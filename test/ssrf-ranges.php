<?php

// Run with: php test/ssrf-ranges.php (needs no network: only IP literals)

// Enable SnappyMail Api and include index file
$_ENV['SNAPPYMAIL_INCLUDE_AS_API'] = true;
require __DIR__ . '/../index.php';

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$Request = 'SnappyMail\\Host';
$public = fn(string $host) => $Request::URIHasPublicHost("http://{$host}/x.png");

foreach (['100.64.0.1', '100.100.100.200', '100.127.255.254', '192.0.0.1', '198.18.0.1',
	'198.19.255.254', '224.0.0.1', '239.255.255.250', '[fec0::1]', '[ff02::1]',
	'127.0.0.1', '10.0.0.1', '169.254.169.254', '[::1]', '[fc00::1]', '[::ffff:100.64.0.1]'] as $host) {
	check(!$public($host), "{$host} treated as public");
}
foreach (['100.63.255.255', '100.128.0.1', '198.17.255.255', '198.20.0.1', '8.8.8.8',
	'[2001:4860:4860::8888]'] as $host) {
	check($public($host), "{$host} wrongly blocked");
}

// The server's own addresses. With --network=none only loopback exists, so
// hand the cache an address to stand for this host's public IP.
$prop = new ReflectionProperty($Request, 'aLocalAddresses');
$prop->setValue(null, [inet_pton('203.0.113.7'), inet_pton('2001:db8:1::53')]);
check(!$public('203.0.113.7'), "the server's own IPv4 address treated as public");
check(!$public('[2001:db8:1::53]'), "the server's own IPv6 address treated as public");
check($public('203.0.113.8'), 'a neighbouring address wrongly blocked');

check($public('snappymail.eu'), 'snapymail.eu wrongly blocked');
check(!$public('localhost'), 'localhost should be blocked');

echo "ssrf-ranges: ok\n";
