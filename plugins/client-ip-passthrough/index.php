<?php

/**
 * Client IP Passthrough Plugin for SnappyMail
 *
 * This plugin passes the logged-in user's real IP address to SMTP and IMAP servers.
 * It intelligently detects the client IP through various proxy headers and:
 * - Modifies SMTP EHLO message to include the client IP (e.g., EHLO [192.168.1.100])
 * - Sends IMAP ID command (RFC 2971) with the client IP after login
 *
 * IP Detection Priority:
 * 1. CF-Connecting-IP (Cloudflare)
 * 2. X-Real-IP (Nginx)
 * 3. X-Forwarded-For (Standard proxy - first IP)
 * 4. HTTP_CLIENT_IP (Less common proxy)
 * 5. REMOTE_ADDR (Direct connection)
 */
class ClientIpPassthroughPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME        = 'Client IP Passthrough',
		AUTHOR      = 'SnappyMail Community',
		URL         = 'https://github.com/the-djmaze/snappymail',
		VERSION     = '1.0',
		RELEASE     = '2025-01-28',
		REQUIRED    = '2.36.0',
		CATEGORY    = 'Network',
		LICENSE     = 'MIT',
		DESCRIPTION = 'Passes the client\'s real IP address to SMTP and IMAP servers for logging and security purposes';

	/**
	 * Initialize the plugin and register hooks
	 */
	public function Init() : void
	{
		// Hook into SMTP connection to modify EHLO message
		$this->addHook('smtp.before-connect', 'ModifySmtpEhlo');

		// Hook into IMAP after-login to send ID command
		$this->addHook('imap.after-login', 'SendImapIdCommand');
	}

	/**
	 * Configure plugin settings
	 */
	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('enable_smtp')
				->SetLabel('Enable SMTP IP Passthrough')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Modify SMTP EHLO message to include client IP address')
				->SetDefaultValue(true),

			\RainLoop\Plugins\Property::NewInstance('enable_imap')
				->SetLabel('Enable IMAP IP Passthrough')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Send IMAP ID command with client IP address after login')
				->SetDefaultValue(true),

			\RainLoop\Plugins\Property::NewInstance('trust_proxies')
				->SetLabel('Trust Proxy Headers')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Enable detection of client IP from proxy headers (CF-Connecting-IP, X-Real-IP, X-Forwarded-For). Only enable if behind a trusted reverse proxy.')
				->SetDefaultValue(true),

			\RainLoop\Plugins\Property::NewInstance('ipv6_support')
				->SetLabel('IPv6 Support')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDescription('Enable IPv6 address support (formats IPv6 addresses correctly for EHLO)')
				->SetDefaultValue(true)
		);
	}

	/**
	 * Detect the client's real IP address with priority order
	 *
	 * @return string Client IP address or 'unknown' if not detectable
	 */
	private function getClientIp() : string
	{
		$trustProxies = $this->Config()->Get('plugin', 'trust_proxies', true);

		// If we don't trust proxies, only use REMOTE_ADDR
		if (!$trustProxies) {
			return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		}

		// Priority 1: Cloudflare's CF-Connecting-IP header
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			return $this->cleanIpAddress($_SERVER['HTTP_CF_CONNECTING_IP']);
		}

		// Priority 2: Nginx's X-Real-IP header
		if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
			return $this->cleanIpAddress($_SERVER['HTTP_X_REAL_IP']);
		}

		// Priority 3: X-Forwarded-For header (use first IP in chain)
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ips = \explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			return $this->cleanIpAddress(\trim($ips[0]));
		}

		// Priority 4: HTTP_CLIENT_IP header (less common)
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			return $this->cleanIpAddress($_SERVER['HTTP_CLIENT_IP']);
		}

		// Priority 5: Direct connection via REMOTE_ADDR
		if (!empty($_SERVER['REMOTE_ADDR'])) {
			return $this->cleanIpAddress($_SERVER['REMOTE_ADDR']);
		}

		return 'unknown';
	}

	/**
	 * Clean and validate IP address
	 *
	 * @param string $ip Raw IP address
	 * @return string Cleaned IP address
	 */
	private function cleanIpAddress(string $ip) : string
	{
		$ip = \trim($ip);

		// Remove port if present (e.g., "192.168.1.1:8080" or "[::1]:8080")
		$ip = \preg_replace('/:\d+$/', '', $ip);

		// Remove brackets from IPv6 if present
		$ip = \trim($ip, '[]');

		return $ip;
	}

	/**
	 * Format IP address for SMTP EHLO message
	 *
	 * @param string $ip IP address
	 * @return string Formatted IP for EHLO (IPv4 wrapped in brackets, IPv6 as-is with brackets)
	 */
	private function formatIpForEhlo(string $ip) : string
	{
		if ($ip === 'unknown') {
			return 'localhost';
		}

		// Check if IPv6 (contains colons)
		if (\strpos($ip, ':') !== false) {
			if (!$this->Config()->Get('plugin', 'ipv6_support', true)) {
				return 'localhost';
			}
			// IPv6 addresses in EHLO should be in brackets
			return '[' . $ip . ']';
		}

		// IPv4 addresses should be wrapped in brackets for EHLO
		// e.g., EHLO [192.168.1.100]
		return '[' . $ip . ']';
	}

	/**
	 * Hook: Modify SMTP EHLO message to include client IP
	 *
	 * @param \RainLoop\Model\Account $oAccount
	 * @param \MailSo\Smtp\SmtpClient $oSmtpClient
	 * @param array $aSmtpCredentials
	 */
	public function ModifySmtpEhlo(\RainLoop\Model\Account $oAccount,
		\MailSo\Smtp\SmtpClient $oSmtpClient,
		array &$aSmtpCredentials) : void
	{
		// Check if SMTP passthrough is enabled
		if (!$this->Config()->Get('plugin', 'enable_smtp', true)) {
			return;
		}

		$clientIp = $this->getClientIp();

		// Only modify EHLO if we successfully detected an IP
		if ($clientIp !== 'unknown') {
			$aSmtpCredentials['Ehlo'] = $this->formatIpForEhlo($clientIp);

			// Log the modification if logging is enabled
			if ($this->Manager()->Actions()->Logger()) {
				$this->Manager()->Actions()->Logger()->Write(
					'Client IP Passthrough: SMTP EHLO set to ' . $aSmtpCredentials['Ehlo'] . ' for ' . $oAccount->Email(),
					\LOG_INFO,
					'PLUGIN'
				);
			}
		}
	}

	/**
	 * Hook: Send IMAP ID command with client IP after login
	 *
	 * @param \RainLoop\Model\Account $oAccount
	 * @param \MailSo\Imap\ImapClient $oImapClient
	 * @param bool $bLoginResult
	 * @param \MailSo\Imap\Settings $oSettings
	 */
	public function SendImapIdCommand(\RainLoop\Model\Account $oAccount,
		\MailSo\Imap\ImapClient $oImapClient,
		bool $bLoginResult,
		\MailSo\Imap\Settings $oSettings) : void
	{
		// Only proceed if IMAP passthrough is enabled and login was successful
		if (!$this->Config()->Get('plugin', 'enable_imap', true) || !$bLoginResult) {
			return;
		}

		// Check if server supports ID command (RFC 2971)
		if (!$oImapClient->hasCapability('ID')) {
			return;
		}

		$clientIp = $this->getClientIp();

		// Only send ID command if we successfully detected an IP
		if ($clientIp === 'unknown') {
			return;
		}

		try {
			// Build ID parameters array per RFC 2971
			// Format: ID ("name" "SnappyMail" "version" "2.x" "client-ip" "192.168.1.100")
			$idParams = [
				'name', 'SnappyMail',
				'version', defined('APP_VERSION') ? APP_VERSION : '2.x',
				'client-ip', $clientIp
			];

			// Send ID command
			// The IMAP ID command format is: ID (<key> <value> <key> <value> ...)
			$oImapClient->SendRequestGetResponse('ID', [$idParams]);

			// Log the ID command if logging is enabled
			if ($this->Manager()->Actions()->Logger()) {
				$this->Manager()->Actions()->Logger()->Write(
					'Client IP Passthrough: IMAP ID sent with client-ip=' . $clientIp . ' for ' . $oAccount->Email(),
					\LOG_INFO,
					'PLUGIN'
				);
			}
		} catch (\Throwable $oException) {
			// Log error but don't fail the login
			if ($this->Manager()->Actions()->Logger()) {
				$this->Manager()->Actions()->Logger()->Write(
					'Client IP Passthrough: Failed to send IMAP ID command: ' . $oException->getMessage(),
					\LOG_WARNING,
					'PLUGIN'
				);
			}
		}
	}
}
