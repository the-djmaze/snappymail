<?php

namespace RainLoop\Enumerations;

enum SignMeType: string
{
	case DefaultOff = 'DefaultOff';
	case DefaultOn = 'DefaultOn';
	case Unused = 'Unused';

	public function toInt(): int
	{
		return \array_search($this, self::cases(), true);
		return match ($this) {
			SignMeType::DefaultOff => 0,
			SignMeType::DefaultOn => 1,
			SignMeType::Unused => 2
		};
	}
}
