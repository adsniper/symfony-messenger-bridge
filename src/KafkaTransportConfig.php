<?php

namespace Adsniper\SymfonyMessengerBridge;

readonly class KafkaTransportConfig
{
	public function __construct(
		public string $host,
		public string $topic,
		public string $group,
		public string $consumerInstancePrefix,
		public KafkaAutoOffsetReset $autoOffsetReset = KafkaAutoOffsetReset::EARLIEST,
		public bool $autoCommit = false,
		public string $messageKeyPrefix = ""
	) {
	}
}
