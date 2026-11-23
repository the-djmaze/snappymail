<?php

namespace RainLoop\Providers\Storage;

use RainLoop\Model\Account;
use RainLoop\Providers\Storage\Enumerations\StorageType;

class FileStorage implements \RainLoop\Providers\Storage\IStorage
{
	use \MailSo\Log\Inherit;

	protected string $sDataPath;

	private bool $bLocal;

	public function __construct(string $sStoragePath, bool $bLocal = false)
	{
		$this->sDataPath = \rtrim(\trim($sStoragePath), '\\/');
		$this->bLocal = $bLocal;
	}

	public function Put(Account|string|null $mAccount, StorageType $eStorageType, string $sKey, string $sValue): bool
	{
		$sFileName = $this->generateFileName($mAccount, $eStorageType, $sKey, true);
		try {
			$sFileName && \RainLoop\Utils::saveFile($sFileName, $sValue);
			return true;
		} catch (\Throwable $e) {
			\SnappyMail\Log::warning('FileStorage', $e->getMessage());
		}
		return false;
	}

	public function Get(Account|string|null $mAccount, StorageType $eStorageType, string $sKey, mixed $mDefault = false): mixed
	{
		$mValue = false;
		$sFileName = $this->generateFileName($mAccount, $eStorageType, $sKey);
		if ($sFileName && \file_exists($sFileName)) {
			for ($i = 0; $i < 5; ++$i) {
				$i && \usleep(20000);
				$mValue = \file_get_contents($sFileName);
				if (false !== $mValue) {
					// Update mtime to prevent garbage collection
					if (StorageType::SESSION === $eStorageType) {
						\touch($sFileName);
					}
					return $mValue;
				}
			}
		}
		return $mDefault;
	}

	public function Clear(Account|string|null $mAccount, StorageType $eStorageType, string $sKey): bool
	{
		$sFileName = $this->generateFileName($mAccount, $eStorageType, $sKey);
		return $sFileName && \file_exists($sFileName) && \unlink($sFileName);
	}

	public function DeleteStorage(Account|string|null $mAccount): bool
	{
		$sPath = $this->generateFileName($mAccount, StorageType::CONFIG, '');
		if ($sPath && \is_dir($sPath)) {
			\MailSo\Base\Utils::RecRmDir($sPath);
		}
		return true;
	}

	public function IsLocal(): bool
	{
		return $this->bLocal;
	}

	public function GenerateFilePath(Account|string|null $mAccount, StorageType $eStorageType, bool $bMkDir = false): string
	{
		$sEmail = $sSubFolder = $sFilePath = '';
		if (null === $mAccount || StorageType::NOBODY === $eStorageType) {
			$sFilePath = $this->sDataPath.'/__nobody__/';
		} else {
			if ($mAccount instanceof \RainLoop\Model\MainAccount) {
				$sEmail = $mAccount->Email();
			} else if ($mAccount instanceof \RainLoop\Model\AdditionalAccount) {
				$sEmail = $mAccount->ParentEmail();
				if ($this->bLocal) {
					$sSubFolder = $mAccount->Email();
				}
			} else if (\is_string($mAccount)) {
				$sEmail = $mAccount;
			}

			if ($sEmail) {
				// these are never local
				if (StorageType::SIGN_ME === $eStorageType) {
					$sSubFolder = '.sign_me';
				} else if (StorageType::SESSION === $eStorageType) {
					$sSubFolder = '.sessions';
				} else if (StorageType::PGP === $eStorageType) {
					$sSubFolder = '.pgp';
				} else if (StorageType::ROOT === $eStorageType) {
					$sSubFolder = '';
				}
			}

			switch ($eStorageType)
			{
				case StorageType::CONFIG:
				case StorageType::SIGN_ME:
				case StorageType::SESSION:
				case StorageType::PGP:
				case StorageType::ROOT:
					if (empty($sEmail)) {
						return '';
					}
					if (\is_dir("{$this->sDataPath}/cfg")) {
						\SnappyMail\Upgrade::FileStorage($this->sDataPath);
					}
					$aEmail = \explode('@', $sEmail ?: 'nobody@unknown.tld');
					$sDomain = \trim(1 < \count($aEmail) ? \array_pop($aEmail) : '');
					$sFilePath = $this->sDataPath
						.'/'.\MailSo\Base\Utils::SecureFileName($sDomain ?: 'unknown.tld')
						.'/'.\MailSo\Base\Utils::SecureFileName(\implode('@', $aEmail) ?: '.unknown')
						.'/'.($sSubFolder ? \MailSo\Base\Utils::SecureFileName($sSubFolder).'/' : '');
					break;
				default:
					throw new \Exception('Invalid storage type');
			}
		}

		$bMkDir && !empty($sFilePath) && \MailSo\Base\Utils::mkdir($sFilePath);

		return $sFilePath;
	}

	protected function generateFileName(Account|string|null $mAccount, StorageType $eStorageType, string $sKey, bool $bMkDir = false): string
	{
		$sFilePath = $this->GenerateFilePath($mAccount, $eStorageType, $bMkDir);
		if ($sFilePath) {
			if (StorageType::NOBODY === $eStorageType) {
				$sFilePath .= \sha1($sKey ?: \time());
			} else {
				$sFilePath .= ($sKey ? \MailSo\Base\Utils::SecureFileName($sKey) : '');
			}
		}
		return $sFilePath;
	}

	public function GC(): void
	{
		\clearstatcache();
		foreach (\glob("{$this->sDataPath}/*", GLOB_ONLYDIR) as $sDomain) {
			foreach (\glob("{$sDomain}/*", GLOB_ONLYDIR) as $sLocal) {
				\MailSo\Base\Utils::RecTimeDirRemove("{$sLocal}/.sign_me", 3600 * 24 * 30); // 30 days
				\MailSo\Base\Utils::RecTimeDirRemove("{$sLocal}/.sessions", 3600 * 3); // 3 hours
			}
		}
	}
}
