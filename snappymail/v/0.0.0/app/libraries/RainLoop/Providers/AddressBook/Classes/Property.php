<?php

namespace RainLoop\Providers\AddressBook\Classes;

use RainLoop\Providers\AddressBook\Enumerations\PropertyType;

class Property implements \JsonSerializable
{
	public int $IdProperty = 0;

	public int $Type;

	public string $TypeStr;

	public string $Value;

	public int $Frec = 0;

	public function __construct(int $iType = PropertyType::UNKNOWN, string $sValue = '', string $sTypeStr = '')
	{
		$this->Type = $iType;
		$this->Value = $sValue;
		$this->TypeStr = $sTypeStr;
	}

	public function jsonSerialize(): mixed
	{
		throw new \Exception('Obsolete ' . __CLASS__ . '::jsonSerialize()');
	}
}
