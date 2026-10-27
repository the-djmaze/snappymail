<?php

namespace RainLoop\Providers\Storage;

use RainLoop\Model\Account;

interface IStorage
{
	public function Put(Account|string|null $mAccount, int $iStorageType, string $sKey, string $sValue): bool;

	public function Get(Account|string|null $mAccount, int $iStorageType, string $sKey, mixed $mDefault = false): mixed;

	public function Clear(Account|string|null $mAccount, int $iStorageType, string $sKey): bool;

	public function IsLocal(): bool;
}
