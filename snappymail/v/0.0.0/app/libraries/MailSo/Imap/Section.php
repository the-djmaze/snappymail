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
 * @package Mime
 *
 * BINARY[<section-binary>]<<partial>>
 * BODY[<section>]<<partial>>
 * BODY.PEEK[<section>]<<partial>>
 * section         = "[" [section-spec] "]"
 * section-binary  = "[" [section-part] "]"
 * section-spec    = section-msgtext / (section-part ["." section-text])
 * section-part    = nz-number *("." nz-number)
 * section-text    = section-msgtext / "MIME"
 * section-msgtext = "HEADER" / "HEADER.FIELDS" [".NOT"] SP header-list / "TEXT"
 */
class Section
{
	private string $value = '';

	public function isRoot(): bool
	{
		return !\strlen($this->value) || '.' === \substr($this->value, -1);
	}

	public function setValue(string $value): void
	{
		if (\strlen($value) && !static::validate($value)) {
			throw new \ValueError('Invalid MIME section value');
		}
		$this->value = $value;
	}

	function __toString(): string
	{
		return $this->value;
	}

	public static function validate(string $value): bool
	{
		$astring         = '[^(){\x00-\x20\x7f%*]+';
		$header_list     = "\({$astring}(?:\s+{$astring}\)*\)";
		$section_msgtext = "HEADER|HEADER\\.FIELDS(?:\\.NOT)?\s+{$header_list}|TEXT";
		return 1 === \preg_match("/^(?:{$section_msgtext}|[1-9][0-9]*(?:\\.[1-9][0-9]*)*(?:\\.(?:{$section_msgtext}|MIME))?)$/D", $value);
	}

	/**
	 * A MIME part number as NewInstance() makes them: 1, 2, 1.2.3. Callers put
	 * part numbers they got from the client into a FETCH section (BODY[...]),
	 * so anything else must be refused before it reaches the command.
	 */
	public static function validatePart(string $value): bool
	{
		return 1 === \preg_match('/^[1-9][0-9]*(?:\\.[1-9][0-9]*)*$/D', $value);
	}
}
