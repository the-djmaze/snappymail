<?php

function json_validate(string $json, int $depth = 512, int $flags = 0): bool
{
	if (0 !== $flags && \JSON_INVALID_UTF8_IGNORE !== $flags) {
		throw new \ValueError('json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)');
	}

	if ($depth <= 0) {
		throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
	}

	if ($depth > 2147483647) {
		throw new \ValueError('json_validate(): Argument #2 ($depth) must be less than 2147483647');
	}

	json_decode($json, true, $depth, $flags);

	return \JSON_ERROR_NONE === json_last_error();
}
