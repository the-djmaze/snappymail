<?php

namespace RainLoop\Providers\Storage\Enumerations;

enum StorageType
{
	case CONFIG;
	case NOBODY;
	case SIGN_ME;
	case SESSION;
	case PGP;
	case ROOT;
}
