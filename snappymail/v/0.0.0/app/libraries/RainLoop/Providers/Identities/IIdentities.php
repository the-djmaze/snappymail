<?php

namespace RainLoop\Providers\Identities;

use RainLoop\Model\Account;
use RainLoop\Model\Identity;

interface IIdentities
{
	/**
	 * @return Identity[]
	 */
	public function GetIdentities(Account $account): array;

	/**
	 * @param Identity[] $identities
	 */
	public function SetIdentities(Account $account, array $identities): void;

	public function SupportsStore(): bool;

	public function Name(): string;
}
