<?php

// Run with: php test/smime-partid.php

// Enable SnappyMail Api and include index file
$_ENV['SNAPPYMAIL_INCLUDE_AS_API'] = true;
require __DIR__ . '/../index.php';

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$actions = (new ReflectionClass(\RainLoop\Actions::class))->newInstanceWithoutConstructor();
$params = new ReflectionProperty(\RainLoop\Actions::class, 'aCurrentActionParams');
$method = new ReflectionMethod(\RainLoop\Actions::class, 'smimePartIdParam');
$read = function (string $value) use ($actions, $params, $method) : ?string {
	$params->setValue($actions, ['partId' => $value]);
	try {
		return $method->invoke($actions, 'partId');
	} catch (\RainLoop\Exceptions\ClientException $e) {
		return null;
	}
};

foreach (['', '1', '2', '1.2', '1.2.10', 'TEXT'] as $ok) {
	check($ok === $read($ok), "valid part id '{$ok}' refused");
}
foreach (["1]\r\nA1 LOGOUT\r\n", '1] BODY[HEADER', "1\r\nA1 LOGOUT", '1 2', '0', '1.0', '01', '1.', '.1', 'text', 'HEADER'] as $bad) {
	check(null === $read($bad), 'part id '.json_encode($bad).' accepted');
}
// Surrounding whitespace is trimmed away, and the trimmed value is what is used.
check('1' === $read("1\n"), 'trailing newline not trimmed');

echo "smime-partid: ok\n";
