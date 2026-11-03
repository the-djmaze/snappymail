<?php

namespace OCA\SnappyMail;

class ContentSecurityPolicy extends \OCP\AppFramework\Http\ContentSecurityPolicy
{
	private string $nonce;

	function __construct()
	{
		$CSP = \RainLoop\Api::getCSP();

		foreach ($CSP->get('script-src') as $domain) {
			// Knockout's legacy binding parser still evaluates binding strings.
			// Keep unsafe-eval until those bindings have been migrated.
			if ("'unsafe-inline'" !== $domain) {
				$this->addAllowedScriptDomain($domain);
			}
		}

		if (\method_exists($this, 'useStrictDynamic')) {
			$this->useStrictDynamic(true);
		} else {
			$this->addAllowedScriptDomain("'strict-dynamic'");
		}

		foreach ($CSP->get('img-src') as $domain) {
			$this->addAllowedImageDomain($domain);
		}

		foreach ($CSP->get('style-src') as $domain) {
			if ("'unsafe-inline'" !== $domain) {
				$this->addAllowedStyleDomain($domain);
			}
		}

		foreach ($CSP->get('frame-src') as $domain) {
			$this->addAllowedFrameDomain($domain);
		}

		if (\method_exists($this, 'addReportTo')) {
			foreach ($CSP->report_to as $reportTo) {
				$this->addReportTo($reportTo);
			}
		}

		$this->nonce = \SnappyMail\UUID::generate();
		$this->addAllowedScriptDomain("'nonce-{$this->nonce}'");
	}

	public function getSnappyMailNonce()
	{
		return $this->nonce;
	}
}
