<?php

namespace Adsniper\SymfonyMessengerBridge;

use Symfony\Component\Messenger\Stamp\StampInterface;

class KafkaMessageKeyStamp implements StampInterface
{
	public function __construct(
		public string $key
	) {
	}
}
