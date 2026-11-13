<?php

class LoginHttpPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Login HTTP',
		AUTHOR   = 'Alterative Editions',
		URL      = 'https://alterative.fr/',
		VERSION  = '1.1',
		RELEASE  = '2026-11-12',
		REQUIRED = '2.21.0',
		CATEGORY = 'Login',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Automatic HTTP Login : uses PHP_AUTH_USER & PHP_AUTH_PW for IMAP connection';

	public function Init() : void
	{
		$this->addPartHook('ExternalHTTP', 'ServiceExternalHTTP');
	}

	public function ServiceExternalHTTP(): bool
	{
		$oActions = \RainLoop\Api::Actions();
		$oActions->Http()->ServerNoCache();
		try {
			$oAccount = $oActions->LoginProcess(
				$_SERVER['PHP_AUTH_USER'] ?? '',
				new \SnappyMail\SensitiveString($_SERVER['PHP_AUTH_PW'] ?? '')
			);
			if ($oAccount) {
				\MailSo\Base\Http::Location('./');
				return true;
			}
		} catch (\Throwable $e) {
			// Log l'erreur si besoin
		}
		\MailSo\Base\Http::Location('./?ExternalHTTP');
		return true;
	}
}
