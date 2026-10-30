<?php

namespace SnappyMail;

abstract class Nextcloud
{
	public static function themes(): array
	{
		$themes = [];
		$dir = static::themesDir();
		if ($dir && ($rDirH = \opendir($dir))) {
			while (($name = \readdir($rDirH)) !== false) {
				if ('.' !== $name[0] && \is_dir("{$dir}/{$name}") && \file_exists("{$dir}/{$name}/snappymail/style.css")) {
					$themes[] = $name . '@nextcloud';
				}
			}
			\closedir($rDirH);
		}
		return $themes;
	}

	public static function themesDir(): ?string
	{
		if (\class_exists('OC', false)) {
			$dir = \OC::$SERVERROOT . '/themes'; // custom user themes
			if (\is_dir($dir)) {
				return $dir;
			}
		}
		return null;
	}

	public static function webRoot(): ?string
	{
		return \class_exists('OC', false) ? \OC::$WEBROOT : null;
	}

	private static
		$levels = [
			\LOG_EMERG   => 'EMERGENCY',
			\LOG_ALERT   => 'ALERT',
			\LOG_CRIT    => 'CRITICAL',
			\LOG_ERR     => 'ERROR',
			\LOG_WARNING => 'WARNING',
			\LOG_NOTICE  => 'NOTICE',
			\LOG_INFO    => 'INFO',
			\LOG_DEBUG   => 'DEBUG',
		];

	public static function log(int $level, string $msg): void
	{
		// https://www.php-fig.org/psr/psr-3/#3-psrlogloggerinterface
		if (\class_exists('OCP\Log\logger')) {
//			\OCP\Log\logger('snappymail')->log($level, $msg);
			\OCP\Log\logger('snappymail')->{static::$levels[$level]}($msg);
		}
	}
}
