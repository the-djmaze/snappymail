<?php

namespace RainLoop\Providers\AddressBook;

use
	RainLoop\Providers\AddressBook\Enumerations\PropertyType,
	RainLoop\Providers\AddressBook\Classes\Property
;

class Legacy
{
	private static function yieldPropertyHelper(mixed $oArrayProp, int $iType): iterable
	{
		$aTmp = [];
		foreach ($oArrayProp as $oProp) {
			$sValue = \trim($oProp->getValue());
			if (\strlen($sValue)) {
				$oTypes = $oProp['TYPE'];
				$aTypes = $oTypes ? $oTypes->getParts() : array();
				$pref = empty($oProp['PREF']) ? 100 : \min(100, \max(1, $oProp['PREF']->getValue()));
				$pref = \str_pad($pref, 3, '0', \STR_PAD_LEFT);
				$aTmp[$pref . $sValue] = new Property($iType, $sValue, \implode(',', $aTypes));
			}
		}
		\ksort($aTmp);
		foreach ($aTmp as $oProp) {
			yield $oProp;
		}
	}

	private static function getPropertyValueHelper(mixed $oProp, bool $bOldVersion): string
	{
		$sValue = \trim($oProp);
		if ($bOldVersion && !isset($oProp->parameters['CHARSET'])) {
			if (\strlen($sValue)) {
				$sEncValue = \mb_convert_encoding($sValue, 'UTF-8', 'ISO-8859-1');
				if (\strlen($sEncValue)) {
					$sValue = $sEncValue;
				}
			}
		}
		return \MailSo\Base\Utils::Utf8Clear($sValue);
	}

	public static function VCardToProperties(\Sabre\VObject\Component\VCard $oVCard): iterable
	{
		yield new Property(PropertyType::JCARD, \json_encode($oVCard));

		$bOldVersion = !empty($oVCard->VERSION) && \in_array((string) $oVCard->VERSION, array('2.1', '2.0', '1.0'));

		if (isset($oVCard->FN) && '' !== \trim($oVCard->FN)) {
			$sValue = static::getPropertyValueHelper($oVCard->FN, $bOldVersion);
			yield new Property(PropertyType::FULLNAME, $sValue);
		}

		if (isset($oVCard->N)) {
			$aNames = $oVCard->N->getParts();
			foreach ($aNames as $iIndex => $sValue) {
				$sValue = \trim($sValue);
				if ($bOldVersion && !isset($oVCard->N->parameters['CHARSET'])) {
					if (\strlen($sValue)) {
						$sEncValue = \mb_convert_encoding($sValue, 'UTF-8', 'ISO-8859-1');
						if (\strlen($sEncValue)) {
							$sValue = $sEncValue;
						}
					}
				}
				$sValue = \MailSo\Base\Utils::Utf8Clear($sValue);
				if ($sValue) {
					switch ($iIndex) {
						case 0:
							yield new Property(PropertyType::LAST_NAME, $sValue);
							break;
						case 1:
							yield new Property(PropertyType::FIRST_NAME, $sValue);
							break;
						case 2:
							yield new Property(PropertyType::MIDDLE_NAME, $sValue);
							break;
						case 3:
							yield new Property(PropertyType::NAME_PREFIX, $sValue);
							break;
						case 4:
							yield new Property(PropertyType::NAME_SUFFIX, $sValue);
							break;
					}
				}
			}
		}

		if (isset($oVCard->EMAIL)) {
			yield from static::yieldPropertyHelper($oVCard->EMAIL, PropertyType::EMAIl);
		}

		if (isset($oVCard->URL)) {
			yield from static::yieldPropertyHelper($oVCard->URL, PropertyType::WEB_PAGE);
		}

		if (isset($oVCard->TEL)) {
			yield from static::yieldPropertyHelper($oVCard->TEL, PropertyType::PHONE);
		}
	}

