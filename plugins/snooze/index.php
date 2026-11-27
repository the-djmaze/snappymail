<?php

use MailSo\Imap\Enumerations\MessageFlag;
use MailSo\Imap\Enumerations\FetchType;
use MailSo\Imap\SequenceSet;
use RainLoop\Exceptions\ClientException;
use RainLoop\Notifications;

/**
 * Snooze plugin.
 *
 * Messages are flagged with "Snoozed_<unix-timestamp>" and moved to a
 * dedicated folder. When the timestamp is reached the message is moved back
 * to the inbox and flagged with "Snoozed" so the UI can mark it as returned.
 */
class SnoozePlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Snooze',
		AUTHOR   = 'SnappyMail',
		URL      = 'https://github.com/the-djmaze/snappymail/pull/2062',
		VERSION  = '1.0.3',
		RELEASE  = '2026-11-11',
		REQUIRED = '2.38.0',
		CATEGORY = 'General',
		LICENSE  = 'AGPL v3',
		DESCRIPTION = 'Snooze messages and return them to the inbox at a chosen date and time.';

	public function Init() : void
	{
		$this->UseLangs(true);

		$this->addCss('style.css');
		$this->addJs('js/snooze.js');
		$this->addTemplate('templates/PopupsSnooze.html');

		$this->addJsonHook('SnoozeMessage', 'SnoozeMessage');
		$this->addJsonHook('SnoozeProcess', 'SnoozeProcess');
		$this->addJsonHook('SnoozeSetFolder', 'SnoozeSetFolder');

		// Return due messages before the message list is built, so the list
		// already contains the messages that came back.
		$this->addHook('json.before-MessageList', 'BeforeMessages');

		// Remove the "returned" marker as soon as the message is read.
		$this->addHook('json.before-MessageSetSeen', 'BeforeMessageSetSeen');
		$this->addHook('json.before-MessageSetSeenToAll', 'BeforeMessageSetSeenToAll');

		// Do not keep snooze flags on messages that leave their folder.
		$this->addHook('json.before-MessageMove', 'BeforeMessageMove');
		$this->addHook('json.before-MessageDelete', 'BeforeMessageDelete');
	}

	/**
	 * Expose the configured folder to the user interface.
	 */
	public function FilterAppDataPluginSection(bool $bAdmin, bool $bAuth, array &$aConfig) : void
	{
		if (!$bAdmin && $bAuth) {
			$aConfig['SnoozeFolder'] = $this->snoozedFolderSetting();
		}
	}

	public function SnoozeMessage() : array
	{
		$oActions = $this->actions();
		$this->initMailClient();

		$sFromFolder = (string) $this->jsonParam('folder', '');
		$iUid = (int) $this->jsonParam('uid', 0);
		$iUntil = (int) $this->jsonParam('until', 0);
		if (!$sFromFolder || 0 >= $iUid || \time() >= $iUntil) {
			throw new ClientException(Notifications::UnknownError);
		}

		$oUids = new SequenceSet([$iUid]);
		$bCreated = false;
		$sSnoozedFolder = $this->findSnoozedFolder();
		if (!$sSnoozedFolder) {
			try {
				$oNamespaces = $oActions->ImapClient()->GetNamespaces();
				$sNamespace = $oNamespaces ? $oNamespaces->GetPersonalPrefix() : '';
				$sSnoozedFolder = $sNamespace.'Snoozed';
				$oActions->ImapClient()->FolderCreate($sSnoozedFolder, true);
				$bCreated = true;
			} catch (\Throwable $oException) {
				$sSnoozedFolder = $this->findSnoozedFolder();
				if (!$sSnoozedFolder) {
					throw new ClientException(Notifications::CantCreateFolder, $oException);
				}
			}
		}
		$this->saveSnoozedFolderSetting($sSnoozedFolder);

		$sFlag = 'Snoozed_'.$iUntil;
		$aOriginalFlags = null;
		$bWasSeen = false;
		try {
			$oSnoozedInfo = $oActions->ImapClient()->FolderStatusAndSelect($sSnoozedFolder);
			if (!$this->isFlagSupported($oSnoozedInfo, $sFlag)) {
				throw new \MailSo\RuntimeException('The Snoozed folder does not support custom flags.');
			}

			$oSourceInfo = $oActions->ImapClient()->FolderStatusAndSelect($sFromFolder);
			if (!$this->isFlagSupported($oSourceInfo, $sFlag)) {
				throw new \MailSo\RuntimeException('The source folder does not support custom flags.');
			}

			$aOriginalFlags = $this->snoozeFlags($sFromFolder, $oUids)[$iUid] ?? [];
			$bWasSeen = \in_array(MessageFlag::SEEN, $aOriginalFlags);
			$this->clearSnoozeFlags($sFromFolder, $oUids);
			$oActions->MailClient()->MessageSetFlag($sFromFolder, $oUids, $sFlag, true, true);
			$oActions->MailClient()->MessageSetFlag($sFromFolder, $oUids, MessageFlag::SEEN, true, true);

			if ($sFromFolder !== $sSnoozedFolder) {
				$oActions->ImapClient()->MessageMove($sFromFolder, $sSnoozedFolder, $oUids);
			}
		} catch (\Throwable $oException) {
			if (\is_array($aOriginalFlags)) try {
				$this->clearSnoozeFlags($sFromFolder, $oUids);
				foreach ($aOriginalFlags as $sOriginalFlag) {
					if ('snoozed' === $sOriginalFlag || \preg_match('/^snoozed_[0-9]+$/', $sOriginalFlag)) {
						$oActions->MailClient()->MessageSetFlag($sFromFolder, $oUids, $sOriginalFlag, true, true);
					}
				}
				$oActions->MailClient()->MessageSetFlag($sFromFolder, $oUids, MessageFlag::SEEN, $bWasSeen, true);
			} catch (\Throwable $oRollbackException) {
				$this->Manager()->logException($oRollbackException, \LOG_ERR);
			}
			throw new ClientException(Notifications::CantMoveMessage, $oException);
		}

		return $this->jsonResponse(__FUNCTION__, [
			'folder' => $sSnoozedFolder,
			'created' => $bCreated
		]);
	}

	public function SnoozeProcess() : array
	{
		$this->initMailClient();
		return $this->jsonResponse(__FUNCTION__, ['moved' => $this->processSnoozedMessages()]);
	}

	public function SnoozeSetFolder() : array
	{
		$sFolder = \trim((string) $this->jsonParam('folder', ''));
		if ($sFolder) {
			$this->initMailClient();

			$sFoundFolder = '';
			foreach ($this->allFolders() as $oFolder) {
				if ($oFolder->FullName === $sFolder) {
					$sFoundFolder = $oFolder->FullName;
					break;
				}
			}
			if (!$sFoundFolder) {
				throw new ClientException(Notifications::UnknownError);
			}
			$sFolder = $sFoundFolder;
		}

		$this->saveSnoozedFolderSetting($sFolder);
		return $this->jsonResponse(__FUNCTION__, ['folder' => $sFolder]);
	}

	/**
	 * Process due messages before the message list is loaded.
	 */
	public function BeforeMessages() : void
	{
		try {
			$this->initMailClient();
			$this->processSnoozedMessages();
		} catch (\Throwable $oException) {
			$this->Manager()->logException($oException);
		}
	}

	/**
	 * Remove the returned marker when a message is marked as read.
	 */
	public function BeforeMessageSetSeen() : void
	{
		if (!empty($this->jsonParam('setAction', '0'))) {
			try {
				$this->initMailClient();
				$this->clearReturnedFlags(
					(string) $this->jsonParam('folder', ''),
					new SequenceSet(\explode(',', (string) $this->jsonParam('uids', '')))
				);
			} catch (\Throwable $oException) {
				$this->Manager()->logException($oException);
			}
		}
	}

	public function BeforeMessageSetSeenToAll() : void
	{
		if (empty($this->jsonParam('setAction', '0'))) {
			return;
		}

		try {
			$this->initMailClient();
			$sThreadUids = \trim((string) $this->jsonParam('threadUids', ''));
			$this->clearReturnedFlags(
				(string) $this->jsonParam('folder', ''),
				$sThreadUids ? new SequenceSet(\explode(',', $sThreadUids)) : new SequenceSet('1:*', false)
			);
		} catch (\Throwable $oException) {
			$this->Manager()->logException($oException);
		}
	}

	/**
	 * A message moved out of the snooze folder is no longer snoozed.
	 */
	public function BeforeMessageMove() : void
	{
		try {
			$this->initMailClient();
			$this->clearSnoozeFlags(
				(string) $this->jsonParam('fromFolder', ''),
				new SequenceSet(\explode(',', (string) $this->jsonParam('uids', '')))
			);
		} catch (\Throwable $oException) {
			$this->Manager()->logException($oException);
		}
	}

	public function BeforeMessageDelete() : void
	{
		try {
			$this->initMailClient();
			$this->clearSnoozeFlags(
				(string) $this->jsonParam('folder', ''),
				new SequenceSet(\explode(',', (string) $this->jsonParam('uids', '')))
			);
		} catch (\Throwable $oException) {
			$this->Manager()->logException($oException);
		}
	}

	/**
	 * initMailClientConnection() is protected in Actions, so connect here.
	 */
	private function initMailClient() : void
	{
		$oActions = $this->actions();
		$oAccount = $oActions->getAccountFromToken();
		if ($oAccount && !$oActions->ImapClient()->IsLoggined()) {
			try {
				$oAccount->ImapConnectAndLogin(
					$oActions->Plugins(),
					$oActions->ImapClient(),
					$oActions->Config()
				);
			} catch (\MailSo\Net\Exceptions\ConnectionException $oException) {
				throw new ClientException(Notifications::ConnectionError, $oException);
			} catch (\Throwable $oException) {
				throw new ClientException(Notifications::AuthError, $oException);
			}
		}
	}

	private function actions() : \RainLoop\Actions
	{
		return $this->Manager()->Actions();
	}

	private function allFolders() : array
	{
		$aResult = [];
		$oFolders = $this->actions()->MailClient()->Folders('', '*', false);
		if ($oFolders) {
			foreach ($oFolders as $oFolder) {
				$aResult[] = $oFolder;
			}
		}
		return $aResult;
	}

	private function findSnoozedFolder() : string
	{
		$sConfiguredFolder = $this->snoozedFolderSetting();
		$sDefaultFolder = '';
		foreach ($this->allFolders() as $oFolder) {
			if ($sConfiguredFolder && $oFolder->FullName === $sConfiguredFolder) {
				return $oFolder->FullName;
			}
			if (!$sDefaultFolder && 0 === \strcasecmp($oFolder->Name(), 'Snoozed')) {
				$sDefaultFolder = $oFolder->FullName;
			}
		}
		return $sDefaultFolder;
	}

	private function snoozedFolderSetting() : string
	{
		$aSettings = $this->getUserSettings();
		$sFolder = \trim((string) ($aSettings['SnoozeFolder'] ?? ''));
		return '__UNUSE__' === $sFolder || '0' === $sFolder ? '' : $sFolder;
	}

	private function saveSnoozedFolderSetting(string $sFolder) : void
	{
		$aSettings = $this->getUserSettings();
		$aSettings['SnoozeFolder'] = $sFolder;
		$this->saveUserSettings($aSettings);
	}

	private function isFlagSupported(\MailSo\Imap\FolderInformation $oFolder, string $sFlag) : bool
	{
		$sFlag = \strtolower($sFlag);
		foreach ($oFolder->PermanentFlags as $sPermanentFlag) {
			if ('\\*' === \strtolower($sPermanentFlag) || $sFlag === \strtolower($sPermanentFlag)) {
				return true;
			}
		}
		return false;
	}

	private function snoozeFlags(string $sFolder, SequenceSet $oUids) : array
	{
		$aResult = [];
		if (!$sFolder || !\count($oUids)) {
			return $aResult;
		}

		$this->actions()->ImapClient()->FolderSelect($sFolder);
		$aResponses = $this->actions()->ImapClient()->Fetch(
			[FetchType::UID, FetchType::FLAGS, FetchType::RFC822_SIZE],
			(string) $oUids,
			$oUids->UID
		);
		foreach ($aResponses as $oResponse) {
			$iUid = (int) $oResponse->GetFetchValue(FetchType::UID);
			$aFlags = $oResponse->GetFetchValue(FetchType::FLAGS) ?: [];
			$aResult[$iUid] = \array_map(
				'mb_strtolower',
				\array_map('\\MailSo\\Base\\Utils::Utf7ModifiedToUtf8', $aFlags)
			);
		}
		return $aResult;
	}

	private function clearSnoozeFlags(string $sFolder, SequenceSet $oUids) : void
	{
		if (!$sFolder || !\count($oUids)) {
			return;
		}

		foreach ($this->snoozeFlags($sFolder, $oUids) as $iUid => $aFlags) {
			foreach ($aFlags as $sFlag) {
				if ('snoozed' === $sFlag || \preg_match('/^snoozed_[0-9]+$/', $sFlag)) {
					$this->actions()->MailClient()->MessageSetFlag(
						$sFolder,
						new SequenceSet([$iUid]),
						$sFlag,
						false,
						true
					);
				}
			}
		}
	}

	private function clearReturnedFlags(string $sFolder, SequenceSet $oUids) : void
	{
		if (!$sFolder || !\count($oUids)) {
			return;
		}

		foreach ($this->snoozeFlags($sFolder, $oUids) as $iUid => $aFlags) {
			if (\in_array('snoozed', $aFlags)) {
				$this->actions()->MailClient()->MessageSetFlag(
					$sFolder,
					new SequenceSet([$iUid]),
					'Snoozed',
					false,
					true
				);
			}
		}
	}

	private function processSnoozedMessages() : int
	{
		$sSnoozedFolder = $this->findSnoozedFolder();
		if (!$sSnoozedFolder) {
			return 0;
		}

		$sInboxFolder = '';
		foreach ($this->allFolders() as $oFolder) {
			if ($oFolder->IsInbox()) {
				$sInboxFolder = $oFolder->FullName;
				break;
			}
		}
		if (!$sInboxFolder) {
			return 0;
		}

		$oActions = $this->actions();
		$oInfo = $oActions->ImapClient()->FolderStatusAndSelect($sSnoozedFolder);
		$aDue = [];
		for ($iStart = 1; $iStart <= $oInfo->MESSAGES; $iStart += 250) {
			$iEnd = \min($oInfo->MESSAGES, $iStart + 249);
			$aResponses = $oActions->ImapClient()->Fetch(
				[FetchType::UID, FetchType::FLAGS, FetchType::RFC822_SIZE],
				$iStart.':'.$iEnd,
				false
			);
			foreach ($aResponses as $oResponse) {
				$iUid = (int) $oResponse->GetFetchValue(FetchType::UID);
				$aFlags = \array_map(
					'mb_strtolower',
					\array_map('\\MailSo\\Base\\Utils::Utf7ModifiedToUtf8',
						$oResponse->GetFetchValue(FetchType::FLAGS) ?: [])
				);
				foreach ($aFlags as $sFlag) {
					if (\preg_match('/^snoozed_([0-9]+)$/', $sFlag, $aMatch)
						&& (int) $aMatch[1] <= \time()) {
						$aDue[$aMatch[1]][$iUid] = [
							'flag' => $sFlag,
							'seen' => \in_array(MessageFlag::SEEN, $aFlags)
						];
					}
				}
			}
		}

		$iMoved = 0;
		foreach ($aDue as $aMessages) {
			$oDueUids = new SequenceSet(\array_keys($aMessages));
			try {
				foreach ($aMessages as $iUid => $aMessage) {
					$oActions->MailClient()->MessageSetFlag(
						$sSnoozedFolder,
						new SequenceSet([$iUid]),
						$aMessage['flag'],
						false,
						true
					);
				}
				$oActions->MailClient()->MessageSetFlag($sSnoozedFolder, $oDueUids, 'Snoozed', true, true);
				$oActions->MailClient()->MessageSetFlag($sSnoozedFolder, $oDueUids, MessageFlag::SEEN, false, true);
				$oActions->ImapClient()->MessageMove($sSnoozedFolder, $sInboxFolder, $oDueUids);
			} catch (\Throwable $oException) {
				foreach ($aMessages as $iUid => $aMessage) try {
					$oUid = new SequenceSet([$iUid]);
					$oActions->MailClient()->MessageSetFlag($sSnoozedFolder, $oUid, 'Snoozed', false, true);
					$oActions->MailClient()->MessageSetFlag(
						$sSnoozedFolder,
						$oUid,
						$aMessage['flag'],
						true,
						true
					);
					$oActions->MailClient()->MessageSetFlag(
						$sSnoozedFolder,
						$oUid,
						MessageFlag::SEEN,
						$aMessage['seen'],
						true
					);
				} catch (\Throwable $oRollbackException) {
					$this->Manager()->logException($oRollbackException, \LOG_ERR);
				}
				throw new ClientException(Notifications::CantMoveMessage, $oException);
			}
			$iMoved += \count($aMessages);
		}
		return $iMoved;
	}
}
