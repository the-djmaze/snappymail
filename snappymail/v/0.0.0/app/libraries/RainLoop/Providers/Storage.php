<?php

namespace RainLoop\Providers;

use RainLoop\Model\Account;
use RainLoop\Providers\Storage\IStorage;
use RainLoop\Providers\Storage\Enumerations\StorageType;

class Storage extends \RainLoop\Providers\AbstractProvider
{
	private IStorage $oDriver;

	public function __construct(IStorage $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	private function verifyAccount(Account|string|null $mAccount, StorageType $eStorageType): bool
	{
		return StorageType::NOBODY === $eStorageType
			|| $mAccount instanceof Account
			|| \is_string($mAccount);
	}

	public function Put(Account|string|null $mAccount, StorageType $eStorageType, string $sKey, string $sValue): bool
	{
		return $this->verifyAccount($mAccount, $eStorageType)
			? $this->oDriver->Put($mAccount, $eStorageType, $sKey, $sValue)
			: false;
	}

	public function Get(Account|string|null $mAccount, StorageType $eStorageType, string $sKey, mixed $mDefault = false): mixed
	{
		return $this->verifyAccount($mAccount, $eStorageType)
			? $this->oDriver->Get($mAccount, $eStorageType, $sKey, $mDefault)
			: $mDefault;
	}

	public function Clear(Account|string|null $mAccount, StorageType $eStorageType, string $sKey): bool
	{
		return $this->verifyAccount($mAccount, $eStorageType)
			? $this->oDriver->Clear($mAccount, $eStorageType, $sKey)
			: false;
	}

	public function DeleteStorage(Account|string $mAccount): bool
	{
		return $this->oDriver->DeleteStorage($mAccount);
	}

	public function GenerateFilePath(Account|string|null $mAccount, StorageType $eStorageType, bool $bMkDir = false): string
	{
		return $this->oDriver->GenerateFilePath($mAccount, $eStorageType, $bMkDir);
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
