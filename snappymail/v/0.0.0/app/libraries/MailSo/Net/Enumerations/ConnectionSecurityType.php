<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Net\Enumerations;

/**
 * @category MailSo
 * @package Net
 * @subpackage Enumerations
 */
enum ConnectionSecurityType: int
{
	case NONE = 0;
	case SSL = 1;
//	case TLS = 1;
	case STARTTLS = 2;
	case AUTO_DETECT = 9;

	public static function UseSSL(int $iPort, ConnectionSecurityType $eSecurityType): bool
	{
		$iPort = (int) $iPort;
		$eResult = $eSecurityType;
		if (self::AUTO_DETECT === $eSecurityType) {
			switch (true)
			{
				case 993 === $iPort:
				case 995 === $iPort:
				case 465 === $iPort:
					$eResult = self::SSL;
					break;
			}
		}

		if (self::SSL === $eResult && !\in_array('ssl', \stream_get_transports())) {
			$eResult = self::NONE;
		}

		return self::SSL === $eResult;
	}
}
