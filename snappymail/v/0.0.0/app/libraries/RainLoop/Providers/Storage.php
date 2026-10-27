<?php

namespace RainLoop\Providers;

use RainLoop\Model\Account;

class Storage extends \RainLoop\Providers\AbstractProvider
{
	/**
	 * @var \RainLoop\Providers\Storage\IStorage
	 */
	private $oDriver;

	public function __construct(\RainLoop\Providers\Storage\IStorage $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	private function verifyAccount(Account|string|null $mAccount, int $iStorageType): bool
	{
		return \RainLoop\Providers\Storage\Enumerations\StorageType::NOBODY === $iStorageType
			|| $mAccount instanceof Account
			|| \is_string($mAccount);
	}

	public function Put(Account|string|null $mAccount, int $iStorageType, string $sKey, string $sValue): bool
	{
		return $this->verifyAccount($mAccount, $iStorageType)
			? $this->oDriver->Put($mAccount, $iStorageType, $sKey, $sValue)
			: false;
	}

	public function Get(Account|string|null $mAccount, int $iStorageType, string $sKey, mixed $mDefault = false): mixed
	{
		return $this->verifyAccount($mAccount, $iStorageType)
			? $this->oDriver->Get($mAccount, $iStorageType, $sKey, $mDefault)
			: $mDefault;
	}

	public function Clear(Account|string|null $mAccount, int $iStorageType, string $sKey): bool
	{
		return $this->verifyAccount($mAccount, $iStorageType)
			? $this->oDriver->Clear($mAccount, $iStorageType, $sKey)
			: false;
	}

	public function DeleteStorage(Account|string $mAccount): bool
	{
		return $this->oDriver->DeleteStorage($mAccount);
	}

	public function GenerateFilePath(Account|string|null $mAccount, int $iStorageType, bool $bMkDir = false): string
	{
		return $this->oDriver->GenerateFilePath($mAccount, $iStorageType, $bMkDir);
	}

	public function IsActive(): bool
	{
		return true;
	}

	public function IsLocal(): bool
	{
		return $this->oDriver->IsLocal();
	}

	public function GC(): void
	{
		$this->oDriver->GC();
	}
}
