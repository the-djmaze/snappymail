<?php
/**
 * Domain name is handled in lowercase punycode.
 * Because internationalized domain names can have uppercase or titlecase characters.
 */

namespace RainLoop\Model;

use MailSo\Net\Enumerations\ConnectionSecurityType;

class Domain implements \JsonSerializable
{
	private string $Name;

	private \MailSo\Imap\Settings $IMAP;

	private \MailSo\Smtp\Settings $SMTP;

	private \MailSo\Sieve\Settings $Sieve;

	private string $whiteList = '';

	private string $aliasName = '';

	function __construct(string $sName)
	{
//		$this->Name = \SnappyMail\IDN::toAscii($sName);
		$this->Name = \strtolower(\idn_to_ascii($sName));
		$this->IMAP = new \MailSo\Imap\Settings;
		$this->SMTP = new \MailSo\Smtp\Settings;
		$this->Sieve = new \MailSo\Sieve\Settings;
	}

	private function Normalize()
	{
		$this->IMAP->host = \trim($this->IMAP->host);
		$this->Sieve->host = \trim($this->Sieve->host);
		$this->SMTP->host = \trim($this->SMTP->host);
		$this->whiteList = \trim($this->whiteList);
	}

	public function Name(): string
	{
		return $this->Name;
	}

	public function SetAliasName(string $sAliasName): void
	{
//		$this->aliasName = \SnappyMail\IDN::toAscii($sAliasName);
		$this->aliasName = \strtolower(\idn_to_ascii($sAliasName));
	}

	public function ValidateWhiteList(string $sEmail): bool
	{
		$sW = $this->whiteList;
		if (!$sW) {
			return true;
		}
		$sEmail = \SnappyMail\IDN::emailToAscii(\mb_strtolower($sEmail));
		$iPos = \strrpos($sEmail, '@');
		$sUserPart = \substr($sEmail, 0, $iPos);
		$sUserDomain = \substr($sEmail, $iPos);
		$sItem = \strtok($sW, " ;,\n");
		while (false !== $sItem) {
			$sItem = \SnappyMail\IDN::emailToAscii(\mb_strtolower(\trim($sItem)));
			if ($sItem === $sEmail || $sItem === $sUserPart || $sItem === $sUserDomain) {
				return true;
			}
			$sItem = \strtok(" ;,\n");
		}
		return false;
	}

	public function ImapSettings(): \MailSo\Imap\Settings
	{
		return $this->IMAP;
	}

	public function SieveSettings(): \MailSo\Sieve\Settings
	{
		return $this->Sieve;
	}

	public function SmtpSettings(): \MailSo\Smtp\Settings
	{
		return $this->SMTP;
	}

	/**
	 * See jsonSerialize() for valid values
	 */
	public static function fromArray(string $sName, array $aDomain): ?self
	{
		if (!\strlen($sName)) {
			return null;
		}
		$oDomain = new self($sName);
		if (!empty($aDomain['IMAP'])) {
			$oDomain->IMAP = \MailSo\Imap\Settings::fromArray($aDomain['IMAP']);
			$oDomain->SMTP = \MailSo\Smtp\Settings::fromArray($aDomain['SMTP']);
			$oDomain->Sieve = \MailSo\Sieve\Settings::fromArray($aDomain['Sieve']);
			$oDomain->whiteList = (string) $aDomain['whiteList'];
		} else if (\strlen($aDomain['imapHost'])) {
			// Old way
			$oDomain->IMAP = \MailSo\Imap\Settings::fromArray([
				'host' => $aDomain['imapHost'],
				'port' => (int) $aDomain['imapPort'],
				'type' => (int) $aDomain['imapSecure'],
				'shortLogin' => !empty($aDomain['imapShortLogin'])
			]);
			$oDomain->SMTP = \MailSo\SMTP\Settings::fromArray([
				'host' => $aDomain['smtpHost'],
				'port' => (int) $aDomain['smtpPort'],
				'type' => (int) $aDomain['smtpSecure'],
				'shortLogin' => !empty($aDomain['smtpShortLogin']),
				'useAuth' => !empty($aDomain['smtpAuth']),
				'setSender' => !empty($aDomain['smtpSetSender']),
				'authPlainLine' => !empty($aDomain['smtpAuthPlainLine']),
				'usePhpMail' => !empty($aDomain['smtpPhpMail'])
			]);
			$oDomain->Sieve = \MailSo\Sieve\Settings::fromArray([
				'enabled' => $aDomain['useSieve'],
				'host' => $aDomain['sieveHost'],
				'port' => (int) $aDomain['sievePort'],
				'type' => (int) $aDomain['sieveSecure']
			]);
			$oDomain->whiteList = (string) $aDomain['whiteList'];
		} else {
			return null;
		}
		$oDomain->Normalize();
		return $oDomain;
	}

	/**
	 * Used by old RainLoop ToIniString()
	 */
	public static function fromIniArray(string $sName, array $aDomain): ?self
	{
		if (!\strlen($sName) || empty($aDomain['imap_host'])) {
			return null;
		}
		return static::fromArray([
			'IMAP' => [
				'host' => $aDomain['imap_host'],
				'port' => (int) $aDomain['imap_port'],
				'type' => self::StrToConnectionSecurityType($aDomain['imap_secure'] ?? ''),
				'shortLogin' => !empty($aDomain['imap_short_login'])
			],
			'SMTP' => [
				'host' => $aDomain['smtp_host'],
				'port' => (int) ($aDomain['smtp_port'] ?? 25),
				'type' => self::StrToConnectionSecurityType($aDomain['smtp_secure'] ?? ''),
				'shortLogin' => !empty($aDomain['smtp_short_login']),
				'useAuth' => !empty($aDomain['smtp_auth']),
				'setSender' => !empty($aDomain['smtp_set_sender']),
				'usePhpMail' => !empty($aDomain['smtp_php_mail'])
			],
			'Sieve' => [
				'enabled' => !empty($aDomain['sieve_use']),
				'host' => $aDomain['sieve_host'] ?: '',
				'port' => (int) ($aDomain['sieve_port'] ?? 4190),
				'type' => self::StrToConnectionSecurityType($aDomain['sieve_secure'] ?? '')
			],
			'whiteList' => $aDomain['white_list'] ?? '',
		]);
	}

	/**
	 * Use by old RainLoop fromIniArray()
	 */
	private static function StrToConnectionSecurityType(string $sType): ConnectionSecurityType
	{
		switch (\strtoupper($sType))
		{
			case 'SSL':
				return ConnectionSecurityType::SSL;
			case 'TLS':
				return ConnectionSecurityType::STARTTLS;
		}
		return ConnectionSecurityType::NONE;
	}

//	public function jsonSerialize(): array
	public function jsonSerialize(): mixed
	{
		$aResult = array(
//			'@Object' => 'Object/Domain',
			'IMAP' => $this->IMAP,
			'SMTP' => $this->SMTP,
			'Sieve' => $this->Sieve,
			'whiteList' => $this->whiteList
		);
		if ($this->aliasName) {
			$aResult['aliasName'] = $this->aliasName;
		}
		return $aResult;
	}
}
