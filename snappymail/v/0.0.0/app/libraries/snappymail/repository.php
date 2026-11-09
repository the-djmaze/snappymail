<?php

namespace SnappyMail;

abstract class Repository
{
	const BASE_URL = 'https://snappymail.eu/repository/v2/';
	const CORE_API_URL = 'https://api.github.com/repos/the-djmaze/snappymail/releases/latest';

	private static function httpGet(string $url, array $headers = []): string
	{
		$oHTTP = HTTP\Request::factory(/*'socket' or 'curl'*/);
		$oHTTP->proxy = \RainLoop\Api::Config()->Get('labs', 'curl_proxy', '');
		$oHTTP->proxy_auth = \RainLoop\Api::Config()->Get('labs', 'curl_proxy_auth', '');
		$oHTTP->verify_peer = \RainLoop\Api::Config()->Get('labs', 'curl_verify_peer', true);
		$oHTTP->max_response_kb = 0;
		$oHTTP->timeout = 15;
		$oResponse = $oHTTP->doRequest('GET', $url, null, $headers);
		if (!$oResponse) {
			throw new \Exception('No HTTP response from ' . $url);
		}
		if (200 !== $oResponse->status) {
			throw new \Exception('HTTP ' . $oResponse->status . ':  from ' . $url, $oResponse->status);
		}
		return $oResponse->body;
	}

//	$aRep = \json_decode($sRep);

	private static function download(string $url): string
	{
		$sTmp = APP_PRIVATE_DATA . \md5(\microtime(true) . $url) . \preg_replace('/^.*?(\\.[a-z\\.]+)$/Di', '$1', $url);
		$pDest = \fopen($sTmp, 'w+b');
		if (!$pDest) {
			throw new \Exception('Cannot create temp file: ' . $sTmp);
		}

		$oHTTP = HTTP\Request::factory(/*'socket' or 'curl'*/);
		$oHTTP->proxy = \RainLoop\Api::Config()->Get('labs', 'curl_proxy', '');
		$oHTTP->proxy_auth = \RainLoop\Api::Config()->Get('labs', 'curl_proxy_auth', '');
		$oHTTP->verify_peer = \RainLoop\Api::Config()->Get('labs', 'curl_verify_peer', true);
		$oHTTP->max_response_kb = 0;
		$oHTTP->timeout = 15;
		$oHTTP->streamBodyTo($pDest);
		\set_time_limit(120);
		$oResponse = $oHTTP->doRequest('GET', $url);
		\fclose($pDest);
		if (!$oResponse) {
			\unlink($sTmp);
			throw new \Exception('No HTTP response from ' . $url);
		}
		if (200 !== $oResponse->status) {
			$body = \file_get_contents($sTmp);
			\unlink($sTmp);
			throw new \Exception(static::body2plain($body), $oResponse->status);
		}
		return $sTmp;
	}

	private static function body2plain(string $body): string
	{
		return \trim(\strip_tags(
			\preg_match('@<body[^>]*>(.*)</body@si', $body, $match) ? $match[1] : $body
		));
	}

	private static function getRepositoryDataByUrl(bool &$bReal = false): array
	{
		$bReal = false;
		$aRep = null;

		$sRepoFile = 'packages.json';

		$oCache = \RainLoop\Api::Actions()->Cacher();

		$sCacheKey = '/RepositoryCache/Repo/' . static::BASE_URL . '/File/' . $sRepoFile;
		$sRep = $oCache->Get($sCacheKey);
		$iRepTime = $sRep ? $oCache->GetTimer($sCacheKey) : 0;

		if (!$sRep || !$iRepTime || \time() - 3600 > $iRepTime) {
			$sRep = static::httpGet(static::BASE_URL . $sRepoFile);
			if ($sRep) {
				$aRep = \json_decode($sRep);
				$bReal = \is_array($aRep) && \count($aRep);
				if ($bReal) {
					$oCache->Set($sCacheKey, $sRep);
					$oCache->SetTimer($sCacheKey);
				}
			} else {
				throw new \Exception('Cannot read remote repository file: '.$sRepoFile);
			}
		} else if ($sRep) {
			$aRep = \json_decode($sRep, false, 10);
			$bReal = \is_array($aRep) && \count($aRep);
		}

		return \is_array($aRep) ? $aRep : [];
	}

