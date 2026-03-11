<?php

class NextcloudPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME = 'Nextcloud',
		// Keep upstream metadata if you prefer; this is not functional.
		VERSION = '2.38.2',
		RELEASE  = '2026-02-06',
		CATEGORY = 'Integrations',
		DESCRIPTION = 'Integrate with Nextcloud v20+',
		REQUIRED = '2.38.0';

	public function Init() : void
	{
		if (static::IsIntegrated()) {
			\SnappyMail\Log::debug('Nextcloud', 'integrated');
			$this->UseLangs(true);

			$this->addHook('main.fabrica', 'MainFabrica');
			$this->addHook('filter.app-data', 'FilterAppData');
			$this->addHook('filter.language', 'FilterLanguage');

			$this->addCss('style.css');

			$this->addJs('js/webdav.js');

			$this->addJs('js/message.js');
			$this->addHook('json.attachments', 'DoAttachmentsActions');
			$this->addJsonHook('NextcloudSaveMsg', 'NextcloudSaveMsg');

			$this->addJs('js/composer.js');
			$this->addJsonHook('NextcloudAttachFile', 'NextcloudAttachFile');

			$this->addJs('js/messagelist.js');

			$this->addTemplate('templates/PopupsNextcloudFiles.html');
			$this->addTemplate('templates/PopupsNextcloudCalendars.html');

			// $this->addHook('login.credentials.step-2', 'loginCredentials2');
			// $this->addHook('login.credentials', 'loginCredentials');
			$this->addHook('imap.before-login', 'beforeLogin');
			$this->addHook('smtp.before-login', 'beforeLogin');
			$this->addHook('sieve.before-login', 'beforeLogin');
		} else {
			\SnappyMail\Log::debug('Nextcloud', 'NOT integrated');
			$this->addHook('main.content-security-policy', 'ContentSecurityPolicy');
		}
	}

	public function ContentSecurityPolicy(\SnappyMail\HTTP\CSP $CSP)
	{
		if (\method_exists($CSP, 'add')) {
			$CSP->add('frame-ancestors', "'self'");
		}
	}

	public function Supported() : string
	{
		return static::IsIntegrated() ? '' : 'Nextcloud not found to use this plugin';
	}

	public static function IsIntegrated()
	{
		return \class_exists('OC') && isset(\OC::$server);
	}

	public static function IsLoggedIn()
	{
		return static::IsIntegrated() && \OC::$server->getUserSession()->isLoggedIn();
	}

	public function loginCredentials(string &$sEmail, string &$sLogin, ?string &$sPassword = null) : void
	{
		// left intentionally as upstream (commented in upstream)
	}

	public function loginCredentials2(string &$sEmail, ?string &$sPassword = null) : void
	{
		$ocUser = \OC::$server->getUserSession()->getUser();
		$sEmail = $ocUser->getEMailAddress() ?: $ocUser->getPrimaryEMailAddress() ?: $sEmail;
	}

	public function beforeLogin(\RainLoop\Model\Account $oAccount, \MailSo\Net\NetClient $oClient, \MailSo\Net\ConnectSettings $oSettings) : void
	{
		if ($oAccount instanceof \RainLoop\Model\MainAccount
			&& \OCA\SnappyMail\Util\SnappyMailHelper::isOIDCLogin()
			&& \str_starts_with($oSettings->passphrase, 'oidc_login|')
		) {
			$oSettings->passphrase = (string) \OC::$server->getSession()->get('oidc_access_token');
			\array_unshift($oSettings->SASLMechanisms, 'OAUTHBEARER');
		}
	}

	/**
	 * Attach a Nextcloud file into SnappyMail temp storage (for composing).
	 */
	public function NextcloudAttachFile() : array
	{
		$aResult = [
			'success' => false,
			'tempName' => ''
		];

		$sFile = (string) $this->jsonParam('file', '');

		try {
			$oActions = \RainLoop\Api::Actions();
			$oAccount = $oActions->getAccountFromToken();
			if (!$oAccount) {
				$aResult['error'] = 'no-account';
				return $this->jsonResponse(__FUNCTION__, $aResult);
			}

			$user = \OC::$server->getUserSession()->getUser();
			if (!$user) {
				$aResult['error'] = 'no-user';
				return $this->jsonResponse(__FUNCTION__, $aResult);
			}

			/** @var \OCP\Files\IRootFolder $root */
			$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
			$userFolder = $root->getUserFolder($user->getUID());

			// SnappyMail sends "/Documents/app.svg" style paths; userFolder expects relative.
			$relPath = \ltrim($sFile, '/');

			if ($relPath === '') {
				$aResult['error'] = 'empty-path';
				return $this->jsonResponse(__FUNCTION__, $aResult);
			}

			$node = $userFolder->get($relPath);
			if (!($node instanceof \OCP\Files\File)) {
				$aResult['error'] = 'not-a-file';
				return $this->jsonResponse(__FUNCTION__, $aResult);
			}

			$fp = $node->fopen('rb');
			if (!$fp) {
				$aResult['error'] = 'open-failed';
				return $this->jsonResponse(__FUNCTION__, $aResult);
			}

			$sSavedName = 'nextcloud-file-' . \sha1($sFile . \microtime(true));
			$ok = $oActions->FilesProvider()->PutFile($oAccount, $sSavedName, $fp);
			@\fclose($fp);

			if (!$ok) {
				$aResult['error'] = 'failed';
			} else {
				$aResult['tempName'] = $sSavedName;
				$aResult['success'] = true;
			}
		} catch (\Throwable $e) {
			$aResult['error'] = 'exception';
			$aResult['errorMessage'] = $e->getMessage();
		}

		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	/**
	 * Save an .eml message from SnappyMail into Nextcloud Files.
	 */
	public function NextcloudSaveMsg() : array
	{
		$sSaveFolder = \ltrim((string) $this->jsonParam('folder', ''), '/');

		$msgHash = (string) $this->jsonParam('msgHash', '');
		$aValues = \json_decode(\MailSo\Base\Utils::UrlSafeBase64Decode($msgHash), true);

		$aResult = [
			'folder' => '',
			'filename' => '',
			'success' => false,
		];

		if (!empty($aValues['folder']) && !empty($aValues['uid'])) {
			$oActions = \RainLoop\Api::Actions();
			$oMailClient = $oActions->MailClient();

			if (!$oMailClient->IsLoggined()) {
				$oAccount = $oActions->getAccountFromToken();
				$oAccount->ImapConnectAndLogin($oActions->Plugins(), $oMailClient->ImapClient(), $oActions->Config());
			}

			// Default folder
			$sSaveFolder = $sSaveFolder ?: 'Emails';
			$sSaveFolder = \trim($sSaveFolder, '/');

			// Use Nextcloud Files API (per-user)
			$userFolder = \OC::$server->getUserFolder();

			// Create folder path (supports nested folders)
			$folderNode = $this->ensureFolderPath($userFolder, $sSaveFolder);

			$filenameBase = (string) ($this->jsonParam('filename', '') ?: \date('YmdHis'));
			$safeBase = \MailSo\Base\Utils::SecureFileName(\mb_substr($filenameBase, 0, 100));
			$filename = $safeBase . '.' . \md5($msgHash) . '.eml';

			$aResult['folder'] = $sSaveFolder;
			$aResult['filename'] = $filename;

			$oMailClient->MessageMimeStream(
				function ($rResource) use (&$aResult, $folderNode, $filename) {
					if (!\is_resource($rResource)) {
						return;
					}

					// If exists, overwrite by deleting and recreating (keeps behavior deterministic)
					if ($folderNode->nodeExists($filename)) {
						$existing = $folderNode->get($filename);
						if ($existing instanceof \OCP\Files\File) {
							$existing->delete();
						}
					}

					$fileNode = $folderNode->newFile($filename);
					$out = $fileNode->fopen('w');
					if (\is_resource($out)) {
						\stream_copy_to_stream($rResource, $out);
						\fclose($out);
						$aResult['success'] = true;
					}
				},
				(string) $aValues['folder'],
				(int) $aValues['uid'],
				isset($aValues['mimeIndex']) ? (string) $aValues['mimeIndex'] : ''
			);
		}

		return $this->jsonResponse(__FUNCTION__, $aResult);
	}

	/**
	 * Save message attachments from SnappyMail into Nextcloud Files.
	 * (Used by SnappyMail "save attachments to nextcloud" actions.)
	 */
	public function DoAttachmentsActions(\SnappyMail\AttachmentsAction $data)
	{
		if (!static::isLoggedIn() || 'nextcloud' !== $data->action) {
			return;
		}

		try {
			// Target folder in Nextcloud
			$sSaveFolder = \ltrim((string) $this->jsonParam('NcFolder', ''), '/');
			$sSaveFolder = $sSaveFolder ?: 'Attachments';
			$sSaveFolder = \trim($sSaveFolder, '/');

			$userFolder = \OC::$server->getUserFolder();
			$folderNode = $this->ensureFolderPath($userFolder, $sSaveFolder);

			$data->result = true;

			foreach ($data->items as $aItem) {
				$sSavedFileName = empty($aItem['fileName']) ? 'file.dat' : (string) $aItem['fileName'];
				$sSavedFileName = \MailSo\Base\Utils::SecureFileName(\mb_substr($sSavedFileName, 0, 180));

				$finalName = $this->uniqueNameInFolder($folderNode, $sSavedFileName);

				// Create file node
				$fileNode = $folderNode->newFile($finalName);

				$out = $fileNode->fopen('w');
				if (!\is_resource($out)) {
					$data->result = false;
					continue;
				}

				$wrote = false;

				if (!empty($aItem['data'])) {
					// Raw data string
					$bytes = \fwrite($out, (string) $aItem['data']);
					$wrote = ($bytes !== false);
				} else if (!empty($aItem['fileHash'])) {
					// Stream from SnappyMail temp storage
					$fFile = $data->filesProvider->GetFile($data->account, (string) $aItem['fileHash'], 'rb');
					if (\is_resource($fFile)) {
						\stream_copy_to_stream($fFile, $out);
						\fclose($fFile);
						$wrote = true;
					}
				}

				\fclose($out);

				if (!$wrote) {
					// Clean up the empty file
					try { $fileNode->delete(); } catch (\Throwable $e) {}
					$data->result = false;
				}
			}
		} catch (\Throwable $e) {
			$data->result = false;
		}
	}

	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if ($bAdmin || !\is_array($aResult)) {
			return;
		}

		$ocUser = \OC::$server->getUserSession()->getUser();
		if (!$ocUser) {
			return;
		}

		$sUID = $ocUser->getUID();
		$oUrlGen = \OC::$server->getURLGenerator();
		$sWebDAV = $oUrlGen->getAbsoluteURL($oUrlGen->linkTo('', 'remote.php') . '/dav');

		$aResult['Nextcloud'] = [
			'UID' => $sUID,
			'WebDAV' => $sWebDAV,
			'CalDAV' => $this->Config()->Get('plugin', 'calendar', false)
		];

		if (empty($aResult['Auth'])) {
			$config = \OC::$server->getConfig();
			$sEmail = '';

			if ($config->getAppValue('snappymail', 'snappymail-autologin', false)) {
				$sEmail = $sUID;
			} else if ($config->getAppValue('snappymail', 'snappymail-autologin-with-email', false)) {
				$sEmail = $config->getUserValue($sUID, 'settings', 'email', '');
			} else {
				\SnappyMail\Log::debug('Nextcloud', 'snappymail-autologin is off');
			}

			// User-set SnappyMail credentials override
			$sCustomEmail = $config->getUserValue($sUID, 'snappymail', 'snappymail-email', '');
			if ($sCustomEmail) {
				$sEmail = $sCustomEmail;
			}

			if (!$sEmail) {
				$sEmail = $ocUser->getEMailAddress();
			}

			$aResult['DevEmail'] = $sEmail ?: '';
		} else if (!empty($aResult['ContactsSync'])) {
			$bSave = false;

			if (empty($aResult['ContactsSync']['Url'])) {
				$aResult['ContactsSync']['Url'] = "{$sWebDAV}/addressbooks/users/{$sUID}/contacts/";
				$bSave = true;
			}
			if (empty($aResult['ContactsSync']['User'])) {
				$aResult['ContactsSync']['User'] = $sUID;
				$bSave = true;
			}

			// FIX: do not array-access the session; use ->get()
			$pass = (string) \OC::$server->getSession()->get('snappymail-passphrase');
			if ($pass) {
				$pass = \SnappyMail\Crypt::DecryptUrlSafe($pass, $sUID);
				if ($pass) {
					$aResult['ContactsSync']['Password'] = $pass;
					$bSave = true;
				}
			}

			if ($bSave) {
				$oActions = \RainLoop\Api::Actions();
				$oActions->setContactsSyncData(
					$oActions->getAccountFromToken(),
					[
						'Mode' => $aResult['ContactsSync']['Mode'],
						'User' => $aResult['ContactsSync']['User'],
						'Password' => $aResult['ContactsSync']['Password'],
						'Url' => $aResult['ContactsSync']['Url']
					]
				);
			}
		}
	}

	public function FilterLanguage(&$sLanguage, $bAdmin) : void
	{
		if (!\RainLoop\Api::Config()->Get('webmail', 'allow_languages_on_settings', true)) {
			$aResultLang = \SnappyMail\L10n::getLanguages($bAdmin);
			$userId = \OC::$server->getUserSession()->getUser()->getUID();
			$userLang = \OC::$server->getConfig()->getUserValue($userId, 'core', 'lang', 'en');
			$userLang = \strtr($userLang, '_', '-');
			$sLanguage = $this->determineLocale($userLang, $aResultLang);
			if (!$sLanguage) {
				$sLanguage = 'en';
			}
		}
	}

	private function determineLocale(string $langCode, array $languagesArray) : ?string
	{
		if (\in_array($langCode, $languagesArray, true)) {
			return $langCode;
		}

		if (\str_contains($langCode, '-')) {
			$langCode = \explode('-', $langCode)[0];
			if (\in_array($langCode, $languagesArray, true)) {
				return $langCode;
			}
		}

		$langCodeWithUpperCase = $langCode . '-' . \strtoupper($langCode);
		if (\in_array($langCodeWithUpperCase, $languagesArray, true)) {
			return $langCodeWithUpperCase;
		}

		return null;
	}

	public function MainFabrica(string $sName, &$mResult)
	{
		if (!static::isLoggedIn()) {
			return;
		}

		if ('suggestions' === $sName && $this->Config()->Get('plugin', 'suggestions', true)) {
			if (!\is_array($mResult)) {
				$mResult = [];
			}
			include_once __DIR__ . '/NextcloudContactsSuggestions.php';
			$mResult[] = new NextcloudContactsSuggestions(
				$this->Config()->Get('plugin', 'ignoreSystemAddressbook', true)
			);
		}

		// storage hook left as upstream (commented)
	}

	protected function configMapping() : array
	{
		return [
			\RainLoop\Plugins\Property::NewInstance('suggestions')->SetLabel('Suggestions')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true),

			\RainLoop\Plugins\Property::NewInstance('ignoreSystemAddressbook')->SetLabel('Ignore system addressbook')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true),

			\RainLoop\Plugins\Property::NewInstance('calendar')->SetLabel('Enable "Put ICS in calendar"')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
		];
	}

	/**
	 * Ensure a (possibly nested) folder path exists under the given base folder.
	 */
	private function ensureFolderPath(\OCP\Files\Folder $baseFolder, string $path) : \OCP\Files\Folder
	{
		$path = \trim($path, '/');
		if ($path === '') {
			return $baseFolder;
		}

		$folderNode = $baseFolder;
		foreach (\array_filter(\explode('/', $path), 'strlen') as $part) {
			$part = (string) $part;
			if ($folderNode->nodeExists($part)) {
				$node = $folderNode->get($part);
				if ($node instanceof \OCP\Files\Folder) {
					$folderNode = $node;
				} else {
					// name collision: file exists where folder needed; create a suffixed folder
					$folderNode = $folderNode->newFolder($part . '-folder');
				}
			} else {
				$folderNode = $folderNode->newFolder($part);
			}
		}

		return $folderNode;
	}

	/**
	 * Return a unique filename inside a Nextcloud folder (keeps original extension).
	 */
	private function uniqueNameInFolder(\OCP\Files\Folder $folderNode, string $fileName) : string
	{
		$fileName = \trim($fileName);
		if ($fileName === '') {
			$fileName = 'file.dat';
		}

		if (!$folderNode->nodeExists($fileName)) {
			return $fileName;
		}

		$info = \pathinfo($fileName);
		$base = $info['filename'] ?? 'file';
		$ext  = isset($info['extension']) && $info['extension'] !== '' ? ('.' . $info['extension']) : '';

		for ($i = 1; $i <= 50; $i++) {
			$try = $base . ' (' . $i . ')' . $ext;
			if (!$folderNode->nodeExists($try)) {
				return $try;
			}
		}

		// fallback
		return $fileName;
	}
}
