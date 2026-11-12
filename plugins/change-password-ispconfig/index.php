<?php

class ChangePasswordISPConfigPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME     = 'Change Password ISPConfig',
		VERSION  = '2.38',
		RELEASE  = '2026-11-12',
		REQUIRED = '2.36.1',
		CATEGORY = 'Security',
		DESCRIPTION = 'Extension to allow users to change their passwords through ISPConfig';

	public function Supported() : string
	{
		return 'Use Change Password plugin';
	}
}
