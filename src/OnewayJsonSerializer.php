<?php

namespace Adsniper\SymfonyMessengerBridge;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class OnewayJsonSerializer implements SerializerInterface
{
	public function encode(Envelope $envelope): array
	{
		return json_decode(json_encode($envelope->getMessage()), true);
	}

	public function decode(array $encodedEnvelope): Envelope
	{
		throw new \Exception('Oneway json serializer does not support decoding');
	}
}
