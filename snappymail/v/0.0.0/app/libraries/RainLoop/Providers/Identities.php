<?php

namespace RainLoop\Providers;

use RainLoop\Model\Account;
use RainLoop\Model\Identity;
use RainLoop\Providers\Identities\IIdentities;

class Identities extends AbstractProvider
{
	/** @var IIdentities[] */
	private array $drivers;

	/** @var Identity[][][] */
	private array $identitiesPerDriverPerAccount = [];

	/**
	 * Identities constructor.
	 * @param IIdentities[] $drivers
	 */
	public function __construct(?array $drivers = null)
	{
		$this->drivers = \array_filter($drivers ?: [], fn($driver) => $driver instanceof IIdentities);
	}

	public function IsActive() : bool
	{
		return true;
	}

	/**
	 * @param Account $account
	 * @param bool $allowMultipleIdentities
	 * @return Identity[]
	 */
	public function GetIdentities(Account $account, bool $allowMultipleIdentities): array
	{
		// Find all identities stored in the system
		$identities = $this->MergeIdentitiesPerDriver($this->GetIdentiesPerDriver($account));

		// Find the primary identity
		$primaryIdentity = \array_find($identities, fn($identity) => $identity->IsAccountIdentities());

		// If no primary identity is found, generate default one from account info
		if (!$primaryIdentity) {
			$primaryIdentity = new Identity('', $account->Email());
			$primaryIdentity->exists = !\RainLoop\Api::Config()->Get('webmail', 'popup_identity', true);
			$identities[] = $primaryIdentity;
		}

		// An additional account added with a name sends as that name until its identity gets one of its own
		if ('' === $primaryIdentity->Name() && '' !== $account->Name()) {
			$primaryIdentity->SetName($account->Name());
		}

		// Return only primary identity or all identities
		return $allowMultipleIdentities ? $identities : [$primaryIdentity];
	}

	public function UpdateIdentity(Account $account, Identity $identity)
	{
		// Find all identities in the system
		$identities = &$this->GetIdentiesPerDriver($account);

		$isNew = true;
		foreach ($this->drivers as $driver) {
			if (!$driver->SupportsStore()) continue;

			$driverIdentities = &$identities[$driver->Name()];
			if (!isset($driverIdentities[$identity->Id(true)]))
				continue;

			// We update the identity in all writeable stores
			$driverIdentities[$identity->Id(true)] = $identity;
			$isNew = false;

			$driver->SetIdentities($account, $driverIdentities);
		}

		// If it is a new identity we add it to any storage driver
		if ($isNew) {
			// Pick any storage driver to store the result, typically only file storage
			$storageDriver = \array_find($this->drivers, fn($driver) => $driver->SupportsStore());

			$identities[$storageDriver->Name()][$identity->Id(true)] = $identity;
			$storageDriver->SetIdentities($account, $identities[$storageDriver->Name()]);
		}
	}

	public function DeleteIdentity(Account $account, string $identityId)
	{
		// On deletion, we remove the identity from all drivers if they are writeable.
		$identities = &$this->GetIdentiesPerDriver($account);

		foreach ($this->drivers as $driver) {
			if (!$driver->SupportsStore()) continue;

			$driverIdentities = &$identities[$driver->Name()];
			if (!isset($driverIdentities[$identityId]))
				continue;

			// We found it, so remove and update storage if relevant
			$identity = $driverIdentities[$identityId];
			if ($identity->IsAccountIdentities()) continue; // never remove the primary identity

			unset($driverIdentities[$identityId]);
			$driver->SetIdentities($account, $driverIdentities);
		}
	}

	private function &GetIdentiesPerDriver(Account $account): array
	{
		if (isset($this->identitiesPerDriverPerAccount[$account->Email()]))
			return $this->identitiesPerDriverPerAccount[$account->Email()];

		$identitiesPerDriver = [];
		foreach ($this->drivers as $driver) {
			$driverIdentities = $driver->GetIdentities($account);

			foreach ($driverIdentities as $identity)
				$identitiesPerDriver[$driver->Name()][$identity->Id(true)] = $identity;
		}

		$this->identitiesPerDriverPerAccount[$account->Email()] = $identitiesPerDriver;
		return $this->identitiesPerDriverPerAccount[$account->Email()];
	}

	/**
	 * @param Identity[][] $identitiesPerDriver
	 * @return Identity[]
	 */
	private function MergeIdentitiesPerDriver(array $identitiesPerDriver): array
	{
		// Merge logic for the identities
		$identities = [];
		foreach ($this->drivers as $driver) {
			// Merge and replace by key
			if (isset($identitiesPerDriver[$driver->Name()])) {
				foreach ($identitiesPerDriver[$driver->Name()] as $identity) {
					$identities[$identity->Id(true)] = $identity;
				}
			}
		}

		return \array_values($identities);
	}
}
