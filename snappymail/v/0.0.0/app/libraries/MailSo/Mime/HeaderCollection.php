<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Mime;

/**
 * @category MailSo
 * @package Mime
 */
class HeaderCollection extends \MailSo\Base\Collection
{
	protected string $sParentCharset = '';

	function __construct(string $sRawHeaders = '', string $sParentCharset = '')
	{
		parent::__construct();
		if (\strlen($sRawHeaders)) {
			$this->Parse($sRawHeaders, $sParentCharset);
		}
	}

	public function append($oHeader, bool $bToTop = false): void
	{
		assert($oHeader instanceof Header);
		parent::append($oHeader, $bToTop);
	}

	public function AddByName(string $sName, string $sValue, bool $bToTop = false): self
	{
		$this->append(new Header($sName, $sValue), $bToTop);
		return $this;
	}

	public function SetByName(string $sName, string $sValue, bool $bToTop = false): self
	{
		return $this->RemoveByName($sName)->Add(new Header($sName, $sValue), $bToTop);
	}

	public function ValueByName(string $sHeaderName, bool $bCharsetAutoDetect = false): string
	{
		$oHeader = $this->GetByName($sHeaderName);
		return $oHeader ? ($bCharsetAutoDetect ? $oHeader->ValueWithCharsetAutoDetect() : $oHeader->Value()) : '';
	}

	public function ValuesByName(string $sHeaderName): array
	{
		$aResult = array();
		$sHeaderNameLower = \strtolower($sHeaderName);
		foreach ($this as $oHeader) {
			if ($sHeaderNameLower === \strtolower($oHeader->Name())) {
				$aResult[] = $oHeader->Value();
			}
		}
		return $aResult;
	}

	public function RemoveByName(string $sHeaderName): self
	{
		$sHeaderName = \strtolower($sHeaderName);
		$this->exchangeArray(\array_filter($this->getArrayCopy(), function ($oHeader) use ($sHeaderName) {
			return $oHeader && \strtolower($oHeader->Name()) !== $sHeaderName;
		}));
		return $this;
	}

	public function GetAsEmailCollection(string $sHeaderName): ?EmailCollection
	{
		if ($oHeader = $this->GetByName($sHeaderName)) {
			return new EmailCollection($oHeader->EncodedValue());
		}
		return new EmailCollection();
	}

	public function ParameterValue(string $sHeaderName, string $sParamName): string
	{
		$oHeader = $this->GetByName($sHeaderName);
		$oParameters = $oHeader ? $oHeader->Parameters() : null;
		return (null !== $oParameters) ? $oParameters->ParameterValueByName($sParamName) : '';
	}

	public function GetByName(string $sHeaderName): ?Header
	{
		$sHeaderNameLower = \strtolower($sHeaderName);
		foreach ($this as $oHeader) {
			if ($sHeaderNameLower === \strtolower($oHeader->Name())) {
				return $oHeader;
			}
		}
		return null;
	}

	public function SetParentCharset(string $sParentCharset): self
	{
		if (\strlen($sParentCharset) && $this->sParentCharset !== $sParentCharset) {
			foreach ($this as $oHeader) {
				$oHeader->SetParentCharset($sParentCharset);
			}
			$this->sParentCharset = $sParentCharset;
		}
		return $this;
	}

