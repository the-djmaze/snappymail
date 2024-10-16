<?php

class CompactComposerPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME = 'Compact Composer',
		AUTHOR = 'Sergey Mosin',
		URL = 'https://github.com/the-djmaze/snappymail/pull/1466',
		VERSION = '1.0.5',
		RELEASE = '2024-08-08',
		REQUIRED = '2.34.0',
		LICENSE = 'AGPL v3',
		DESCRIPTION = 'WYSIWYG editor with a compact toolbar';

	public function Init(): void
	{
		$this->addCss('css/composer.css');
		$this->addJs('js/squire-raw.js');
		$this->addJs('js/parsel.js');
		$this->addJs('js/CompactComposer.js');
	}
}
