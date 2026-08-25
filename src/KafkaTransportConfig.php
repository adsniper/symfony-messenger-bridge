<?php

namespace Adsniper\SymfonyMessengerBridge;

use Closure;

readonly class KafkaTransportConfig
{
	/**
	 * @param null|pure-Closure(string): string $formatConsumerInstance
	 */
	public function __construct(
		public string $host,
		public string $topic,
		public string $group,
		public string $consumerInstancePrefix,
		public KafkaAutoOffsetReset $autoOffsetReset = KafkaAutoOffsetReset::EARLIEST,
		public bool $autoCommit = false,
		public string $messageKeyPrefix = "",
		public ?Closure $formatConsumerInstance = null
	) {
	}

	/**
	 * @internal
	 * @param array<string, mixed> $props
	 */
	public function with(array $props): static
	{
		$copy = clone $this;

		foreach ($props as $name => $value) {
			if (!property_exists($copy, $name)) continue;

			$copy->$name = $value;
		}

		return $copy;
	}
}
