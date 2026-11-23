<?php

namespace RainLoop\Providers\Storage;

use RainLoop\Model\Account;
use RainLoop\Providers\Storage\Enumerations\StorageType;

interface IStorage
{
	public function Put(Account|string|null $mAccount, StorageType $eStorageType, string $sKey, string $sValue): bool;

	public function Get(Account|string|null $mAccount, StorageType $eStorageType, string $sKey, mixed $mDefault = false): mixed;

	public function Clear(Account|string|null $mAccount, StorageType $eStorageType, string $sKey): bool;

	public function IsLocal(): bool;
}
