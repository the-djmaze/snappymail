<?php

namespace RainLoop\Plugins;

//class PropertyCollection extends \MailSo\Base\Collection
class PropertyCollection extends \ArrayObject implements \JsonSerializable
{
	private string $sLabel;

	function __construct(string $sLabel)
	{
		$this->sLabel = $sLabel;
	}

//	public function jsonSerialize(): array
	public function jsonSerialize(): mixed
	{
		return array(
			'@Object' => 'Object/PluginProperty',
			'type' => \RainLoop\Enumerations\PluginPropertyType::GROUP,
			'label' => $this->sLabel,
			'config' => $this->getArrayCopy()
/*
			'config' => [
				'@Object' => 'Collection/PropertyCollection',
				'@Collection' => $this->getArrayCopy(),
			]
*/
		);
	}
}
