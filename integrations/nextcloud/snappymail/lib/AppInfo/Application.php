<?php

namespace OCA\SnappyMail\AppInfo;

use OCA\SnappyMail\Util\SnappyMailHelper;
use OCA\SnappyMail\Controller\FetchController;
use OCA\SnappyMail\Controller\PageController;
use OCA\SnappyMail\Search\Provider;
use OCA\SnappyMail\Listeners\AccessTokenUpdatedListener;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\IL10N;
use OCP\IUser;
use OCP\User\Events\PostLoginEvent;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use OCA\OIDCLogin\Events\AccessTokenUpdatedEvent;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserSession;
use OCP\INavigationManager;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'snappymail';

	public function __construct(array $urlParams = [])
	{
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void
	{
		/**
		 * Controllers
		 */
		$context->registerService(
			'PageController', function($c) {
				return new PageController(
					$c->query('AppName'),
					$c->query('Request'),
					$c->query(IConfig::class),
					$c->query(INavigationManager::class)
				);
			}
		);

		$context->registerService(
			'FetchController', function($c) {
				return new FetchController(
					$c->query('AppName'),
					$c->query('Request'),
					$c->query('ServerContainer')->getAppManager(),
					$c->query('ServerContainer')->getConfig(),
					$c->query(IL10N::class),
					$c->query(IUserSession::class)
				);
			}
		);

		/**
		 * Utils
		 */
		$context->registerService(
			'SnappyMailHelper', function($c) {
				return new SnappyMailHelper();
			}
		);

		$context->registerSearchProvider(Provider::class);
		$context->registerEventListener(AccessTokenUpdatedEvent::class, AccessTokenUpdatedListener::class);

		// TODO: Not working yet, needs a Vue UI
//		$context->registerDashboardWidget(UnreadMailWidget::class);
	}

	public function boot(IBootContext $context): void
	{
		$config = $context->getServerContainer()->getConfig();
		if (!\is_dir(\rtrim(\trim($config->getSystemValue('datadirectory', '')), '\\/') . '/appdata_snappymail')) {
			return;
		}

		$dispatcher = $context->getAppContainer()->query('OCP\EventDispatcher\IEventDispatcher');
		$dispatcher->addListener(PostLoginEvent::class, function (PostLoginEvent $Event) use ($context) {
/*
			$config = $context->getServerContainer()->getConfig();
			// Only store the user's password in the current session if they have
			// enabled auto-login using Nextcloud username or email address.
			if ($config->getAppValue('snappymail', 'snappymail-autologin', false)
			 || $config->getAppValue('snappymail', 'snappymail-autologin-with-email', false)) {
*/
				$sUID = $Event->getUser()->getUID();
				$session = $context->getServerContainer()->getSession();
				$session['snappymail-nc-uid'] = $sUID;
				$session['snappymail-passphrase'] = SnappyMailHelper::encodePassword($Event->getPassword(), $sUID);
/*
			}
*/
		});

		$dispatcher->addListener(BeforeUserLoggedOutEvent::class, function (BeforeUserLoggedOutEvent $Event) {
			// https://github.com/nextcloud/server/issues/36083#issuecomment-1387370634
//			\OC::$server->getSession()['snappymail-passphrase'] = '';
			SnappyMailHelper::loadApp();
//			\RainLoop\Api::Actions()->Logout(true);
			\RainLoop\Api::Actions()->DoLogout();
		});

		// https://github.com/nextcloud/impersonate/issues/179
		// https://github.com/nextcloud/impersonate/pull/180
		$class = 'OCA\Impersonate\Events\BeginImpersonateEvent';
		if (\class_exists($class)) {
			$dispatcher->addListener($class, function ($Event) use ($context) {
				$context->getServerContainer()->getSession()['snappymail-passphrase'] = '';
				SnappyMailHelper::loadApp();
				\RainLoop\Api::Actions()->Logout(true);
			});
			$dispatcher->addListener('OCA\Impersonate\Events\EndImpersonateEvent', function ($Event) use ($context) {
				$context->getServerContainer()->getSession()['snappymail-passphrase'] = '';
				SnappyMailHelper::loadApp();
				\RainLoop\Api::Actions()->Logout(true);
			});
		}
	}
}
