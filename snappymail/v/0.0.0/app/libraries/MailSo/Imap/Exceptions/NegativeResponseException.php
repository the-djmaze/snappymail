<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Imap\Exceptions;

/**
 * @category MailSo
 * @package Imap
 * @subpackage Exceptions
 */
class NegativeResponseException extends ResponseException
{

	public function getAlertFromStatus(): string
	{
		$oResponse = $this->GetLastResponse();
		if ($oResponse && $oResponse->ResponseStatus && !empty($oResponse->HumanReadable) &&
			'ALERT' === ($oResponse->OptionalResponse[0] ?? ''))
		{
			return $oResponse->HumanReadable;
		}

		return '';
	}

	public function isTemporaryLoginFailure(): bool
	{
		$oResponse = $this->GetLastResponse();
		$sCode = $oResponse && \is_array($oResponse->OptionalResponse) && isset($oResponse->OptionalResponse[0])
			? \strtoupper((string) $oResponse->OptionalResponse[0])
			: '';

		return \in_array($sCode, array('UNAVAILABLE', 'INUSE', 'SERVERBUG'), true);
	}
}
