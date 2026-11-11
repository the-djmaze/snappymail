<?php

namespace SnappyMail\PGP;

\defined('GNUPG_SIG_MODE_NORMAL') || \define('GNUPG_SIG_MODE_NORMAL', 0);
\defined('GNUPG_SIG_MODE_DETACH') || \define('GNUPG_SIG_MODE_DETACH', 1);
\defined('GNUPG_SIG_MODE_CLEAR') || \define('GNUPG_SIG_MODE_CLEAR', 2);

use SnappyMail\GPG\PGP as GPG;

abstract class GnuPG
{
	public static function isSupported() : bool
	{
		return GPG::isSupported() || PECL::isSupported();
	}

	private static $instances = [];
	public static function getInstance(string $homedir) : ?PGPInterface
	{
		if (!\array_key_exists($homedir, static::$instances)) {
			if (GPG::isSupported()) {
				static::$instances[$homedir] = new GPG($homedir);
			}
			else if (PECL::isSupported()) {
				static::$instances[$homedir] = new PECL($homedir);
			} else {
				static::$instances[$homedir] = null;
			}
		}
		return static::$instances[$homedir];
	}
}
