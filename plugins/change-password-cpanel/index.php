<?php

class ChangePasswordCPanelPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Change Password cPanel',
		VERSION  = '2.38',
		RELEASE  = '2026-11-16',
		REQUIRED = '2.36.1',
		CATEGORY = 'Security',
		DESCRIPTION = 'Extension to allow users to change their passwords through cPanel';

	public function Supported() : string
	{
		return 'Use Change Password plugin';
	}
}
