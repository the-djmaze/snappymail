<?php

namespace OCA\SnappyMail\Controller;

use OCA\SnappyMail\Util\SnappyMailHelper;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

class FetchController extends Controller
{
	private IConfig $config;
	private IL10N $l10n;

	public function __construct(string $appName, IRequest $request, \Psr\Container\ContainerInterface $container)
	{
		parent::__construct($appName, $request);
		$this->config = $container->query(IConfig::class);
		$this->l10n = $container->query(IL10N::class);
	}

	public function upgrade(): JSONResponse
	{
		$error = 'Upgrade failed';
		try {
			SnappyMailHelper::loadApp();
			if (\SnappyMail\Upgrade::core()) {
				return new JSONResponse([
					'status' => 'success',
					'Message' => $this->l10n->t('Upgraded successfully')
				]);
			}
		} catch (Exception $e) {
			$error .= ': ' . $e->getMessage();
		}
		return new JSONResponse([
			'status' => 'error',
			'Message' => $error
		]);
	}

	public function setAdmin(): JSONResponse
	{
		try {
			$sUrl = '';
			$sPath = '';

			if (isset($_POST['appname']) && 'snappymail' === $_POST['appname']) {
				$this->config->setAppValue('snappymail', 'snappymail-autologin',
					isset($_POST['snappymail-autologin']) ? '1' === $_POST['snappymail-autologin'] : false);
				$this->config->setAppValue('snappymail', 'snappymail-autologin-with-email',
					isset($_POST['snappymail-autologin']) ? '2' === $_POST['snappymail-autologin'] : false);
				$this->config->setAppValue('snappymail', 'snappymail-no-embed', isset($_POST['snappymail-no-embed']));
				$this->config->setAppValue('snappymail', 'snappymail-autologin-oidc', isset($_POST['snappymail-autologin-oidc']));
			} else {
				return new JSONResponse([
					'status' => 'error',
					'Message' => $this->l10n->t('Invalid argument(s)')
				]);
			}

			SnappyMailHelper::loadApp();

			$oConfig = \RainLoop\Api::Config();
			if (!empty($_POST['snappymail-app_path'])) {
				$oConfig->Set('webmail', 'app_path', $_POST['snappymail-app_path']);
			}
			$oConfig->Set('webmail', 'allow_languages_on_settings', empty($_POST['snappymail-nc-lang']));
			$oConfig->Set('login', 'allow_languages_on_login', empty($_POST['snappymail-nc-lang']));
			$oConfig->Save();

			if (!empty($_POST['import-rainloop'])) {
				return new JSONResponse([
					'status' => 'success',
					'Message' => \implode("\n", \OCA\SnappyMail\Util\RainLoop::import())
				]);
			}

			$debug = !empty($_POST['snappymail-debug']);
			$oConfig = \RainLoop\Api::Config();
			if ($debug != $oConfig->Get('debug', 'enable', false)) {
				$oConfig->Set('debug', 'enable', $debug);
				$oConfig->Save();
			}

			return new JSONResponse([
				'status' => 'success',
				'Message' => $this->l10n->t('Saved successfully')
			]);
		} catch (Exception $e) {
			return new JSONResponse([
				'status' => 'error',
				'Message' => $e->getMessage()
			]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function setPersonal(): JSONResponse
	{
		try {
			$sEmail = '';
			if (isset($_POST['appname'], $_POST['snappymail-password'], $_POST['snappymail-email']) && 'snappymail' === $_POST['appname']) {
				$sUser = SnappyMailHelper::getUID();
//				$sUser = $this->userSession->getUser()?->getUID();
				if ($sUser) {
					$sEmail = $_POST['snappymail-email'];
					$this->config->setUserValue($sUser, 'snappymail', 'snappymail-email', $sEmail);

					$sPass = $_POST['snappymail-password'];
					if ('******' !== $sPass) {
						$this->config->setUserValue($sUser, 'snappymail', 'passphrase',
							$sPass ? SnappyMailHelper::encodePassword($sPass, \md5($sEmail)) : '');
					}
				}
			} else {
				return new JSONResponse([
					'status' => 'error',
					'Message' => $this->l10n->t('Invalid argument(s)'),
					'Email' => $sEmail
				]);
			}

			// Logout as the credentials have changed
			SnappyMailHelper::loadApp();
			\RainLoop\Api::Actions()->DoLogout();

			return new JSONResponse([
				'status' => 'success',
				'Message' => $this->l10n->t('Saved successfully'),
				'Email' => $sEmail
			]);
		} catch (Exception $e) {
			// Logout as the credentials might have changed, as exception could be in one attribute
			// TODO: Handle both exceptions separately?
			SnappyMailHelper::loadApp();
			\RainLoop\Api::Actions()->DoLogout();

			return new JSONResponse([
				'status' => 'error',
				'Message' => $e->getMessage()
			]);
		}
	}
}

