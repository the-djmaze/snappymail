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
enum DkimStatus: string
{
	case NONE = 'none';
	case PASS = 'pass';
	case FAIL = 'fail';
	case POLICY = 'policy';
	case NEUTRAL = 'neutral';
	case TEMP_ERROR = 'temperror';
	case PREM_ERROR = 'permerror';
}
