<?php

class FloatingComposerPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Floating Composer',
		AUTHOR   = 'Maxime Patteri',
		URL      = 'https://github.com/the-djmaze/snappymail',
		VERSION  = '1.0',
		RELEASE  = '2026-11-23',
		REQUIRED = '2.38.0',
		CATEGORY = 'General',
		LICENSE  = 'MIT',
		DESCRIPTION = 'Compose in a small window at the bottom right, like Gmail: the mailbox stays usable while writing';

	public function Init() : void
	{
		$this->addCss('floating-composer.css');
		$this->addJs('floating-composer.js');
	}
}
