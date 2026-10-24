<?php

// Run with: php test/header-injection.php
define('APP_VERSION', 'test');
spl_autoload_register(static function (string $class): void {
	$file = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/'.str_replace('\\', '/', $class).'.php';
	if (is_file($file)) {
		require_once $file;
	}
});

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

// An encoded-word that decodes to a line break must not survive decoding.
$payload = "<x@example.com>\r\nBcc: attacker@example.net";
$encoded = '=?utf-8?b?'.base64_encode($payload).'?=';
$decoded = \MailSo\Base\Utils::DecodeHeaderValue($encoded);
check(!preg_match('/[\r\n]/', $decoded), 'B-encoded CRLF survived DecodeHeaderValue');
check(str_contains($decoded, 'Bcc: attacker@example.net'), 'decoded text was lost, not just its line break');

$decoded = \MailSo\Base\Utils::DecodeHeaderValue('=?utf-8?q?a=0D=0AX-Injected:_1?=');
check(!preg_match('/[\r\n]/', $decoded), 'Q-encoded CRLF survived DecodeHeaderValue');

// The same through header parsing, as for an incoming Message-ID.
$header = \MailSo\Mime\Header::NewInstanceFromEncodedString("Message-ID: {$encoded}");
check(!preg_match('/[\r\n]/', $header->Value()), 'parsed Message-ID kept a line break');

// Outgoing: a value handed straight to the message (In-Reply-To and Subject come
// from client parameters) must serialise as one header, whatever it contains.
$message = new \MailSo\Mime\Message();
$message->SetInReplyTo("<x@example.com>\r\nBcc: attacker@example.net");
$message->SetSubject("Re: hello\nX-Injected: 1\r\n\r\nbody text");
$raw = stream_get_contents($message->ToStream());
[$headers] = explode("\r\n\r\n", $raw, 2);
foreach (explode("\r\n", $headers) as $line) {
	check(!preg_match('/^(Bcc|X-Injected):/i', $line), "injected header line: {$line}");
}
check(!str_contains($headers, "\n\n"), 'blank line injected into the header block');
check(str_contains($raw, 'In-Reply-To: <x@example.com> Bcc: attacker@example.net'), 'In-Reply-To was not kept as one line');

// Folding must still work: a long value is wrapped with CRLF + space only.
$header = new \MailSo\Mime\Header('Subject', str_repeat('word ', 40));
foreach (array_slice(explode("\r\n", (string) $header), 1) as $continuation) {
	check(str_starts_with($continuation, ' '), 'folded line does not start with whitespace');
}

echo "header-injection: ok\n";
