<?php

class AddXOriginatingIpHeaderPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME = 'X-Originating-IP',
		VERSION = '3.0',
		RELEASE  = '2026-11-03',
		DESCRIPTION = 'Adds X-Originating-IP header to outgoing message, containing sender\'s IP address.',
		REQUIRED = '3.0.0';

	public function Init() : void
	{
		$this->addHook('filter.build-message', 'FilterBuildMessage');
	}

	/**
	 * @param \MailSo\Mime\Message $oMessage
	 */
	public function FilterBuildMessage(&$oMessage)
	{
		if ($oMessage instanceof \MailSo\Mime\Message)
		{
			$oMessage->SetCustomHeader(
				\MailSo\Mime\Enumerations\Header::X_ORIGINATING_IP,
				$_SERVER['REMOTE_ADDR']
			);
		}
	}
}
