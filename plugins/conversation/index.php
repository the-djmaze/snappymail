<?php
/**
 * Conversation view — read the whole thread of a message on one page.
 *
 * SnappyMail issue #1950: open a message and see the received messages
 * **and** your own replies (kept in Sent), in order, as in Gmail. The server
 * tells *which* messages make up the thread; the browser loads each body on
 * demand through the core `Message` action and shows it through the core
 * sanitizer.
 *
 * Runs on SnappyMail 2.36+ and on Tachyon, which keeps the
 * `\RainLoop\Plugins\AbstractPlugin` alias and the `MailSo` namespace.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

require_once __DIR__ . '/Conversation.php';

class ConversationPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME        = 'Conversation',
		AUTHOR      = 'Convergent Cloud Computing',
		URL         = 'https://www.convergent.tn',
		VERSION     = '0.2.0',
		RELEASE     = '2026-11-15',
		REQUIRED    = '2.36.0',
		CATEGORY    = 'Messages',
		LICENSE     = 'AGPL-3.0-or-later',
		DESCRIPTION = 'Read the whole thread of a message, received and sent, on one page.';

	/**
	 * Three folders, not "all": the action runs on every opened message, the
	 * hottest path of the interface. The current folder, the inbox and Sent
	 * cover a thread as people live it.
	 */
	const MAX_FOLDERS = 3;

	/** Candidates checked against their headers, per folder. Beyond: `partial`. */
	const MAX_CANDIDATES = 200;

	public function Init() : void
	{
		$this->UseLangs(true);
		$this->addJs('conversation.js');
		$this->addCss('conversation.css');
		$this->addJsonHook('ConversationList', 'DoConversationList');
	}

	/**
	 * A **logged in** IMAP client.
	 *
	 * `Actions()->ImapClient()` returns a client that is not connected: an
	 * action registered with `addJsonHook` does not trigger
	 * `initMailClientConnection()` (protected), so the plugin logs in itself.
	 */
	private function imap() : ?\MailSo\Imap\ImapClient
	{
		try {
			$oActions = $this->Manager()->Actions();
			$oClient  = $oActions->ImapClient();
			if (!$oClient->IsLoggined()) {
				$oAccount = $oActions->getAccountFromToken();
				if (!$oAccount) {
					return null;
				}
				$oAccount->ImapConnectAndLogin($this->Manager(), $oClient, $oActions->Config());
			}
			return $oClient->IsLoggined() ? $oClient : null;
		} catch (\Throwable $oException) {
			self::log('no IMAP connection: ' . $oException->getMessage());
			return null;
		}
	}

	/**
	 * The folders set by the user, read where the core reads them
	 * (`SettingsLocal`): "Sent", "Envoyés", "Elementos enviados" all exist,
	 * nothing is guessed from a name.
	 */
	private function folderSettings() : array
	{
		$a = array('sent' => '', 'drafts' => '');
		try {
			$oActions  = $this->Manager()->Actions();
			$oAccount  = $oActions->getAccountFromToken();
			$oSettings = $oAccount ? $oActions->SettingsProvider(true)->Load($oAccount) : null;
			if ($oSettings && \method_exists($oSettings, 'GetConf')) {
				$a['sent']   = \trim((string) $oSettings->GetConf('SentFolder', ''));
				$a['drafts'] = \trim((string) $oSettings->GetConf('DraftsFolder', ''));
			}
		} catch (\Throwable $oException) {
			self::log('unreadable settings: ' . $oException->getMessage());
		}
		return $a;
	}

	/**
	 * Sent and Drafts from their **IMAP role** (`\Sent`, `\Drafts`, RFC 6154),
	 * as read by the core `Folder::Role()`.
	 *
	 * An account that never saved its folder settings has no `SentFolder` on
	 * the server, while the browser guesses the folder from that same role.
	 * Without this fallback, the thread would miss every sent reply.
	 */
	public static function byRole(iterable $aFolders) : array
	{
		$a = array('sent' => '', 'drafts' => '');
		foreach ($aFolders as $oFolder) {
			if (!\is_object($oFolder) || !\method_exists($oFolder, 'Role') || !isset($oFolder->FullName)) {
				continue;
			}
			$sRole = (string) $oFolder->Role();
			if ('sent' === $sRole && '' === $a['sent']) {
				$a['sent'] = (string) $oFolder->FullName;
			} else if ('drafts' === $sRole && '' === $a['drafts']) {
				$a['drafts'] = (string) $oFolder->FullName;
			}
		}
		return $a;
	}

	/** Fills empty settings from the IMAP roles. An unreadable list breaks nothing. */
	private function complete(\MailSo\Imap\ImapClient $oClient, array $aSettings) : array
	{
		if ('' !== $aSettings['sent'] && '' !== $aSettings['drafts']) {
			return $aSettings;
		}
		try {
			$aRoles = self::byRole($oClient->FolderList('', '*'));
			foreach ($aRoles as $sKey => $sFolder) {
				'' === $aSettings[$sKey] && $aSettings[$sKey] = $sFolder;
			}
		} catch (\Throwable $oException) {
			self::log('unreadable folder list: ' . $oException->getMessage());
		}
		return $aSettings;
	}

	/**
	 * The current folder, the inbox, Sent — without duplicates and without
	 * Drafts: a draft is not part of an exchange.
	 */
	public static function folders(string $sCurrent, string $sSent, string $sDrafts) : array
	{
		$aFolders = array();
		foreach (array($sCurrent, 'INBOX', $sSent) as $s) {
			if ('' !== $s && $s !== $sDrafts && !\in_array($s, $aFolders, true)) {
				$aFolders[] = $s;
			}
		}
		return \array_slice($aFolders, 0, self::MAX_FOLDERS);
	}

	/** The three headers that make a thread, without marking anything read (`BODY.PEEK`). */
	private static function headerRequest() : string
	{
		return \MailSo\Imap\Enumerations\FetchType::BuildBodyCustomHeaderRequest(
			array('MESSAGE-ID', 'IN-REPLY-TO', 'REFERENCES'), true);
	}

	/**
	 * What a `FETCH` response tells: its place in a thread and what the panel
	 * shows. Envelope indexes, RFC 3501 § 7.4.2: 0 date, 1 subject, 2 `From`.
	 */
	private static function read($oResponse, string $sFolder, bool $bSent) : array
	{
		$aPosition = ConversationThread::position(ConversationThread::headers((string) $oResponse->GetHeaderFieldsValue()));
		$mFlags = $oResponse->GetFetchValue(\MailSo\Imap\Enumerations\FetchType::FLAGS);
		$aFlags = \is_array($mFlags) ? \array_map('strtolower', \array_filter($mFlags, 'is_string')) : array();
		return $aPosition + array(
			'folder'    => $sFolder,
			'uid'       => (int) $oResponse->GetFetchValue(\MailSo\Imap\Enumerations\FetchType::UID),
			'from'      => ConversationThread::sender($oResponse->GetFetchEnvelopeValue(2, null),
				static fn (string $s) : string => \MailSo\Base\Utils::DecodeHeaderValue($s, 'utf-8')),
			'timestamp' => ConversationThread::timestamp($oResponse->GetFetchEnvelopeValue(0, ''),
				$oResponse->GetFetchValue(\MailSo\Imap\Enumerations\FetchType::INTERNALDATE)),
			'subject'   => ConversationThread::normalize(\MailSo\Base\Utils::DecodeHeaderValue(
				(string) $oResponse->GetFetchEnvelopeValue(1, ''), 'utf-8'), 500),
			'seen'      => \in_array('\\seen', $aFlags, true),
			'sent'      => $bSent,
		);
	}

	private static function fetchItems() : array
	{
		return array(
			\MailSo\Imap\Enumerations\FetchType::UID,
			\MailSo\Imap\Enumerations\FetchType::ENVELOPE,
			\MailSo\Imap\Enumerations\FetchType::FLAGS,
			\MailSo\Imap\Enumerations\FetchType::INTERNALDATE,
			self::headerRequest(),
		);
	}

	/**
	 * The messages of one folder that belong to the thread.
	 *
	 * `FolderExamine`, never `FolderSelect`: reading a thread changes nothing,
	 * no `\Seen` is set by accident. `MessageSort` rather than `MessageESort`,
	 * which returns a sequence set to parse. Every candidate is checked again
	 * by `ConversationThread::belongs()`: the search is only a filter.
	 *
	 * Returns `array(rows, partial)`.
	 */
	private function fromFolder(\MailSo\Imap\ImapClient $oClient, string $sFolder, string $sCriterion,
		array $aKeys, bool $bSent) : array
	{
		try {
			$oClient->FolderExamine($sFolder);
			$aUids = $oClient->MessageSort(array('DATE'), $sCriterion, true);
			$aUids = \is_array($aUids) ? \array_values(\array_filter(\array_map('intval', $aUids))) : array();
			$bPartial = \count($aUids) > self::MAX_CANDIDATES;
			$aUids = \array_slice($aUids, -self::MAX_CANDIDATES);
			if (!\count($aUids)) {
				return array(array(), false);
			}
			$aRows = array();
			foreach ($oClient->Fetch(self::fetchItems(), \implode(',', $aUids), true) as $oResponse) {
				$aRow = self::read($oResponse, $sFolder, $bSent);
				if ($aRow['uid'] > 0 && ConversationThread::belongs($aRow, $aKeys)) {
					$aRows[] = $aRow;
				}
			}
			return array($aRows, $bPartial);
		} catch (\Throwable $oException) {
			// An unreadable folder (rights removed, mailbox gone) does not
			// break the thread; it makes it partial.
			self::log('folder skipped: ' . $oException->getMessage());
			return array(array(), true);
		}
	}

	/** The open message, read-only. `null` if it cannot be found. */
	private function current(\MailSo\Imap\ImapClient $oClient, string $sFolder, int $iUid) : ?array
	{
		$oClient->FolderExamine($sFolder);
		foreach ($oClient->Fetch(self::fetchItems(), (string) $iUid, true) as $oResponse) {
			$aRow = self::read($oResponse, $sFolder, false);
			if ($aRow['uid'] === $iUid) {
				return $aRow;
			}
		}
		return null;
	}

	/** A reply that shows nothing, and says why. */
	private function nothing(string $sReason) : array
	{
		return $this->jsonResponse('DoConversationList', array('messages' => array(), 'reason' => $sReason));
	}

	/**
	 * The action. Parameters: `Folder` and `Uid` of the open message — and
	 * nothing else: its headers are read again here, never taken from the
	 * browser.
	 *
	 * Returns `messages` (two or more, or none), `partial` and `truncated`.
	 */
	public function DoConversationList() : array
	{
		try {
			$sFolder = ConversationThread::normalize($this->jsonParam('Folder', ''), 1000);
			$mUid    = $this->jsonParam('Uid', 0);
			$iUid    = (\is_int($mUid) || (\is_string($mUid) && \preg_match('/^[1-9][0-9]{0,9}$/D', $mUid)))
				? (int) $mUid : 0;
			if ('' === $sFolder || $iUid <= 0) {
				return $this->nothing('parameters');
			}
			if (!$this->Manager()->Actions()->getAccountFromToken(false)) {
				return $this->nothing('not-logged-in');
			}

			$aSettings = $this->folderSettings();
			if ('' !== $aSettings['drafts'] && $sFolder === $aSettings['drafts']) {
				return $this->nothing('draft');
			}

			$oClient = $this->imap();
			if (!$oClient) {
				return $this->nothing('no-imap');
			}
			$aSettings = $this->complete($oClient, $aSettings);
			if ('' !== $aSettings['drafts'] && $sFolder === $aSettings['drafts']) {
				return $this->nothing('draft');
			}

			$aCurrent = $this->current($oClient, $sFolder, $iUid);
			if (!$aCurrent) {
				return $this->nothing('not-found');
			}
			$aKeys = $aCurrent['keys'];
			$sCriterion = ConversationThread::criterion($aKeys);
			if (null === $sCriterion) {
				return $this->nothing('no-message-id');
			}
			// The judging keys also include what the open message cites: a
			// client that only kept `In-Reply-To` still links its parent. The
			// search itself stays bounded by `MAX_KEYS`.
			$aJudge = \array_values(\array_unique(\array_merge($aKeys, $aCurrent['references'])));

			$aRows = array();
			$bPartial = false;
			foreach (self::folders($sFolder, $aSettings['sent'], $aSettings['drafts']) as $sOne) {
				list($aFromFolder, $bP) = $this->fromFolder($oClient, $sOne, $sCriterion, $aJudge,
					'' !== $aSettings['sent'] && $sOne === $aSettings['sent']);
				$bPartial = $bPartial || $bP;
				foreach ($aFromFolder as $aRow) {
					$aRow['current'] = ($sOne === $sFolder && $aRow['uid'] === $iUid);
					$aRows[] = $aRow;
				}
			}
			if ('' === $aSettings['sent']) {
				$bPartial = true;
			}

			$aThread = ConversationThread::assemble($aRows);
			$aMessages = array();
			foreach ($aThread['messages'] as $a) {
				$aMessages[] = array(
					'folder'  => $a['folder'],
					'uid'     => $a['uid'],
					'from'    => $a['from'],
					'date'    => $a['timestamp'] ? \gmdate('c', $a['timestamp']) : '',
					'subject' => $a['subject'],
					'seen'    => $a['seen'],
					'sent'    => $a['sent'],
					'current' => !empty($a['current']),
				);
			}
			// The panel only exists when it has something to say: a thread of
			// one message returns nothing.
			if (\count($aMessages) < 2) {
				return $this->nothing('single');
			}
			return $this->jsonResponse('DoConversationList', array(
				'messages'  => $aMessages,
				'partial'   => $bPartial,
				'truncated' => $aThread['truncated'],
				'reason'    => '',
			));
		} catch (\Throwable $oException) {
			self::log('failure: ' . $oException->getMessage());
			return $this->nothing('error');
		}
	}

	private static function log(string $sMessage) : void
	{
		if (\class_exists('\\SnappyMail\\Log')) {
			\SnappyMail\Log::notice('conversation', $sMessage);
		}
	}
}
