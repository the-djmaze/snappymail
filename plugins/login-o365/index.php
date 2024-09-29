<?php
/**
 * Microsoft requires an Azure account that has an active subscription
 * I'm not going to pay, so feel free to fix this code yourself.
 * https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth
 * https://answers.microsoft.com/en-us/msoffice/forum/all/configuration-for-imap-pop-and-smtp-with-oauth-in/3db47d43-25ac-4e0b-b957-22585e6caf15
 *
 * https://portal.azure.com/#view/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/~/RegisteredApps
 *
 * https://learn.microsoft.com/en-us/entra/identity-platform/reply-url#query-parameter-support-in-redirect-uris
 * Azure:    redirect_uri=https://{DOMAIN}/?LoginO365
 * Personal: redirect_uri=https://{DOMAIN}/LoginO365
 */

use RainLoop\Model\MainAccount;
use RainLoop\Providers\Storage\Enumerations\StorageType;

class LoginO365Plugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Office365/Outlook OAuth2',
		VERSION  = '0.3',
		RELEASE  = '2024-09-29',
		REQUIRED = '2.36.1',
		CATEGORY = 'Login',
		DESCRIPTION = 'Office365/Outlook IMAP, Sieve & SMTP login using RFC 7628 OAuth2';

	// https://login.microsoftonline.com/{{tenant}}/v2.0/.well-known/openid-configuration
	const
		LOGIN_URI = 'https://login.microsoftonline.com/{{tenant}}/oauth2/v2.0/auth',
		TOKEN_URI = 'https://login.microsoftonline.com/{{tenant}}/oauth2/v2.0/token';

	private static ?array $auth = null;

	public function Init() : void
	{
		$this->UseLangs(true);
		$this->addJs('LoginOAuth2.js');
		$this->addHook('imap.before-login', 'clientLogin');
		$this->addHook('smtp.before-login', 'clientLogin');
		$this->addHook('sieve.before-login', 'clientLogin');

		$this->addPartHook('LoginO365', 'ServiceLoginO365');

		// Prevent Disallowed Sec-Fetch Dest: document Mode: navigate Site: cross-site User: true
		$this->addHook('filter.http-paths', 'httpPaths');
	}

	public function httpPaths(array &$aPaths) : void
	{
		// Personal accounts workaround
		if (!empty($_SERVER['PATH_INFO']) && \str_ends_with($_SERVER['PATH_INFO'], 'LoginO365')) {
			$aPaths = ['LoginO365'];
		}

		if (!empty($aPaths[0]) && 'LoginO365' === $aPaths[0]) {
			$oConfig = \RainLoop\Api::Config();
			$oConfig->Set('security', 'secfetch_allow',
				\trim($oConfig->Get('security', 'secfetch_allow', '') . ';site=cross-site', ';')
			);
		}
	}

	public function ServiceLoginO365() : string
	{
		$oActions = \RainLoop\Api::Actions();
		$oHttp = $oActions->Http();
		$oHttp->ServerNoCache();

		try
		{
			if (isset($_GET['error'])) {
				throw new \RuntimeException("{$_GET['error']}: {$_GET['error_description']}");
			}
			if (!isset($_GET['code']) || empty($_GET['state']) || 'o365' !== $_GET['state']) {
				$oActions->Location(\RainLoop\Utils::WebPath());
				exit;
			}
			$oO365 = $this->o365Connector();
			if (!$oO365) {
				$oActions->Location(\RainLoop\Utils::WebPath());
				exit;
			}

			$iExpires = \time();
			$aResponse = $oO365->getAccessToken(
				\str_replace('{{tenant}}', $this->Config()->Get('plugin', 'tenant', 'common'), static::TOKEN_URI),
				'authorization_code',
				array(
					'code' => $_GET['code'],
					'redirect_uri' => $oHttp->GetFullUrl().'?LoginO365'
				)
			);
			if (200 != $aResponse['code']) {
				if (isset($aResponse['result']['error'])) {
					throw new \RuntimeException(
						$aResponse['code']
						. ': '
						. $aResponse['result']['error']
						. ' / '
						. $aResponse['result']['error_description']
					);
				}
				throw new \RuntimeException("HTTP: {$aResponse['code']}");
			}
			$aResponse = $aResponse['result'];
			if (empty($aResponse['access_token'])) {
				throw new \RuntimeException('access_token missing');
			}
			if (empty($aResponse['refresh_token'])) {
				throw new \RuntimeException('refresh_token missing');
			}

			$sAccessToken = $aResponse['access_token'];
			$iExpires += $aResponse['expires_in'];

			$oO365->setAccessToken($sAccessToken);
			$aUserInfo = $oO365->fetch('https://graph.microsoft.com/oidc/userinfo');
			if (200 != $aUserInfo['code']) {
				throw new \RuntimeException("HTTP: {$aResponse['code']}");
			}
			$aUserInfo = $aUserInfo['result'];
			if (empty($aUserInfo['id'])) {
				throw new \RuntimeException('unknown id');
			}
			if (empty($aUserInfo['email'])) {
				throw new \RuntimeException('unknown email address');
			}

			static::$auth = [
				'access_token' => $sAccessToken,
				'refresh_token' => $aResponse['refresh_token'],
				'expires_in' => $aResponse['expires_in'],
				'expires' => $iExpires
			];

			$oPassword = new \SnappyMail\SensitiveString($aUserInfo['id']);
			$oAccount = $oActions->LoginProcess($aUserInfo['email'], $oPassword);
//			$oAccount = MainAccount::NewInstanceFromCredentials($oActions, $aUserInfo['email'], $aUserInfo['email'], $oPassword, true);
			if ($oAccount) {
//				$oActions->SetMainAuthAccount($oAccount);
//				$oActions->SetAuthToken($oAccount);
				$oActions->StorageProvider()->Put($oAccount, StorageType::SESSION, \RainLoop\Utils::GetSessionToken(),
					\SnappyMail\Crypt::EncryptToJSON(static::$auth, $oAccount->CryptKey())
				);
			}
		}
		catch (\Exception $oException)
		{
			$oActions->Logger()->WriteException($oException, \LOG_ERR);
		}
		$oActions->Location(\RainLoop\Utils::WebPath());
		exit;
	}

	public function configMapping() : array
	{
		return [
			\RainLoop\Plugins\Property::NewInstance('personal')
				->SetLabel('Use with personal accounts')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true)
				->SetAllowedInJs()
				->SetDescription('Sign in users with personal Microsoft accounts such as Outlook.com (Hotmail)'),
			\RainLoop\Plugins\Property::NewInstance('client_id')
				->SetLabel('Client ID')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING)
				->SetAllowedInJs()
				->SetDescription('https://github.com/the-djmaze/snappymail/wiki/FAQ#o365'),
			\RainLoop\Plugins\Property::NewInstance('client_secret')
				->SetLabel('Client Secret')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING)
				->SetEncrypted(),
			\RainLoop\Plugins\Property::NewInstance('tenant_id')
				->SetLabel('Tenant ID')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::STRING),
			\RainLoop\Plugins\Property::NewInstance('tenant')->SetLabel('Tenant')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::SELECTION)
				->SetDefaultValue(['common','consumers','organizations'])
				->SetAllowedInJs()
		];
	}

	public function clientLogin(\RainLoop\Model\Account $oAccount, \MailSo\Net\NetClient $oClient, \MailSo\Net\ConnectSettings $oSettings) : void
	{
		if ($oAccount instanceof MainAccount && \str_ends_with($oAccount->Email(), '@hotmail.com')) {
			$oActions = \RainLoop\Api::Actions();
			try {
				$aData = static::$auth ?: \SnappyMail\Crypt::DecryptFromJSON(
					$oActions->StorageProvider()->Get($oAccount, StorageType::SESSION, \RainLoop\Utils::GetSessionToken()),
					$oAccount->CryptKey()
				);
			} catch (\Throwable $oException) {
//				$oActions->Logger()->WriteException($oException, \LOG_ERR);
				return;
			}
			if (!empty($aData['expires']) && !empty($aData['access_token']) && !empty($aData['refresh_token'])) {
				if (\time() >= $aData['expires']) {
					$iExpires = \time();
					$oO365 = $this->o365Connector();
					if ($oO365) {
						$aRefreshTokenResponse = $oO365->getAccessToken(
							\str_replace('{{tenant}}', $this->Config()->Get('plugin', 'tenant', 'common'), static::TOKEN_URI),
							'refresh_token',
							array('refresh_token' => $aData['refresh_token'])
						);
						if (!empty($aRefreshTokenResponse['result']['access_token'])) {
							$aData['access_token'] = $aRefreshTokenResponse['result']['access_token'];
							$aResponse['expires'] = $iExpires + $aResponse['expires_in'];
							$oActions->StorageProvider()->Put($oAccount, StorageType::SESSION, \RainLoop\Utils::GetSessionToken(),
								\SnappyMail\Crypt::EncryptToJSON($aData, $oAccount->CryptKey())
							);
						}
					}
				}
				$oSettings->passphrase = $aData['access_token'];
				\array_unshift($oSettings->SASLMechanisms, 'OAUTHBEARER', 'XOAUTH2');
			}
		}
	}

	protected function o365Connector() : ?\OAuth2\Client
	{
		$client_id = \trim($this->Config()->Get('plugin', 'client_id', ''));
		$client_secret = \trim($this->Config()->getDecrypted('plugin', 'client_secret', ''));
		if ($client_id && $client_secret) {
			try
			{
				$oO365 = new \OAuth2\Client($client_id, $client_secret);
				$oActions = \RainLoop\Api::Actions();
				$sProxy = $oActions->Config()->Get('labs', 'curl_proxy', '');
				if (\strlen($sProxy)) {
					$oO365->setCurlOption(CURLOPT_PROXY, $sProxy);
					$sProxyAuth = $oActions->Config()->Get('labs', 'curl_proxy_auth', '');
					if (\strlen($sProxyAuth)) {
						$oO365->setCurlOption(CURLOPT_PROXYUSERPWD, $sProxyAuth);
					}
				}
				return $oO365;
			}
			catch (\Exception $oException)
			{
				$oActions->Logger()->WriteException($oException, \LOG_ERR);
			}
		}
		return null;
	}
}
