<?php
// Simple contact form handler for klelab.com
// Sends the submitted message straight to admin@klelab.com using the
// hosting provider's PHP mail() function — no third-party service.

// Don't leak stack traces / file paths to visitors if something errors.
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json');

$TO_ADDRESS      = 'admin@klelab.com';
$SITE_NAME       = 'KLE Lab website';
$MIN_SECONDS     = 2;      // reject submissions faster than this (bots)
$MAX_MESSAGE_LEN = 5000;   // reject implausibly long messages
$MAX_LINKS       = 2;      // reject messages stuffed with links

function respond($success, $message) {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Reads one SMTP response, following multi-line replies (e.g. "250-...").
// Returns [code, full response text].
function smtp_read_response($socket) {
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return [(int) substr($data, 0, 3), $data];
}

// Sends one SMTP command (or null to just read, e.g. the initial greeting)
// and checks the response code matches what's expected.
function smtp_command($socket, $command, $expected_code, &$error) {
    if ($command !== null) {
        fwrite($socket, $command . "\r\n");
    }
    list($code, $response) = smtp_read_response($socket);
    if ($code !== $expected_code) {
        $error = "expected $expected_code, got: " . trim($response);
        return false;
    }
    return true;
}

// Minimal SMTP client: connects over implicit TLS (port 465), authenticates
// with AUTH LOGIN, and sends a single plain-text email. Written by hand
// (rather than pulling in a library like PHPMailer) since this site has no
// package manager / build step to vendor one in safely.
function send_via_smtp($host, $port, $username, $password, $from_email, $from_name, $to_email, $reply_name, $reply_email, $subject, $body, &$error) {
    $socket = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 15);
    if (!$socket) {
        $error = "connection failed: $errstr ($errno)";
        return false;
    }
    stream_set_timeout($socket, 15);

    $ok = smtp_command($socket, null, 220, $error)
        && smtp_command($socket, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), 250, $error)
        && smtp_command($socket, 'AUTH LOGIN', 334, $error)
        && smtp_command($socket, base64_encode($username), 334, $error)
        && smtp_command($socket, base64_encode($password), 235, $error)
        && smtp_command($socket, "MAIL FROM:<$from_email>", 250, $error)
        && smtp_command($socket, "RCPT TO:<$to_email>", 250, $error)
        && smtp_command($socket, 'DATA', 354, $error);

    if ($ok) {
        $message  = 'Date: ' . date('r') . "\r\n";
        $message .= "From: $from_name <$from_email>\r\n";
        $message .= "Reply-To: $reply_name <$reply_email>\r\n";
        $message .= "To: <$to_email>\r\n";
        $message .= "Subject: $subject\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "\r\n$body";

        // Dot-stuff lines starting with "." per RFC 5321, then terminate the
        // DATA block with a line containing only a single ".".
        $lines = explode("\r\n", str_replace("\n", "\r\n", $message));
        foreach ($lines as &$line) {
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
        }
        unset($line);

        $ok = smtp_command($socket, implode("\r\n", $lines) . "\r\n.", 250, $error);
    }

    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Method not allowed.');
}

// Origin/Referer check: if the browser sent one of these headers, it must
// point back at this same site. Blocks scripts that POST to this endpoint
// directly from elsewhere. (Some privacy-hardened browsers omit both
// headers entirely — when neither is present we fall through to the other
// checks instead of hard-blocking those legitimate visitors.)
$host = $_SERVER['HTTP_HOST'] ?? '';
$origin_header = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if ($origin_header !== '') {
    $origin_host = parse_url($origin_header, PHP_URL_HOST);
    if (!$origin_host || strcasecmp($origin_host, $host) !== 0) {
        respond(false, 'Request could not be verified. Please reload the page and try again.');
    }
}

// Honeypot: real visitors never fill this hidden field in. If it's
// filled, silently report success to the bot without sending mail.
if (!empty($_POST['_honey'])) {
    respond(true, 'Thanks!');
}

// Time-trap: a hidden field is stamped with the page-load time in JS.
// Real humans take at least a couple of seconds to fill the form out;
// bots that submit instantly get silently "succeeded" without an email.
if (!empty($_POST['_ts']) && is_numeric($_POST['_ts'])) {
    $elapsed_seconds = (microtime(true) * 1000 - (float) $_POST['_ts']) / 1000;
    if ($elapsed_seconds >= 0 && $elapsed_seconds < $MIN_SECONDS) {
        respond(true, 'Thanks!');
    }
}

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $email === '' || $message === '') {
    respond(false, 'Please fill in all fields.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.');
}

if (mb_strlen($message) > $MAX_MESSAGE_LEN) {
    respond(false, 'Message is too long. Please shorten it and try again.');
}

$link_count = preg_match_all('/https?:\/\/|www\./i', $message);
if ($link_count > $MAX_LINKS) {
    respond(false, 'Message looks like spam (too many links). Please remove links and try again.');
}

// Strip anything that could be used for header injection (newlines) in
// fields that end up influencing headers.
$safe_name  = str_replace(["\r", "\n"], '', $name);
$safe_email = str_replace(["\r", "\n"], '', $email);

$subject = "New message from $SITE_NAME";

$body  = "You received a new message from the klelab.com contact form:\n\n";
$body .= "Name: $safe_name\n";
$body .= "Email: $safe_email\n\n";
$body .= "Message:\n$message\n";

// Send via authenticated SMTP (see smtp-config.php) rather than PHP's
// mail(), so the message goes out through Hostinger's real mail servers
// with proper auth — this is what keeps it out of the spam folder, since
// it's naturally SPF/DKIM-aligned instead of relying on the shared
// server's local sendmail relay.
$smtp = require __DIR__ . '/smtp-config.php';

$smtp_error = '';
$sent = send_via_smtp(
    $smtp['host'],
    $smtp['port'],
    $smtp['username'],
    $smtp['password'],
    $smtp['from'],
    $SITE_NAME,
    $TO_ADDRESS,
    $safe_name,
    $safe_email,
    $subject,
    $body,
    $smtp_error
);

if ($sent) {
    respond(true, "Thanks — your message has been sent. We'll be in touch soon.");
} else {
    error_log('Contact form SMTP send failed: ' . $smtp_error);
    http_response_code(500);
    respond(false, 'Something went wrong sending your message. Please try again, or email us directly.');
}
