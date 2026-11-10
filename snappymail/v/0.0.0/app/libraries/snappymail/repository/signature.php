<?php

namespace SnappyMail\Repository;

use SnappyMail\PGP\GnuPG;
use SnappyMail\PGP\Keyservers;

abstract class Signature
{
	const KEY_URL = 'https://snappymail.eu/repository/releases.asc';
	const KEY_SUM = 'bfa5df5ab2e782940f6b2eede9e62b5b7a44415b';
	// Long Key ID is the last 16 bytes of the fingerprint
	const KEY_FINGERPRINT = '1016E47079145542F8BA133548208BA13290F3EB';

	const SSL_URL = 'https://snappymail.eu/repository/releases.pub';
	const SSL_SUM = 'e5ecc30b52fa64012b15f7cec3c04f7bd083193e';

	public static function verifyPGP(string $data, string $signature): void
	{
		if (!\is_readable($data)) {
			throw new \ValueError('$data is not a file');
		}
		if (!$signature) {
			throw new \ValueError('Plugin PGP signature is missing');
		}

		// Keep the repository verification keyring in SnappyMail's data directory,
		// rather than relying on the webserver process's HOME.
		$homedir = APP_PRIVATE_DATA . 'gnupg/repository';
		if (!\is_dir($homedir) && !\mkdir($homedir, 0700, true) && !\is_dir($homedir)) {
			throw new \RuntimeException("Could not create GnuPG home '{$homedir}'");
		}
		if ((\fileperms($homedir) & 0777) !== 0700 && !\chmod($homedir, 0700)) {
			throw new \RuntimeException("Could not set permissions on GnuPG home '{$homedir}'");
		}
		if (!\is_writable($homedir)) {
			throw new \RuntimeException("GnuPG home '{$homedir}' is not writable");
		}

		// Like: gpg --no-default-keyring --keyring /path/to/pubkey.gpg --verify /path/to/file.txt.gpg
		$gnupg = GnuPG::getInstance($homedir);
		if (!$gnupg) {
			throw new \RuntimeException('GnuPG is not supported');
		}
		static::importKey($gnupg);
		$result = $gnupg->verifyFile($data, $signature);

		if (!empty($result[0]['keyid'])) {
			// Long Key ID is the last 16 bytes of the fingerprint
			if (\substr(static::KEY_FINGERPRINT, -16) !== $result[0]['keyid']) {
				throw new \RuntimeException("Plugin PGP signature wrong key: {$result[0]['keyid']}");
			}
		} else if (empty($result[0]['fingerprint'])) {
			throw new \RuntimeException('Plugin PGP signature invalid');
		} else if (static::KEY_FINGERPRINT !== $result[0]['fingerprint']) {
			throw new \RuntimeException("Plugin PGP signature wrong key: {$result[0]['fingerprint']}");
		}
	}

	private static function importKey($gnupg): void
	{
		if (!\count($gnupg->keyInfo(static::KEY_FINGERPRINT))) {
			// Try local key
			$file = __DIR__ . '/releases.asc';
			if (\is_readable($file)) {
				if (static::KEY_SUM !== \sha1_file($file)) {
					throw new \Exception('Repository invalid PGP public key');
				}
				$gnupg->importFile($file);
			}
			if (!\count($gnupg->keyInfo(static::KEY_FINGERPRINT))) {
				// Try to fetch from WKD server
				// https://snappymail.eu/.well-known/openpgpkey/hu/i4cdqgcarfjdjnba6y4jnf498asg8c6p
				$sKey = Keyservers::wkd('releases@snappymail.eu');
				$sKey && $gnupg->import($sKey);
				// Try to fetch from HKPS server
				if (!\count($gnupg->keyInfo(static::KEY_FINGERPRINT))) {
					$sKey = Keyservers::get(static::KEY_FINGERPRINT);
					$sKey && $gnupg->import($sKey);
					if (!\count($gnupg->keyInfo(static::KEY_FINGERPRINT))) {
						throw new \Exception('Repository PGP public key is missing');
/*
						$aKeys = Keyservers::index('releases@snappymail.eu');
						if ($aKeys) {
							$sKey = Keyservers::get($aKeys[0]['keyid']);
						}
*/
					}
				}
			}
		}
	}

	// Verify SSL signature
	public static function verifySSL(string $data, string $signature): void
	{
		if (!\is_readable($data)) {
			throw new \ValueError('$data is not a file');
		}
		if (512 !== \strlen($signature)) {
			throw new \ValueError('Plugin SSL signature is missing');
		}

		$pkey = __DIR__ . '/releases.pub';
		if (!\is_readable($pkey) || static::SSL_SUM !== \sha1_file($pkey)) {
			throw new \Exception('Repository invalid SSL public key');
		}

		$result = \openssl_verify(
			\file_get_contents($data),
			$signature, // binary data
			\file_get_contents($pkey),
			\OPENSSL_ALGO_SHA256
		);
		if (1 === $result) {
			return;
		}
		if (0 === $result) {
			throw new \RuntimeException('Plugin SSL signature bad');
		}
		throw new \RuntimeException('Plugin SSL signature check failed');
	}

}