	public static function PropertiesToVCard(array &$aContacts, \PDOStatement $oStmt)
	{
		$aVCards = array();
		$sUid = $sFirstName = $sLastName = $sMiddleName = $sSuffix = $sPrefix = '';
		$iPrevId = 0;

		while ($aItem = $oStmt->fetch(\PDO::FETCH_ASSOC)) {
			if ($aItem && isset($aItem['id_prop'], $aItem['id_contact'], $aItem['prop_type'], $aItem['prop_value'])) {
				$iId = (int) $aItem['id_contact'];
				if (0 < $iId) {
					if ($iPrevId != $iId) {
						if ($iPrevId) {
							$aVCards[$iPrevId]->UID = $sUid ?: \SnappyMail\UUID::generate();
							$aVCards[$iPrevId]->N = array($sLastName, $sFirstName, $sMiddleName, $sPrefix, $sSuffix);
						}
						$sUid = $sFirstName = $sLastName = $sMiddleName = $sSuffix = $sPrefix = '';
						$iPrevId = $iId;
					}
					if (isset($aVCards[$iId])) {
						$oVCard = $aVCards[$iId];
					} else {
						$oVCard = new VCard;
//						$oVCard = $oVCard->convert(VCard::VCARD40);
						$oVCard->VERSION = '4.0';
						$oVCard->PRODID = 'SnappyMail-'.APP_VERSION;
						$aVCards[$iId] = $oVCard;
					}
					$oVCard = $aVCards[$iId];
					$sPropValue = (string) $aItem['prop_value'];
					$aTypes = array();
					if (!empty($aItem['prop_type_str'])) {
						$aTypes = \explode(',', \preg_replace('/[\s]+/', '', $aItem['prop_type_str']));
					}
					switch ((int) $aItem['prop_type'])
					{
						case PropertyType::JCARD:
							break;

						case PropertyType::FULLNAME:
							$oVCard->FN = $sPropValue;
							break;
						case PropertyType::NICK_NAME:
							$oVCard->NICKNAME = $sPropValue;
							break;
						case PropertyType::NOTE:
							$oVCard->NOTE = $sPropValue;
							break;
						case PropertyType::UID:
							$sUid = $sPropValue;
							break;
						case PropertyType::FIRST_NAME:
							$sFirstName = $sPropValue;
							break;
						case PropertyType::LAST_NAME:
							$sLastName = $sPropValue;
							break;
						case PropertyType::MIDDLE_NAME:
							$sMiddleName = $sPropValue;
							break;
						case PropertyType::NAME_SUFFIX:
							$sSuffix = $sPropValue;
							break;
						case PropertyType::NAME_PREFIX:
							$sPrefix = $sPropValue;
							break;

						case PropertyType::EMAIl:
							$oVCard->add('EMAIL', $sPropValue, \is_array($aTypes) && \count($aTypes) ? array('TYPE' => $aTypes) : null);
							break;

						case PropertyType::WEB_PAGE:
							$oVCard->add('URL', $sPropValue, \is_array($aTypes) && \count($aTypes) ? array('TYPE' => $aTypes) : null);
							break;

						case PropertyType::PHONE:
							$oVCard->add('TEL', $sPropValue, \is_array($aTypes) && \count($aTypes) ? array('TYPE' => $aTypes) : null);
							break;
					}
				}
			}
		}
		$oStmt->closeCursor();

		if ($iPrevId) {
			$aVCards[$iPrevId]->UID = $sUid ?: \SnappyMail\UUID::generate();
			$aVCards[$iPrevId]->N = array($sLastName, $sFirstName, $sMiddleName, $sPrefix, $sSuffix);
		}

		foreach ($aVCards as $iId => $oVCard) {
			$oVCard->REV = \gmdate('Ymd\\THis\\Z', $aContacts[$iId]->Changed);
			$aContacts[$iId]->setVCard($oVCard);
		}
	}

}
