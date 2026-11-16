<?php

namespace RainLoop\Enumerations;

enum PluginPropertyType: int
{
	case STRING = 0;
	case INT = 1;
	case STRING_TEXT = 2;
	case PASSWORD = 3;
	case SELECTION = 4;
	case BOOL = 5;
	case URL = 6;
	case GROUP = 7;
	case SELECT = 8;
}
