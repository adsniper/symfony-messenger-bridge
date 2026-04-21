<?php

namespace Adsniper\SymfonyMessengerBridge;

use GuzzleHttp\Client;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class KafkaTransportBuilder
{
	private ?KafkaTransportConfig $config = null;

	private ?LoggerInterface $logger = null;

	private ?ClientInterface $client = null;

	private ?SerializerInterface $serializer = null;

	public function setConfig(KafkaTransportConfig $config): self
	{
		$this->config = $config;
		return $this;
	}

	public function setLogger(LoggerInterface $logger): self
	{
		$this->logger = $logger;
		return $this;
	}

	public function setClient(ClientInterface $client): self
	{
		$this->client = $client;
		return $this;
	}

	public function setSerializer(SerializerInterface $serializer): self
	{
		$this->serializer = $serializer;
		return $this;
	}

	public function build(): TransportInterface
	{
		if ($this->config === null) {
			throw new LogicException("No transport config was given");
		}

		$logger = $this->logger ?? new NullLogger();
		$client = $this->client ?? new Client();
		$serializer = $this->serializer ?? new PhpSerializer();

		return new KafkaTransport(
			$this->config,
			$logger,
			$client,
			$serializer
		);
	}
}
