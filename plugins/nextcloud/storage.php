<?php

use RainLoop\Model\Account;
use RainLoop\Providers\Storage\Enumerations\StorageType;

class NextcloudStorage extends \RainLoop\Providers\Storage\FileStorage
{
	public function GenerateFilePath(Account|string|null $mAccount, StorageType|int $eStorageType, bool $bMkDir = false) : string
	{
		$sDataPath = parent::GenerateFilePath($mAccount, $eStorageType, $bMkDir);
		if (StorageType::CONFIG === $eStorageType) {
			$sUID = \OCA\SnappyMail\Util\SnappyMailHelper::getUID();
			$sDataPath .= ".config/{$sUID}/";
			if ($bMkDir && !\is_dir($sDataPath) && !\mkdir($sDataPath, 0700, true)) {
				throw new \RainLoop\Exceptions\Exception('Can\'t make storage directory "'.$sDataPath.'"');
			}
		}
		return $sDataPath;
	}
}
