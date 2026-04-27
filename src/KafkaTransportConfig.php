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
}
