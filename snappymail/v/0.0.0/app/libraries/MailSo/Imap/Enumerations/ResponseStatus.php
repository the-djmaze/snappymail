<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Imap\Enumerations;

/**
 * @category MailSo
 * @package Imap
 * @subpackage Enumerations
 */
enum ResponseStatus: string
{
	case OK = 'OK';
	case NO = 'NO';
	case BAD = 'BAD';
	case BYE = 'BYE';
	case PREAUTH = 'PREAUTH';
}
