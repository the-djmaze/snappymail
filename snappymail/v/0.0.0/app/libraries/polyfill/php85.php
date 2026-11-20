<?php

function array_first(array $array): mixed
{
	return $array[0] ?? null;
//	return $array === [] ? null : $array[\array_key_first($array)];
}
