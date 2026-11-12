<?php

namespace SnappyMail;

class SensitiveString implements \Stringable, \JsonSerializable
{
	private \SensitiveParameterValue $value;

	public function __construct(
		#[\SensitiveParameter]
		string $value
	)
	{
		$this->setValue($value);
	}

	public function getValue(): string
	{
		return $this->value->getValue();
	}

	public function setValue(
		#[\SensitiveParameter]
		string $value
	): void
	{
		\strlen($value) && \RainLoop\Api::Actions()->logMask($value);
		$this->value = new \SensitiveParameterValue($value);
	}

	public function length(): int
	{
		return \strlen($this->value->getValue());
	}

	public function __toString(): string
	{
		$caller = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,1)[0];
		\trigger_error("SensitiveString::__toString deprecated use SensitiveString::getValue() at {$caller['file']}#{$caller['line']}", E_USER_DEPRECATED);
		return $this->value->getValue();
	}

	public function jsonSerialize(): mixed
	{
		throw new \Exception("JSON serialization of 'SnappyMail\\SensitiveString' is not allowed");
	}

	public function __debugInfo(): array
	{
		return [];
	}

	public function __sleep(): array
	{
		return [];
	}

	public function __serialize(): array
	{
		throw new \Exception("Serialization of 'SnappyMail\\SensitiveString' is not allowed");
	}

	public function __unserialize(array $data): void
	{
		throw new \Exception("Unserialization of 'SnappyMail\\SensitiveString' is not allowed");
	}
}
