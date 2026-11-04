<?php

use RainLoop\Enumerations\PluginPropertyType;

class ProxyMappingPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME = 'Proxy mapping',
		VERSION = '3.0',
		RELEASE  = '2026-11-03',
		DESCRIPTION = 'Sets some $_SERVER values from HTTP_* headers';

	public function Init(): void
	{
		$key = $this->Config()->Get('plugin', 'https', '');
		if (isset($_SERVER[$key]) && ('on' === $_SERVER[$key] || 'https' === $_SERVER[$key])) {
			$_SERVER['HTTPS'] = 'on';
		} else {
			unset($_SERVER['HTTPS']);
		}

		$key = $this->Config()->Get('plugin', 'remote_addr', '');
		if ($key && !empty($_SERVER[$key]) && \filter_var($_SERVER[$key], \FILTER_VALIDATE_IP)
//			&& \SnappyMail\Host::IsPublicIP($_SERVER[$key])
		) {
			$_SERVER['REMOTE_ADDR'] = $_SERVER[$key];
		}
	}

	protected function configMapping(): array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('remote_addr')
				->SetLabel('REMOTE_ADDR')
				->SetType(PluginPropertyType::STRING)
				->SetDescription("Like: HTTP_CLIENT_IP, HTTP_X_FORWARDED_FOR.\nSo \$_SERVER[REMOTE_ADDR] = \$_SERVER[HTTP_CLIENT_IP]")
				->SetDefaultValue(''),
			\RainLoop\Plugins\Property::NewInstance('https')
				->SetLabel('$_SERVER[HTTPS]')
				->SetType(PluginPropertyType::SELECTION)
				->SetDescription("Set \$_SERVER[HTTPS] = 'on', only when the selected option is found and matches")
				->SetDefaultValue(array('', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL'))

		);
	}
}