	public function Parse(string $sRawHeaders, string $sParentCharset = ''): self
	{
		$this->Clear();

		if (\strlen($this->sParentCharset)) {
			$this->sParentCharset = $sParentCharset;
		}

		$aHeaders = \explode("\n", \str_replace("\r", '', $sRawHeaders));

		$sName = null;
		$sValue = null;
		foreach ($aHeaders as $sHeadersValue) {
			if (!\strlen($sHeadersValue)) {
				continue;
			}

			$sFirstChar = \substr($sHeadersValue, 0, 1);
			if ($sFirstChar !== ' ' && $sFirstChar !== "\t" && false === \strpos($sHeadersValue, ':')) {
				continue;
			}
			if (null !== $sName && ($sFirstChar === ' ' || $sFirstChar === "\t")) {
				$sValue = \is_null($sValue) ? '' : $sValue;

				if ('?=' === \substr(\rtrim($sHeadersValue), -2)) {
					$sHeadersValue = \rtrim($sHeadersValue);
				}

				// RFC 5322 unfolding removes the line break and keeps the folding
				// whitespace; RFC 2047 6.2 drops it only between two encoded words.
				if ('=?' === \substr(\ltrim($sHeadersValue), 0, 2) && '?=' === \substr($sValue, -2)) {
					$sValue .= \ltrim($sHeadersValue);
				} else {
					$sValue .= ' '.\ltrim($sHeadersValue);
				}
			} else {
				if (null !== $sName) {
					$oHeader = Header::NewInstanceFromEncodedString($sName.': '.$sValue, $this->sParentCharset);
					if ($oHeader) {
						$this->append($oHeader);
					}

					$sName = null;
					$sValue = null;
				}

				$aHeaderParts = \explode(':', $sHeadersValue, 2);
				$sName = $aHeaderParts[0];
				$sValue = isset($aHeaderParts[1]) ? $aHeaderParts[1] : '';

				if ('?=' === \substr(\rtrim($sValue), -2)) {
					$sValue = \rtrim($sValue);
				}
			}
		}

		if (null !== $sName) {
			$oHeader = Header::NewInstanceFromEncodedString($sName.': '.$sValue, $this->sParentCharset);
			if ($oHeader) {
				$this->append($oHeader);
			}
		}

		return $this;
	}

	/**
	 * Detects a message that was submitted and delivered entirely on this mail server
	 * (e.g. user@domain.tld -> otheruser@domain.tld via Postfix+Dovecot LMTP), so it
	 * never passed through the public internet and SPF/DKIM/DMARC results are not
	 * meaningful (they'll typically be missing or fail).
	 *
	 * Only the topmost Received header(s) are trusted: those are prepended by our own
	 * MTA on final delivery, so a remote sender can't forge them by stuffing fake
	 * "Received: ... with LMTP" lines into a message before we receive it -- those
	 * would end up lower in the chain, behind the real hop our own server added.
	 *
	 * Recognizes the standard Postfix Received header wording:
	 * - final hop:      "... by host (Postfix) with LMTP ..."
	 * - submission hop: "... by host (Postfix) with ESMTPSA/ESMTPA ..." (SASL authenticated)
	 */
	public function IsLocalDelivery(): bool
	{
		$aReceived = $this->ValuesByName(Enumerations\Header::RECEIVED);
		if (!$aReceived) {
			return false;
		}

		$sLmtpHop = \preg_replace('/[\\r\\n\\t]+/', ' ', $aReceived[0]);
		if (!\preg_match('/\\bwith\\s+LMTP\\b/i', $sLmtpHop)) {
			return false;
		}

		if (!isset($aReceived[1])) {
			return false;
		}
		$sSubmissionHop = \preg_replace('/[\\r\\n\\t]+/', ' ', $aReceived[1]);
		return (bool) \preg_match('/\\bwith\\s+ESMTP(?:SA|A)\\b/i', $sSubmissionHop);
	}

