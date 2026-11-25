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
 * https://datatracker.ietf.org/doc/html/rfc9051#section-4.1
 * An atom consists of one or more non-special characters.
 *
 * Also see https://www.rfc-editor.org/info/rfc5234/#appendix-B.1
 */
class Atom
{
	protected string $value;

	function __construct(string $value = '')
	{
		$this->set($value);
	}

	public function set(string $value): void
	{
		// We use static:: so that children can override
		if (\strlen($value) && !static::validate($value)) {
			throw new \ValueError('$value contains invalid atom-special');
		}
		$this->value = $value;
	}

	function __toString(): string
	{
		return $this->value;
	}

	/**
	 * atom            = 1*ATOM-CHAR
	 * ATOM-CHAR       = <any CHAR except atom-specials>
	 * CHAR            = %x01-7F ; any 7-bit US-ASCII character, excluding NUL
	 * atom-specials   = "(" / ")" / "{" / SP / CTL / list-wildcards / quoted-specials / resp-specials
	 * list-wildcards  = "%" / "*"
	 * quoted-specials = DQUOTE / "\"
	 * resp-specials   = "]"
	 * So actually it is VCHAR except some visible characters
	 * Need more then 7-bits (like UTF8)? Use Quoted or Literal
	 */
	public static function validate(string $value): bool
	{
		return 1 > \preg_match('/[(){\x00-\x20\x7f%*"\\\\\\]]/', $value);
	}
}
