<?php
/**
 * ZaphodB wrote most of the code for Tachyon, as solution for a SSRF exploit.
 * The code is suitable for SnappyMail, so it is rewritten and changed to work
 * standalone checks.
 * This one also checks if --disable-ipv6 is used with the PHP compiler
 * https://github.com/ZaphodB/Tachyon/commit/442c28a24804550e24e2707f2ed162ea514dc37d
 */

namespace SnappyMail;

// Sockets extension not loaded?
if (!\defined('AF_INET6')) {
	\define('AF_INET6', true);
}

abstract class Host
{
	public static function isLocal(string $host): bool
	{
		return \in_array(\strtolower(\trim($host)), array(
			'localhost', '127.0.0.1', '::1'
		));
	}

	// RFC 6890 Private-Use and Unique-Local addresses
	// 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
	// fc00::/7 (starting with FC or FD)
	public static function isPrivateIP(string $ip): bool
	{
		return \filter_var($ip, \FILTER_VALIDATE_IP)
		   && !\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE);
	}

	// These are the ranges that are marked as Reserved-By-Protocol in » RFC 6890.
	// 0.0.0.0/8
	// 127.0.0.0/8        Loopback
	// 169.254.0.0/16     Link Local
	// 240.0.0.0/4        Reserved
	// ::1/128            Loopback Address
	// ::/128             Unspecified Address
	// ::ffff:0:0/96      IPv4-mapped Address
	// fe80::/10          Linked-Scoped Unicast
	public static function isReservedIP(string $ip): bool
	{
		return \filter_var($ip, \FILTER_VALIDATE_IP)
		   && !\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_RES_RANGE);
	}

	/**
	 * Available as of PHP 8.2.0.
	 * Only allow global addresses. These can be found in » RFC 6890 where the Global attribute is True.
	 * https://datatracker.ietf.org/doc/html/rfc6890
	 * 192.88.99.0/24 = 6to4 Relay Anycast
	 * 64:ff9b::/96 = IPv4-IPv6 Translat.
	 */
	/* not working correctly in PHP 8.5.12
	public static function isGlobalIP(string $ip): bool
	{
		return \filter_var($ip, \FILTER_VALIDATE_IP)
		   && \filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE);
	}
	*/

	/**
	 * FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
	 * let through some ranges, although they are not the public internet:
	 * RFC 6598 shared space (CGNAT, Tailscale, some cloud metadata), IETF protocol
	 * assignments, benchmarking, multicast, and deprecated IPv6 site-local
	 * and multicast.
	 */
	private const NON_PUBLIC_RANGES = [
		// IPv4
		'100.64.0.0/10',      // Shared Address Space
//		'192.0.0.0/29',       // DS-Lite
		'192.0.0.0/24',       // IETF Protocol Assignments
//		'192.0.2.0/24',       // Documentation (TEST-NET-1)
		'192.88.99.0/24',     // 6to4 Relay Anycast
		'198.18.0.0/15',      // Benchmarking
//		'198.51.100.0/24',    // Documentation (TEST-NET-2)
//		'203.0.113.0/24',     // Documentation (TEST-NET-3)
		'224.0.0.0/4',        // multicast
//		'255.255.255.255/32', // Limited Broadcast
		// IPv6
		'64:ff9b::/9',   // IPv4-IPv6 Translat.
//		'100::/64',      // Discard-Only Address Block
//		'2001::/23',     // IETF Protocol Assignments
//		'2001::/32',     // TEREDO
//		'2001:2::/48',   // Benchmarking
//		'2001:db8::/32', // Documentation
//		'2001:10::/28',  // ORCHID
//		'2002::/16',     // 6to4
		'fec0::/10',     // before 2006 was reserved for site-local addresses
		'ff00::/8',      // multicast
	];

	/**
	 * A public address that is not one of this server's own. Without the
	 * net_get_interfaces part, a request to the server's public IP reaches
	 * services that listen on every address but are firewalled from outside,
	 * since the connection never leaves the host.
	 */
	public static function IsPublicIP(string $ip): bool
	{
		if (!\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			return false;
		}

		/* not working correctly in PHP 8.5.12
		if (\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE)) {
			return false;
		}
		*/

		if (\str_contains($ip, ':') && !\AF_INET6) {
			// PHP compiled with option --disable-ipv6
			return false;
		}

		$bin = \inet_pton($ip);
		foreach (self::NON_PUBLIC_RANGES as $range) {
			[$net, $bits] = \explode('/', $range);
			if (\str_contains($net, ':') && !\AF_INET6) {
				// PHP compiled with option --disable-ipv6
				break;
			}
			$net = \inet_pton($net);
			if (\strlen($net) === \strlen($bin) && self::PrefixMatches($bin, $net, (int) $bits)) {
				return false;
			}
		}

		if (null === self::$aLocalAddresses) {
			self::$aLocalAddresses = [];
			foreach ((\function_exists('net_get_interfaces') ? \net_get_interfaces() : false) ?: [] as $aInterface) {
				foreach ($aInterface['unicast'] ?? [] as $aAddress) {
					if (!empty($aAddress['address']) && false !== ($sLocal = @\inet_pton($aAddress['address']))) {
						self::$aLocalAddresses[] = $sLocal;
					}
				}
			}
		}
		return !\in_array($bin, self::$aLocalAddresses, true);
	}

	public static function getIPs($uri): ?array
	{
		$host = \parse_url($uri, \PHP_URL_HOST);
		if (!\is_string($host) || '' === $host) {
			return null;
		}
		// Bracketed IPv6 literals (http://[::1]/): parse_url keeps the
		// brackets, which filter_var rejects, so strip them first.
		if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
			$host = \substr($host, 1, -1);
		}
		// Validate direct IP or resolve hostname
		return \filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : \SnappyMail\DNS::IP($host);
	}

	/**
	 * Whether the URI host resolves exclusively to public IPs.
	 * Blocks literal private/reserved/loopback/link-local IPs (including
	 * non-standard numeric forms like http://2130706433/, http://017700000001/,
	 * http://0x7f000001/, http://127.1/ and bracketed IPv6 like
	 * http://[::1]/) as well as
	 * hostnames that resolve to one. IPv6 literals with an embedded
	 * IPv4 address (::ffff:127.0.0.1 and friends) are unwrapped first.
	 * Fails closed when the host cannot be resolved. Note: this is a
	 * pre-request
	 * check; a hostile DNS that rebinds between check and connect (TOCTOU)
	 * is not covered.
	 */
	public static function URIHasPublicHost(string $uri): bool
	{
		$ips = static::getIPs($uri);
		if (!$ips) {
			return false;
		}

		// IPv6 literals with an embedded IPv4 address (::ffff:127.0.0.1,
		// ::ffff:a00:5, 64:ff9b::7f00:1): FILTER_FLAG_NO_RES_RANGE
		// does not treat these ranges as reserved, but they reach IPv4
		// addresses in practice, so unwrap the low 32 bits and validate
		// those as IPv4.
		$ips = \array_map(fn($ip) => self::UnwrapEmbeddedIPv4($ip) ?: $ip, $ips);

		// Numeric IPv4 literals in non-standard forms (http://2130706433/,
		// http://017700000001/, http://0x7f000001/, http://127.1/,
		// http://1.2.3.04/): the HTTP client parses these as IPs directly
		// (verified: curl connects to 127.0.0.1 for the hex forms with no
		// DNS involved) while dns_get_record() below would query them as
		// hostnames, so normalize to dotted decimal before validation.
		$ips = \array_map(fn($ip) => self::NormalizeNumericIPv4($ip) ?: $ip, $ips);

		// Check them all
		if (\array_all($ips, fn($ip) => self::IsPublicIP($ip))) {
			return true;
		}

		return false;
	}

	private static ?array $aLocalAddresses = null;

	private static function PrefixMatches(string $bin, string $net, int $bits): bool
	{
		$bytes = \intdiv($bits, 8);
		if (\substr($bin, 0, $bytes) !== \substr($net, 0, $bytes)) {
			return false;
		}
		$rest = $bits % 8;
		if (!$rest) {
			return true;
		}
		$mask = (0xff << (8 - $rest)) & 0xff;
		return (\ord($bin[$bytes]) & $mask) === (\ord($net[$bytes]) & $mask);
	}

	/**
	 * Normalize a numeric IPv4 literal in non-standard form to dotted
	 * decimal, following inet_aton semantics: 1-4 dot-separated parts,
	 * each decimal, octal (leading zero) or hex (0x prefix); the last
	 * part takes all remaining bits (32/24/16/8). Returns null when the
	 * host is not such a literal. A part like "08" is not parsed as
	 * numeric by the resolver, so it is left for the hostname path below.
	 */
	private static function NormalizeNumericIPv4(string $host): ?string
	{
		$aParts = \explode('.', $host);
		$iCount = \count($aParts);
		if ($iCount < 1 || $iCount > 4) {
			return null;
		}
		$aNums = array();
		foreach ($aParts as $sPart) {
			if ('' === $sPart
				|| !\preg_match('/^(0[xX][0-9a-fA-F]+|[0-9]+)$/', $sPart)) {
				return null;
			}
			if (\preg_match('/^0[xX]/', $sPart)) {
				if (10 < \strlen($sPart)) {
					return null; // longer than 0x + 8 digits: not a 32-bit value
				}
				$aNums[] = \hexdec($sPart);
				continue;
			}
			if (1 < \strlen($sPart) && '0' === $sPart[0]
				&& !\preg_match('/^0[0-7]+$/', $sPart)) {
				return null;
			}
			if (12 < \strlen($sPart)) {
				return null; // longer than 037777777777: not a 32-bit value
			}
			$aNums[] = 1 < \strlen($sPart) && '0' === $sPart[0]
				? \octdec($sPart) : (int) $sPart;
		}
		$iMax = array(4294967295, 16777215, 65535, 255);
		for ($i = 0; $i < $iCount - 1; $i++) {
			if ($aNums[$i] > 255) {
				return null;
			}
		}
		if ($aNums[$iCount - 1] > $iMax[$iCount - 1]) {
			return null;
		}
		switch ($iCount) {
			case 1:
				$long = $aNums[0];
				break;
			case 2:
				$long = ($aNums[0] << 24) | $aNums[1];
				break;
			case 3:
				$long = ($aNums[0] << 24) | ($aNums[1] << 16) | $aNums[2];
				break;
			default:
				$long = ($aNums[0] << 24) | ($aNums[1] << 16) | ($aNums[2] << 8) | $aNums[3];
				break;
		}
		return \long2ip($long);
	}

	/**
	 * Unwrap an IPv6 literal with an embedded IPv4 address to dotted
	 * decimal, or null when the host is not such a literal. Covers the
	 * IPv4-mapped range ::ffff:0:0/96 (dotted or hex tail), the NAT64
	 * well-known prefix 64:ff9b::/96, and the deprecated
	 * IPv4-compatible form ::a.b.c.d (textual dotted-quad tail only,
	 * so ::1 stays loopback and is never mistaken for ::0.0.0.1).
	 * Needed because FILTER_FLAG_NO_RES_RANGE does not treat these
	 * ranges as reserved even though they reach IPv4 addresses in
	 * practice.
	 */
	private static function UnwrapEmbeddedIPv4(string $ip): ?string
	{
		$sBin = \inet_pton($ip);
		if (false === $sBin || 16 !== \strlen($sBin)) {
			return null; // not an IPv6 literal
		}
		$sPrefix = \substr($sBin, 0, 12);
		if ("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === $sPrefix // ::ffff:0:0/96
			|| "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00" === $sPrefix) { // 64:ff9b::/96
			return (string) \inet_ntop(\substr($sBin, 12, 4));
		}
		// 6to4, 2002:V4ADDR::/48 (RFC 3056): the address sits in bytes 2 to 5, so
		// 2002:7f00:1:: is 127.0.0.1. Deprecated by RFC 7526 and unreachable
		// without a configured tunnel, but a host that has one would route it.
		if ("\x20\x02" === \substr($sBin, 0, 2)) {
			return (string) \inet_ntop(\substr($sBin, 2, 4));
		}
		$sLower = \strtolower($ip);
		if (\str_starts_with($sLower, '::')
			&& false !== \filter_var(\substr($sLower, 2), \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
			return \substr($sLower, 2); // deprecated ::a.b.c.d form
		}
		return null;
	}
}