	private static function getRepositoryData(bool &$bReal, string &$sError): array
	{
		$aResult = array();
		try {
			foreach (static::getRepositoryDataByUrl($bReal) as $oItem) {
				if ($oItem
				 && isset($oItem->type, $oItem->id, $oItem->name, $oItem->version, $oItem->release, $oItem->file, $oItem->description)
				 && 'plugin' === $oItem->type
				 // is this entry newer then an already defined one
				 && (empty($aResult[$oItem->id]) || \version_compare($aResult[$oItem->id]['version'], $oItem->version, '<'))
				 // does this entry require same or older app version
				 && (SNAPPYMAIL_DEV || empty($oItem->required) || \version_compare(APP_VERSION, $oItem->required, '>='))
				 // is this entry not deprecated for current app version?
				 && (SNAPPYMAIL_DEV || empty($oItem->deprecated) || \version_compare(APP_VERSION, $oItem->deprecated, '<'))
				) {
					$aResult[$oItem->id] = array(
						'type' => $oItem->type,
						'id' => $oItem->id,
						'name' => $oItem->name,
						'installed' => '',
						'enabled' => true,
						'version' => $oItem->version,
						'file' => $oItem->file,
						'release' => $oItem->release,
						'desc' => $oItem->description,
						'canBeDeleted' => false,
						'canBeUpdated' => true
					);
				}
			}
		} catch (\Throwable $e) {
			\SnappyMail\Log::error('INSTALLER', "{$e->getCode()} {$e->getMessage()}");
		}
		return $aResult;
	}

	/**
	 * return object
			$info->version
			$info->file
			$info->url
			$info->warnings
	 */
	public static function getLatestCoreInfo(): ?object
	{
		\RainLoop\Api::Actions()->IsAdminLoggined();
		try {
			$sBody = static::httpGet(static::CORE_API_URL, [
				'Accept: application/vnd.github+json',
				'User-Agent: SnappyMail/' . APP_VERSION,
			]);
		} catch (\Throwable $e) {
			\SnappyMail\Log::error('UPDATER', $e->getMessage());
			// GitHub failed, try main website
			$sRep = static::httpGet(static::BASE_URL . 'core.json');
			$sRep = $sRep ? \json_decode($sRep, false, 3, JSON_THROW_ON_ERROR) : null;
			if ($sRep) {
				$info->url = static::BASE_URL . $info->file;
			}
			return $sRep ? \json_decode($sRep, false, 3, JSON_THROW_ON_ERROR) : null;
		}
		if (!$sBody) {
			return null;
		}
		$data = \json_decode($sBody, false, 5);
		if (!$data || empty($data->tag_name)) {
			return null;
		}

		$sVersion = \ltrim($data->tag_name, 'v');

		// By exact name. A release carries several .tar.gz assets, and the
		// cPanel, Nextcloud and ownCloud packages sort ahead of the core one, so
		// taking the first match downloaded a cPanel bundle that unpacks under
		// /usr/local/cpanel and has no snappymail/ entry to extract at all.
		$sWanted = "snappymail-{$sVersion}.tar.gz";
		$downloadUrl = null;
		foreach ($data->assets ?? [] as $asset) {
			if ($sWanted === ($asset->name ?? '')) {
				$downloadUrl = $asset->browser_download_url;
				break;
			}
		}
		if (!$downloadUrl) {
			\SnappyMail\Log::error('UPDATER', "Release {$data->tag_name} has no {$sWanted} asset");
			return null;
		}

		$info = new \stdClass();
		$info->version = $sVersion;
		$info->file = $sWanted;
		$info->url = $downloadUrl;
		return $info;
	}

	public static function downloadCore() : ?string
	{
		$info = static::getLatestCoreInfo();
		return ($info && \version_compare(APP_VERSION, $info->version, '<'))
			? static::download($info->url)
			: null;
	}

	public static function canUpdateCore() : bool
	{
		return \version_compare(APP_VERSION, '2.0', '>')
			&& \is_writable(\dirname(APP_VERSION_ROOT_PATH))
			&& \is_writable(APP_INDEX_ROOT_PATH . 'index.php')
			&& \RainLoop\Api::Config()->Get('admin_panel', 'allow_update', false);
	}

	public static function getEnabledPackagesNames() : array
	{
		return \array_map('trim',
			\explode(',', \strtolower(\RainLoop\Api::Config()->Get('plugins', 'enabled_list', '')))
		);
	}

	public static function enablePackage(string $sName, bool $bEnable = true) : bool
	{
		if (!\strlen($sName)) {
			return false;
		}

		$oConfig = \RainLoop\Api::Config();

		$aEnabledPlugins = static::getEnabledPackagesNames();

		$aNewEnabledPlugins = array();
		if ($bEnable) {
			$aNewEnabledPlugins = $aEnabledPlugins;
			$aNewEnabledPlugins[] = $sName;
		} else {
			foreach ($aEnabledPlugins as $sPlugin) {
				if ($sName !== $sPlugin && \strlen($sPlugin)) {
					$aNewEnabledPlugins[] = $sPlugin;
				}
			}
		}

		$oConfig->Set('plugins', 'enabled_list', \trim(\implode(',', \array_unique($aNewEnabledPlugins)), ' ,'));

		return $oConfig->Save();
	}

