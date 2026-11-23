<?php

use RainLoop\Enumerations\PluginPropertyType;

class ProxyMappingPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME = 'Proxy mapping',
		VERSION = '1.1',
		RELEASE  = '2026-11-24',
		DESCRIPTION = 'Sets some $_SERVER values from HTTP_* headers',
		REQUIRED = '2.24.3';

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
		$options = [
			0 => ['id' => '', 'name' => '(no mapping)'],
			// a de facto standard
			'HTTP_X_FORWARDED_PROTO' => [],
			// Non-standard
			'HTTP_X_FORWARDED_SSL' => [],
			'HTTP_X_FORWARDED_PROTOCOL' => [],
			'HTTP_X_URL_SCHEME' => [],
			// Non-standard used by Microsoft applications and load-balancers:
			'HTTP_FRONT_END_HTTPS' => [],
		];
		foreach ($options as $key => $option) {
			if ($key) {
				$options[$key] = ['id' => $key, 'name' => '$_SERVER['.$key.']' . (isset($_SERVER[$key])?' (detected)':'')];
			}
		}

		return array(
			\RainLoop\Plugins\Property::NewInstance('remote_addr')
				->SetLabel('REMOTE_ADDR')
				->SetType(PluginPropertyType::SELECT)
				->SetOptions([
					['id' => '', 'name' => '(no mapping)'],
					['id' => 'HTTP_CLIENT_IP',
					 'name' => '$_SERVER[HTTP_CLIENT_IP]' . (isset($_SERVER['HTTP_CLIENT_IP'])?' (detected)':'')],
					['id' => 'HTTP_X_FORWARDED_FOR',
					 'name' => '$_SERVER[HTTP_X_FORWARDED_FOR]' . (isset($_SERVER['HTTP_X_FORWARDED_FOR'])?' (detected)':'')]
				])
				->SetDefaultValue('')
				->SetDescription("Set \$_SERVER[REMOTE_ADDR], only when the selected option is found and matches"),
			\RainLoop\Plugins\Property::NewInstance('https')
				->SetLabel('HTTPS')
				->SetType(PluginPropertyType::SELECT)
				->SetOptions(\array_values($options))
				->SetDefaultValue('')
				->SetDescription("Set \$_SERVER[HTTPS] = 'on', only when the selected option is found and matches")
		);
	}
}
