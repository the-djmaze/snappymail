<?php

// Run with: php test/raw-inline-type.php
// Enable SnappyMail Api and include index file
$_ENV['SNAPPYMAIL_INCLUDE_AS_API'] = true;
require __DIR__ . '/../index.php';

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

class RawInlineType
{
	use \RainLoop\Actions\Raw;
}

$cases = [
	// shown as declared
	'image/png' => 'image/png',
	'IMAGE/JPEG; name="x.jpg"' => 'image/jpeg',
	'application/pdf' => 'application/pdf',
	'video/mp4' => 'video/mp4',
	'audio/ogg; codecs=opus' => 'audio/ogg',
	'text/plain; charset=utf-8' => 'text/plain',
	// shown as source, never rendered
	'text/html' => 'text/plain',
	'Text/HTML; charset=utf-8' => 'text/plain',
	'text/xml' => 'text/plain',
	// downloaded
	'image/svg+xml' => null,
	'application/xhtml+xml' => null,
	'application/xml' => null,
	'application/octet-stream' => null,
	'multipart/related' => null,
	'' => null,
];
foreach ($cases as $in => $expected) {
	$got = RawInlineType::inlineContentType($in);
	check($expected === $got, "'{$in}': expected ".var_export($expected, true).', got '.var_export($got, true));
}

echo "raw-inline-type: ok\n";