	public static function getPackagesList() : array
	{
		empty($_ENV['SNAPPYMAIL_INCLUDE_AS_API']) && \RainLoop\Api::Actions()->IsAdminLoggined();

		$bReal = false;
		$sError = '';
		$aList = static::getRepositoryData($bReal, $sError);

		$aEnabledPlugins = static::getEnabledPackagesNames();

		$aInstalled = \RainLoop\Api::Actions()->Plugins()->InstalledPlugins();
		foreach ($aInstalled as $aItem) {
			if ($aItem) {
				if (isset($aList[$aItem[0]])) {
					$aList[$aItem[0]]['installed'] = $aItem[1];
					$aList[$aItem[0]]['enabled'] = \in_array(\strtolower($aItem[0]), $aEnabledPlugins);
					$aList[$aItem[0]]['canBeDeleted'] = true;
					$aList[$aItem[0]]['canBeUpdated'] = \version_compare($aItem[1], $aList[$aItem[0]]['version'], '<');
				} else {
					\array_push($aList, array(
						'type' => 'plugin',
						'id' => $aItem[0],
						'name' => $aItem[2],
						'installed' => $aItem[1],
						'enabled' => \in_array(\strtolower($aItem[0]), $aEnabledPlugins),
						'version' => '',
						'file' => '',
						'release' => '',
						'desc' => $aItem[3],
						'canBeDeleted' => true,
						'canBeUpdated' => false
					));
				}
			}
		}

//		\uksort($aList, static function($a, $b){return \strcasecmp($a['name'], $b['name']);});

		return array(
			 'Real' => $bReal,
			 'List' => \array_values($aList),
			 'Error' => $sError
		);
	}

	public static function deletePackage(string $sId) : bool
	{
		\RainLoop\Api::Actions()->IsAdminLoggined();
		static::enablePackage($sId, false);
		return static::deletePackageDir($sId);
	}

	private static function deletePackageDir(string $sId) : bool
	{
		$sPath = APP_PLUGINS_PATH . $sId;
		return (!\is_dir($sPath) || \MailSo\Base\Utils::RecRmDir($sPath))
			&& (!\is_file("{$sPath}.phar") || \unlink("{$sPath}.phar"));
	}

	public static function installPackage(string $sType, string $sId, string $sFile = '') : bool
	{
		empty($_ENV['SNAPPYMAIL_INCLUDE_AS_API']) && \RainLoop\Api::Actions()->IsAdminLoggined();

		\SnappyMail\Log::info('INSTALLER', 'Start package install: ' . $sId . ' (' . $sType . ')');

		$sRealFile = '';

		$bResult = false;
		$sTmp = null;
		try {
			if ('plugin' === $sType) {
				$bReal = false;
				$sError = '';
				$aList = static::getRepositoryData($bReal, $sError);
				if ($sError) {
					throw new \Exception($sError);
				}
				if (isset($aList[$sId]) && (!$sFile || $sFile === $aList[$sId]['file'])) {
					$sRealFile = $aList[$sId]['file'];
					$sUrl = static::BASE_URL . $aList[$sId]['file'];
					$sTmp = static::download($sUrl);

					// https://github.com/the-djmaze/snappymail/security/advisories/GHSA-j9c4-gf64-rph2
					// Verify PGP signature
					$sTmpSignature = static::download($sUrl . '.asc');
					$signature = $sTmpSignature ? \file_get_contents($sTmpSignature) : null;
					$sTmpSignature && \unlink($sTmpSignature);
					if (!$signature) {
						throw new \RuntimeException('Plugin PGP signature is missing');
					}
/*
					if ($aList[$sId]['pgp_sig'] !== \trim(\preg_replace('/-----(BEGIN|END) PGP SIGNATURE-----/', '', $signature))) {
						throw new \RuntimeException('Plugin PGP signature invalid');
					}
*/
					try {
						\SnappyMail\Repository\Signature::verifyPGP($sTmp, $signature);
					} catch (\Throwable $e) {
						\SnappyMail\Log::error('INSTALLER', "Verify PGP failed for {$sRealFile}: {$e->getMessage()}");
						// Verify x509 signature
						$sTmpSignature = static::download($sUrl . '.sig');
						$signature = $sTmpSignature ? \file_get_contents($sTmpSignature) : null;
						$sTmpSignature && \unlink($sTmpSignature);
						if (!$signature) {
							throw new \RuntimeException('Plugin x509 signature is missing');
						}
						\SnappyMail\Repository\Signature::verifySSL($sTmp, $signature);
					}
				}
			}

			if ($sTmp) {
				if (!static::deletePackageDir($sId)) {
					throw new \Exception('Cannot remove previous plugin folder: ' . $sId);
				}
				if ('.phar' === \substr($sRealFile, -5)) {
					$bResult = \copy($sTmp, APP_PLUGINS_PATH . \basename($sRealFile));
				} else {
					if (\class_exists('PharData')) {
						$oArchive = new \PharData($sTmp, 0, $sRealFile);
					} else {
						$oArchive = new \SnappyMail\TAR($sTmp);
					}
					$bResult = $oArchive->extractTo(\rtrim(APP_PLUGINS_PATH, '\\/'));
				}
				if (!$bResult) {
					throw new \Exception('Cannot extract package files');
				}
			}
		} catch (\Throwable $e) {
			\SnappyMail\Log::error('INSTALLER', "Install package {$sRealFile} failed: {$e->getMessage()}");
			throw $e;
		} finally {
			$sTmp && \unlink($sTmp);
		}

		return $bResult;
	}

}
