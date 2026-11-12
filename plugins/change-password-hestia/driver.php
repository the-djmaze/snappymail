<?php

use MailSo\Log\Logger;
use RainLoop\Config\Plugin;
use SnappyMail\SensitiveString;

class ChangePasswordHestiaDriver
{
	const
		NAME        = 'Hestia',
		DESCRIPTION = 'Change passwords in Hestia.';

	private Plugin $oConfig;

	protected Logger $oLogger;

	function __construct(Plugin $oConfig, Logger $oLogger)
	{
		$this->oConfig = $oConfig;
		$this->oLogger = $oLogger;
	}

	public static function isSupported() : bool
	{
		return true;
	}

	public static function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('hestia_host')->SetLabel('Hestia Host')
				->SetDefaultValue('')
				->SetDescription('Ex: localhost or domain.com'),
			\RainLoop\Plugins\Property::NewInstance('hestia_port')->SetLabel('Hestia Port')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::INT)
				->SetDefaultValue(8083)
		);
	}

	public function ChangePassword(\RainLoop\Model\Account $oAccount, SensitiveString $oPrevPassword, SensitiveString $oNewPassword) : bool
	{
		if (!\RainLoop\Plugins\Helper::ValidateWildcardValues($oAccount->Email(), $this->oConfig->Get('plugin', 'hestia_allowed_emails', ''))) {
			return false;
		}

		$this->oLogger->Write("Hestia: Try to change password for {$oAccount->Email()}");

		$sHost = $this->oConfig->Get('plugin', 'hestia_host');
		$sPort = $this->oConfig->Get('plugin', 'hestia_port');

		$HTTP = \SnappyMail\HTTP\Request::factory();
		$postvars = array(
			'email'    => $oAccount->Email(),
			'password' => $oPrevPassword->getValue(),
			'new'      => $oNewPassword->getValue(),
		);
		$response = $HTTP->doRequest('POST', 'https://'.$sHost.':'.$sPort.'/reset/mail/', \http_build_query($postvars));
		if (!$response) {
			$this->oLogger->Write("Hestia[Error]: Response failed");
			return false;
		}
		if ('==ok==' != $response->body) {
			$this->oLogger->Write("Hestia[Error]: Response: {$response->status} {$response->body}");
			return false;
		}
		return true;
	}
}
