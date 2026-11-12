<?php

class ChangePasswordHestiaPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Change Password Hestia',
		AUTHOR   = 'Jaap Marcus',
		VERSION  = '2.37',
		RELEASE  = '2026-11-12',
		REQUIRED = '2.36.1',
		CATEGORY = 'Security',
		DESCRIPTION = 'Extension to allow users to change their passwords through HestiaCP';

	public function Supported() : string
	{
		return 'Use Change Password plugin';
	}
}
