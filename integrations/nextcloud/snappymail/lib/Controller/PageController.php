<?php

namespace OCA\SnappyMail\Controller;

use OCA\SnappyMail\Util\SnappyMailHelper;
use OCA\SnappyMail\ContentSecurityPolicy;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\INavigationManager;
use OCP\IConfig;
use OCP\IRequest;

class PageController extends Controller
{
	private IConfig $config;
	private INavigationManager $navigationManager;

	public function __construct($appName, IRequest $request, IConfig $config, INavigationManager $navigationManager) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->navigationManager = $navigationManager;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index()
	{
		$bAdmin = false;
		if (!empty($_SERVER['QUERY_STRING'])) {
			SnappyMailHelper::loadApp();
			$bAdmin = \RainLoop\Api::Config()->Get('security', 'admin_panel_key', 'admin') == $_SERVER['QUERY_STRING'];
			if (!$bAdmin) {
				return SnappyMailHelper::startApp(true);
			}
		}

		if (!$bAdmin && $this->config->getAppValue('snappymail', 'snappymail-no-embed')) {
			$this->navigationManager->setActiveEntry('snappymail');
			SnappyMailHelper::startApp();
			$response = new TemplateResponse('snappymail', 'index', [
				'snappymail-iframe-url' => SnappyMailHelper::normalizeUrl(SnappyMailHelper::getAppUrl())
					. (empty($_GET['target']) ? '' : "#{$_GET['target']}")
			]);
			$csp = new ContentSecurityPolicy();
			$csp->addAllowedFrameDomain("'self'");
//			$csp->addAllowedFrameAncestorDomain("'self'");
			$response->setContentSecurityPolicy($csp);
			return $response;
		}

		$this->navigationManager->setActiveEntry('snappymail');

		SnappyMailHelper::startApp();
		$oConfig = \RainLoop\Api::Config();
		$oActions = $bAdmin ? new \RainLoop\ActionsAdmin() : \RainLoop\Api::Actions();
		$oHttp = \MailSo\Base\Http::SingletonInstance();
		$oServiceActions = new \RainLoop\ServiceActions($oHttp, $oActions);
		$sAppJsMin = $oConfig->Get('debug', 'javascript', false) ? '' : '.min';
		$sAppCssMin = $oConfig->Get('debug', 'css', false) ? '' : '.min';
		$sLanguage = $oActions->GetLanguage(false);

		$csp = new ContentSecurityPolicy();
		$sNonce = $csp->getSnappyMailNonce();

		$cssLink = \RainLoop\Utils::WebStaticPath('css/'.($bAdmin?'admin':'app').$sAppCssMin.'.css');

		$params = [
			'Admin' => $bAdmin ? 1 : 0,
			'LoadingDescriptionEsc' => \htmlspecialchars($oConfig->Get('webmail', 'loading_description', 'SnappyMail'), ENT_QUOTES|ENT_IGNORE, 'UTF-8'),
			'BaseTemplates' => \RainLoop\Utils::ClearHtmlOutput($oServiceActions->compileTemplates($bAdmin)),
			'BaseAppBootScript' => \file_get_contents(APP_VERSION_ROOT_PATH.'static/js'.($sAppJsMin ? '/min' : '').'/boot'.$sAppJsMin.'.js'),
			'BaseAppBootScriptNonce' => $sNonce,
			'BaseLanguage' => $oActions->compileLanguage($sLanguage, $bAdmin),
			'BaseAppBootCss' => \file_get_contents(APP_VERSION_ROOT_PATH.'static/css/boot'.$sAppCssMin.'.css'),
			'BaseAppThemeCss' => \preg_replace(
				'/\\s*([:;{},]+)\\s*/s',
				'$1',
				$oActions->compileCss($oActions->GetTheme($bAdmin), $bAdmin)
			),
			'CssLink' => $cssLink
		];

		$response = new TemplateResponse('snappymail', 'index_embed', $params);

		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function appGet()
	{
		return SnappyMailHelper::startApp(true);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function appPost()
	{
		return SnappyMailHelper::startApp(true);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function indexPost()
	{
		return SnappyMailHelper::startApp(true);
	}
}
