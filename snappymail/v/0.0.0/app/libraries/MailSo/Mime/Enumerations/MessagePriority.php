<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Mime\Enumerations;

/**
 * @category MailSo
 * @package Mime
 * @subpackage Enumerations
 */
enum MessagePriority: int
{
	case Lowest = 5;
	case Normal = 3;
	case Highest = 1;

	public function toMIME() : string
	{
		return $this->value . ' (' . $this->name . ')';
	}
}
