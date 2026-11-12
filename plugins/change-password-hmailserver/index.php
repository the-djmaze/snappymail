<?php

class ChangePasswordHMailServerPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Change Password hMailServer',
		VERSION  = '2.37',
		RELEASE  = '2026-11-12',
		REQUIRED = '2.36.1',
		CATEGORY = 'Security',
		DESCRIPTION = 'Extension to allow users to change their passwords through hMailServer';

	public function Supported() : string
	{
		return 'Activate in Change Password plugin'
			. (\class_exists('COM') ? '' : '. And you must install PHP COM extension');
	}
}
