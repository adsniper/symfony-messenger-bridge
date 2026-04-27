<?php

namespace Adsniper\SymfonyMessengerBridge;

use Symfony\Component\Messenger\Stamp\StampInterface;

class KafkaMessagePositionStamp implements StampInterface
{
	public function __construct(
		public int $partition,
		public int $offset
	) {
	}
}
