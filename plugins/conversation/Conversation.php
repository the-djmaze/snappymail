<?php
/**
 * Conversation view — the part of the plugin that talks to no server.
 *
 * Everything that decides *which* messages make up a thread lives here, as
 * pure functions: read the identifiers of the headers, build the IMAP search
 * criterion, judge every hit on its own headers, de-duplicate and sort.
 * `index.php` only speaks IMAP around it, which is what lets the logic be
 * tested without a server — on this very file, not on a copy.
 *
 * The search criterion is a coarse filter, never a proof: full-text backends
 * (Cyrus with Xapian, for one) return false positives for header searches.
 * Every hit is therefore checked again by `belongs()` against its own headers,
 * and only what passes joins the thread.
 *
 * Message identifiers come from headers written by third parties. They go
 * through an allow-list (`identifiers()`) and IMAP quoting (`escape()`) before
 * they reach `UID SORT`: `MessageSort()` copies the criterion string verbatim.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

class ConversationThread
{
	/** RFC 5322 sets no limit on a message identifier; we set one. */
	const MAX_ID = 250;

	/** Identifiers kept from a `References` header, oldest first, as it orders them. */
	const MAX_REFERENCES = 100;

	/** Identifiers that enter the search criterion; each one adds two terms. */
	const MAX_KEYS = 3;

	/** Messages returned to the browser: the most recent ones, in thread order. */
	const MAX_MESSAGES = 50;

	/**
	 * Every control character becomes a space (a CR LF pair inside a quoted
	 * IMAP string would start a second command), non UTF-8 input gives ''
	 * (fail closed), white space is collapsed and the length bounded.
	 */
	public static function normalize($mTerm, int $iMax = 254) : string
	{
		if (!\is_string($mTerm)) {
			return '';
		}
		$sTerm = \preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $mTerm);
		if (null === $sTerm || '' === $sTerm) {
			return '';
		}
		$sTerm = (string) \preg_replace('/\s+/u', ' ', $sTerm);
		$sTerm = \trim($sTerm);
		if (\function_exists('mb_substr')) {
			$sTerm = (string) \mb_substr($sTerm, 0, $iMax, 'UTF-8');
		} else {
			$sTerm = (string) \substr($sTerm, 0, $iMax);
		}
		return \trim($sTerm);
	}

	/**
	 * The content of an IMAP quoted string (RFC 3501 § 4.3). The backslash is
	 * escaped **before** the double quote, or the backslash just added would
	 * be escaped again.
	 */
	public static function escape(string $sText) : string
	{
		return \str_replace(array('\\', '"'), array('\\\\', '\\"'), $sText);
	}

	/**
	 * The `<…>` identifiers of a header value, in order, without duplicates,
	 * through an allow-list: no white space, no angle bracket, no double quote,
	 * no backslash, one `@`, at most `MAX_ID` characters. Anything else is
	 * ignored — an incomplete thread is better than a dubious command.
	 */
	public static function identifiers($mValue, int $iMax = self::MAX_REFERENCES) : array
	{
		if (!\is_string($mValue) || '' === $mValue) {
			return array();
		}
		$sValue = self::normalize($mValue, 20000);
		if ('' === $sValue) {
			return array();
		}
		\preg_match_all('/<([^<>\s"\\\\]{1,' . self::MAX_ID . '})>/u', $sValue, $aM);
		$aIds = array();
		foreach ($aM[1] as $sBody) {
			if (false === \strpos($sBody, '@')) {
				continue;
			}
			$sId = '<' . $sBody . '>';
			if (!\in_array($sId, $aIds, true)) {
				$aIds[] = $sId;
			}
			if (\count($aIds) >= $iMax) {
				break;
			}
		}
		return $aIds;
	}

	/**
	 * A raw header block (`BODY[HEADER.FIELDS (…)]`) → lower-case name ⇒
	 * unfolded value. A fold (CRLF followed by a space or a tab) is joined;
	 * the first occurrence of a name wins.
	 */
	public static function headers(string $sRaw) : array
	{
		$sRaw = (string) \preg_replace('/\r?\n[ \t]+/', ' ', $sRaw);
		$aHeaders = array();
		foreach (\preg_split('/\r?\n/', $sRaw) ?: array() as $sLine) {
			if (!\preg_match('/^([A-Za-z0-9-]{1,64})\s*:\s?(.*)$/s', $sLine, $aM)) {
				continue;
			}
			$sName = \strtolower($aM[1]);
			if (!\array_key_exists($sName, $aHeaders)) {
				$aHeaders[$sName] = \trim($aM[2]);
			}
		}
		return $aHeaders;
	}

	/**
	 * What a message says about its place in a thread.
	 *
	 * Returns `own` (its `Message-ID`, or ''), `parent` (`In-Reply-To`),
	 * `references` (the chain, from the root to the parent) and `keys`: the
	 * identifiers to search for, **root first** — the first `References`,
	 * else the parent, else the message itself.
	 */
	public static function position(array $aHeaders) : array
	{
		$aOwn    = self::identifiers($aHeaders['message-id'] ?? '', 1);
		$aParent = self::identifiers($aHeaders['in-reply-to'] ?? '', 1);
		$aRefs   = self::identifiers($aHeaders['references'] ?? '');
		$sOwn    = $aOwn[0] ?? '';
		$sParent = $aParent[0] ?? '';

		$aKeys = array();
		foreach (array($aRefs[0] ?? '', $sParent, $sOwn) as $sId) {
			if ('' !== $sId && !\in_array($sId, $aKeys, true)) {
				$aKeys[] = $sId;
			}
		}
		return array(
			'own'        => $sOwn,
			'parent'     => $sParent,
			'references' => $aRefs,
			'keys'       => \array_slice($aKeys, 0, self::MAX_KEYS),
		);
	}

	/**
	 * The `UID SORT` criterion: for every key, `HEADER Message-ID` or
	 * `HEADER References`, joined by prefix `OR`s (RFC 3501 § 6.4.4). Returns
	 * `null` without a key — no search rather than `ALL`.
	 *
	 * Every key is validated again here: this function trusts nothing it is given.
	 */
	public static function criterion(array $aKeys) : ?string
	{
		$aTerms = array();
		foreach (\array_slice($aKeys, 0, self::MAX_KEYS) as $mKey) {
			$aOk = self::identifiers(\is_string($mKey) ? $mKey : '', 1);
			if (!\count($aOk) || $aOk[0] !== $mKey) {
				continue;
			}
			$sQ = '"' . self::escape($aOk[0]) . '"';
			$aTerms[] = 'HEADER Message-ID ' . $sQ;
			$aTerms[] = 'HEADER References ' . $sQ;
		}
		if (!\count($aTerms)) {
			return null;
		}
		$sCriterion = \array_pop($aTerms);
		while (\count($aTerms)) {
			$sCriterion = 'OR ' . \array_pop($aTerms) . ' ' . $sCriterion;
		}
		return $sCriterion;
	}

	/**
	 * The actual judge: does this candidate belong to the thread named by
	 * `$aKeys`? Yes if its own identifier, its parent or one of its
	 * `References` is a key. Nothing else — in particular not the subject,
	 * which anyone can copy.
	 */
	public static function belongs(array $aCandidate, array $aKeys) : bool
	{
		if (!\count($aKeys)) {
			return false;
		}
		$aIds = $aCandidate['references'] ?? array();
		$aIds[] = (string) ($aCandidate['own'] ?? '');
		$aIds[] = (string) ($aCandidate['parent'] ?? '');
		foreach ($aIds as $sId) {
			if ('' !== $sId && \in_array($sId, $aKeys, true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The thread, ready to return. A message found in two folders (a copy in
	 * Sent and in the inbox, when you Cc yourself) appears once — preferably
	 * the copy being read, else the first one found. Chronological order;
	 * beyond `MAX_MESSAGES` the oldest are dropped and `truncated` says so.
	 *
	 * Every row carries at least `own`, `timestamp` and `current`.
	 */
	public static function assemble(array $aRows) : array
	{
		$aById = array();
		$aWithoutId = array();
		foreach ($aRows as $aRow) {
			$sId = (string) ($aRow['own'] ?? '');
			if ('' === $sId) {
				$aWithoutId[] = $aRow;
				continue;
			}
			if (!isset($aById[$sId]) || (!empty($aRow['current']) && empty($aById[$sId]['current']))) {
				$aById[$sId] = $aRow;
			}
		}
		$aAll = \array_merge(\array_values($aById), $aWithoutId);
		\usort($aAll, static function (array $a, array $b) : int {
			return ((int) ($a['timestamp'] ?? 0)) <=> ((int) ($b['timestamp'] ?? 0))
				?: \strcmp((string) ($a['folder'] ?? ''), (string) ($b['folder'] ?? ''))
				?: ((int) ($a['uid'] ?? 0)) <=> ((int) ($b['uid'] ?? 0));
		});
		$bTruncated = \count($aAll) > self::MAX_MESSAGES;
		if ($bTruncated) {
			$aAll = \array_slice($aAll, -self::MAX_MESSAGES);
		}
		return array('messages' => $aAll, 'truncated' => $bTruncated);
	}

	/**
	 * The sender of an IMAP envelope (`From`, index 2: a list of
	 * `(name adl mailbox host)`). Returns `name` (may be empty) and `address`
	 * (lower case, or ''). RFC 2047 decoding is passed in, so that this file
	 * does not depend on MailSo.
	 */
	public static function sender($mFrom, ?callable $fDecode = null) : array
	{
		$aFirst   = (\is_array($mFrom) && isset($mFrom[0]) && \is_array($mFrom[0])) ? $mFrom[0] : array();
		$sName    = \is_string($aFirst[0] ?? null) ? $aFirst[0] : '';
		$sMailbox = \is_string($aFirst[2] ?? null) ? $aFirst[2] : '';
		$sHost    = \is_string($aFirst[3] ?? null) ? $aFirst[3] : '';
		if ($fDecode && '' !== $sName) {
			$sName = (string) $fDecode($sName);
		}
		$sAddress = ('' !== $sMailbox && '' !== $sHost) ? \strtolower($sMailbox . '@' . $sHost) : '';
		if ('' !== $sAddress && !\preg_match('/^[^\s@"<>]+@[^\s@"<>]+$/u', $sAddress)) {
			$sAddress = '';
		}
		return array('name' => self::normalize($sName, 200), 'address' => $sAddress);
	}

	/** A readable header date → timestamp; unreadable → the internal date, else 0. */
	public static function timestamp($mDate, $mInternal = null) : int
	{
		foreach (array($mDate, $mInternal) as $m) {
			if (\is_string($m) && '' !== \trim($m)) {
				$i = \strtotime($m);
				if (false !== $i && $i > 0) {
					return $i;
				}
			}
		}
		return 0;
	}
}
