<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2026 SnappyMail
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Imap;

/**
 * @category MailSo
 * @package Imap
 *
 * Flags are IMAP atoms: optionally prefixed with \
 * https://www.rfc-editor.org/info/rfc9051/#section-2.3.2
 */
class Flag extends Atom
{
	/**
	 * flag            = "\Answered" / "\Flagged" / "\Deleted" /
     *                   "\Seen" / "\Draft" / flag-keyword / flag-extension
     * flag-extension  = "\" atom
     * flag-keyword    = "$MDNSent" / "$Forwarded" / "$Junk" /
     *                   "$NotJunk" / "$Phishing" / atom
	 */
	public static function validate(string $value): bool
	{
		return parent::validate('\\' === $value[0] ? \substr($value, 1) : $value);
	}

	/**
	 * NOTE: RFC 3501 and 9051 do not define unicode for flags
	 * So these will not work in all mail applications
	 */

	public function toUTF8(): string
	{
		return static::decode($this->value);
	}

	public static function decode(string $value): string
	{
		// New Thunderbird encoding
		if (\preg_match('/=[0-9a-f][0-9a-f]=[0-9a-f][0-9a-f]/', $value)) {
			return \preg_replace_callback(
				'/=([0-9a-f][0-9a-f])/',
				fn($m) => \hex2bin($m[1]),
				$value
			);
		}
		// Just try UTF-7
		return \MailSo\Base\Utils::Utf7ModifiedToUtf8($value);
	}

	public static function fromUTF8(string $value): self
	{
		// UTF-8 is not defined for flags, so we encode it
		if (\mb_strlen($value) !== \strlen($value) && !\preg_match('/\\pC/u', $value)) {
			// Thunderbird now uses "hex" like "=f0"
			$value = \preg_replace_callback(
				'/[\x80-\xbf]/',
				fn($m) => '=' . \bin2hex($m[0]),
				$value
			);
			// Before that, it was modified-utf-7
			//$value = \MailSo\Base\Utils::Utf8ToUtf7Modified(\mb_strtolower($value));
		}
		return new self($value);
	}
}
