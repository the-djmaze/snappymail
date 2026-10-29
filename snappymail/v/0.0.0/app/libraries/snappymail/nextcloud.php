<?php

namespace SnappyMail;

/**
 * Nextcloud 34+
 * @CarlSchwan 2026-03-10
 * refactor: remove long deprecated IServerContainer methods
 *
 * https://github.com/nextcloud/server/commit/39c14c383b698467711a2de09870d7c032d92f63
 */
abstract class Nextcloud
{

	public static function themesDir(): ?string
	{
		if (\class_exists('OC', false)) {
			$sDir = \OC::$SERVERROOT . '/themes'; // custom user themes
			if (\is_dir($sDir)) {
				return $sDir;
			}
		}
		return null;
	}

	public static function webRoot(): ?string
	{
		return \class_exists('OC', false) ? \OC::$WEBROOT : null;
	}

	public static function getConfig(): object
	{
		return \method_exists(\OC::$server, 'getConfig')
			? \OC::$server->getConfig()
			// Nextcloud 34+
			: \OC::$server->get(\OCP\IAppConfig);
	}

	public static function getSystemConfigValue(string $key, mixed $default = null): mixed
	{
		return \method_exists(\OC::$server, 'getSystemConfig')
			? \OC::$server->getSystemConfig()->getValue($key, $default)
			// Nextcloud 34+
			: \OC::$server->get(\OCP\IConfig::class)->getSystemValue($key, $default);
	}

	public static function getUserSession(): mixed
	{
		return \method_exists(\OC::$server, 'getUserSession')
			? \OC::$server->getUserSession()
			// Nextcloud 34+
			: \OC::$server->get(\OCP\IUserSession::class);
	}

	public static function setUserSessionValue(string $key, mixed $value): void
	{
		if (\method_exists(\OC::$server, 'getUserSession')) {
			\OC::$server->getUserSession()[$key] = $value;
		} else {
			// Nextcloud 34+
			\OC::$server->get(\OCP\IUserSession::class)->getSession()->set($key, $value);
		}
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
