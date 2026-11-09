<?php

class LoginProxyauthExamplePlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Login PROXYAUTH',
		VERSION  = '1.1',
		RELEASE  = '2026-11-09',
		REQUIRED = '2.36.1',
		CATEGORY = 'Login',
		DESCRIPTION = 'IMAP login using PROXYAUTH';

	public function Init() : void
	{
		$this->addHook('imap.before-login', 'beforeLogin');
		$this->addHook('imap.after-login', 'afterLogin');
	}

	public function beforeLogin(\RainLoop\Model\Account $oAccount, \MailSo\Net\NetClient $oImapClient, \MailSo\Net\ConnectSettings $oSettings) : void
	{
		if ('example.com' === $oAccount->Domain()->Name()) {
			$oSettings->username = 'AdminUsername';
			$oSettings->passphrase = 'AdminPassword';
		}
	}

	public function afterLogin(\RainLoop\Model\Account $oAccount, \MailSo\Imap\ImapClient $oImapClient, bool $bSuccess)
	{
		if ($bSuccess && 'example.com' === $oAccount->Domain()->Name()) {
			$oImapClient->SendRequestGetResponse('PROXYAUTH', array($oImapClient->EscapeString($oAccount->ImapUser())));
		}
	}
}
