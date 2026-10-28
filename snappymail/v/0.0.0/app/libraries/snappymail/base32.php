<?php

namespace SnappyMail;

class Base32
{
	private string $chars;

	function __construct(string $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567')
	{
		$this->chars = $chars;
	}

	public function random(int $length): string
	{
		$secret = '';
		while (0 < $length--) {
			$secret .= $this->chars[\random_int(0,31)];
		}
		return $secret;
	}

	public function encode(string $data, bool $padding = false, int $chunklen = 0, string $end = "\r\n"): string
	{
		$sl = \is_string($data) ? \strlen($data) : 0;

		$base32 = '';
		$remainder = 0;
		$remainderSize = 0;

		for ($i = 0; $i < $sl; ++$i) {
			$remainder = ($remainder << 8) | \ord($data[$i]);
			$remainderSize += 8;
			while ($remainderSize > 4) {
				$remainderSize -= 5;
				$base32 .= $this->chars[($remainder & (31 << $remainderSize)) >> $remainderSize];
			}
		}

		if ($remainderSize > 0) {
			// remainderSize < 5:
			$base32 .= $this->chars[($remainder << (5 - $remainderSize)) & 31];
		}

		if ($padding) {
			$base32 .= \str_repeat('=', (8 - \ceil(($sl % 5) * 8 / 5)) % 8);
		}

		return 0 < $chunklen
			? \chunk_split($base32, $chunklen, $end)
			: $base32;
	}

	public function decode(string $data, bool $strict = false): ?string
	{
		$data = \strtoupper(\rtrim($data, "=\x20\t\n\r\0\x0B"));
		$dataSize = \strlen($data);
		$buf = 0;
		$bufSize = 0;
		$res = '';
		$charMap = \array_flip(\str_split($this->chars));
		for ($i = 0; $i < $dataSize; ++$i) {
			$c = $data[$i];
			if (isset($charMap[$c])) {
				$buf = ($buf << 5) | $charMap[$c];
				$bufSize += 5;
				if ($bufSize > 7) {
					$bufSize -= 8;
					$res .= \chr(($buf & (0xff << $bufSize)) >> $bufSize);
				}
			} else if ($strict && false === \strpos(" \r\n\t", $c)) {
				\trigger_error('Base32 string contains unexpected char #'.\ord($c)." at offset {$i}");
				return null;
			}
		}
		return $res;
	}

	public function verify(string $data): bool
	{
		return (bool) \preg_match('/^['.\preg_quote($this->chars).' \\r\\n\\t]+=*$/D', $data);
	}

}