	/**
	 * https://www.rfc-editor.org/rfc/rfc8601
	 * dkim=pass header.d=domain.tld header.s=s1 header.b=F2SfoZWw;
	 * spf=pass (ORIGINATING: domain of "snappymail@domain.tld" designates 0.0.0.0 as permitted sender) smtp.mailfrom="snappymail@domain.tld";
	 * dmarc=fail reason="SPF not aligned (relaxed), DKIM not aligned (relaxed)" header.from=domain.tld (policy=none)
	 */
	public function AuthStatuses(string $sTrustedIds = ''): array
	{
		$aResult = [
			'dkim' => [],
			'dmarc' => [],
			'spf' => []
		];
		$aHeaders = $this->ValuesByName(Enumerations\Header::AUTHENTICATION_RESULTS);

		/**
		 * A sender can add this header itself, so a result is only worth reading when the
		 * server that wrote it handled the message. $sTrustedIds names those servers by
		 * authserv-id; with none given, the authserv-id of the topmost header is taken as
		 * the local one, which keeps the separate headers an opendkim and an opendmarc
		 * each add. See RFC 8601 section 7.1.
		 */
		$aTrusted = \array_filter(\preg_split('/[\\s,;]+/', \strtolower(\trim($sTrustedIds))));
		$bConfigured = !!\count($aTrusted);
		if (\count($aHeaders)) {
			// The authserv-id is the first field, before the first ";". A version number
			// may follow it, and a comment may sit anywhere.
			$fAuthservId = function (string $sValue) : string {
				$sId = \preg_replace('/\\([^)]*\\)/', ' ', \explode(';', $sValue, 2)[0]);
				return \strtolower(\trim(\preg_replace('/\\s+\\d+$/', '', \trim($sId)), " \t\""));
			};
			$aTrusted = $aTrusted ?: array($fAuthservId($aHeaders[0]));
			$aHeaders = \array_filter($aHeaders, fn($sValue) => \in_array($fAuthservId($sValue), $aTrusted, true));
		}

		if (\count($aHeaders)) {
			$aHeaders = \implode(';', $aHeaders);
			$aHeaders = \preg_replace('/[\\r\\n\\t\\s]+/', ' ', $aHeaders);
			$aHeaders = \str_replace('-bit key;', '-bit key,', $aHeaders);
			$aHeaders = \explode(';', $aHeaders);
			foreach ($aHeaders as $sLine) {
				$aStatus = array();
				$aHeader = array();
				if (\preg_match("/(dkim|dmarc|spf)=([a-z0-9]+).*?(;|$)/Di", $sLine, $aStatus)
				 && \preg_match('/(?:header\\.(?:d|i|from)|smtp.mailfrom)="?([^\\s;"]+)/i', $sLine, $aHeader)
				) {
					$sType = \strtolower($aStatus[1]);
					$aResult[$sType][] = array(\strtolower($aStatus[2]), $aHeader[1], \trim($sLine));
				}
			}
		}
		if (!\count($aResult['dkim']) && !$bConfigured) {
			// X-DKIM-Authentication-Results: signer="hostinger.com" status="pass"
			// This one carries no authserv-id, so there is nothing to tie it to: only the
			// topmost is read, and an install that names its trusted ids skips it.
			$aHeaders = $this->ValuesByName(Enumerations\Header::X_DKIM_AUTHENTICATION_RESULTS);
			foreach ($aHeaders as $sHeaderValue) {
				$aStatus = array();
				$aHeader = array();
				$sHeaderValue = \preg_replace('/[\\r\\n\\t\\s]+/', ' ', $sHeaderValue);
				if (\preg_match('/status[\\s]?=[\\s]?"([a-zA-Z0-9]+)"/i', $sHeaderValue, $aStatus) && !empty($aStatus[1])
				 && \preg_match('/signer[\\s]?=[\\s]?"([^";]+)"/i', $sHeaderValue, $aHeader) && !empty($aHeader[1])
				) {
					$aResult['dkim'][] = array($aStatus[1], \trim($aHeader[1]), $sHeaderValue);
				}
				break;
			}
		}

		return $aResult;
	}

	public function __toString(): string
	{
		return \implode("\r\n", $this->getArrayCopy());
	}

//	public function jsonSerialize(): array
	public function jsonSerialize(): mixed
	{
		return array(
			'@Object' => 'Collection/MimeHeaderCollection',
			'@Collection' => $this->getArrayCopy()
		);
	}
}
