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
enum StoreAction: string
{
//	case SET_FLAGS = 'FLAGS';
//	case SET_FLAGS_SILENT = 'FLAGS.SILENT';
	case ADD_FLAGS = '+FLAGS';
	case ADD_FLAGS_SILENT = '+FLAGS.SILENT';
	case REMOVE_FLAGS = '-FLAGS';
	case REMOVE_FLAGS_SILENT = '-FLAGS.SILENT';
}
