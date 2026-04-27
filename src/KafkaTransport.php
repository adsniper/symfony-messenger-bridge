<?php

namespace Adsniper\SymfonyMessengerBridge;

use Composer\InstalledVersions;
use Exception;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class KafkaTransport implements TransportInterface, KeepaliveReceiverInterface
{
	private QueueRestClient $client;

	private ?string $consumerInstance = null;

	public function __construct(
		private KafkaTransportConfig $config,
		private LoggerInterface $logger,
		ClientInterface $client,
		private SerializerInterface $serializer
	) {
		$messengerVersion = InstalledVersions::getVersion("symfony/messenger");

		if (version_compare($messengerVersion, "7.2", "<")) {
			throw new LogicException(
				"KafkaTransport can only be used with symfony/messenger >= 7.2"
			);
		}

		$this->client = (new QueueRestClient($client, [
			"dev" => false,
			"host" => $config->host
		]))->setLogger($this->logger);
	}

	public function send(Envelope $envelope): Envelope
	{
		$this->produceMessage(
			$this->config->messageKeyPrefix . uniqid(),
			$this->serializer->encode($envelope)
		);

		return $envelope;
	}

	public function get(): iterable
	{
		$this->createConsumerInstance();

		$messages = $this->getRecords();

		if (count($messages) < 1) {
			return [];
		}

		$envelopes = array_map(
			function (array $message): Envelope {
				$envelope = $this->serializer->decode($message["value"]);
				return $envelope->with(
					new KafkaMessageKeyStamp($message["key"]),
					new KafkaMessagePositionStamp(
						$message['partition'], $message['offset']
					)
				);
			},
			$messages
		);

		return $envelopes;
	}

	public function keepalive(Envelope $envelope, ?int $seconds = null): void
	{
		$this->commit($envelope);
	}

	public function ack(Envelope $envelope): void
	{
		$this->commit($envelope);
	}

	public function reject(Envelope $envelope): void
	{
		$this->commit($envelope);
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * @param array<mixed> $message
	 */
	public function produceMessage(?string $key, array $message): void
	{
		$this->client->exec(
			'POST',
			'/topics/' . $this->getTopic(),
			[
				'records' => [
					[
						'key' => $key,
						'value' => $message
					]
				]
			],
			[
				'Content-Type' => 'application/vnd.kafka.json.v2+json',
				'Accept' => 'application/vnd.kafka.v2+json, application/vnd.kafka+json, application/json',
			]
		);
	}

	private function createConsumerInstance(): void
	{
		if ($this->consumerInstance !== null) {
			return;
		}

		$data = $this->client->exec(
			'POST',
			"/consumers/{$this->getGroup()}/",
			$this->getConsumerInstanceConfig(),
			['Content-Type' => 'application/vnd.kafka.v2+json']
		);

		$res = json_decode($data, true);
		$this->consumerInstance = $res['instance_id'];

		$this->subscribeToTopic();
	}

	private function subscribeToTopic(): void
	{
		$this->shouldSetConsumerInstance();

		$this->client->exec(
			"POST",
			"/consumers/{$this->getGroup()}/instances/{$this->consumerInstance}/subscription",
			[
				'topics' => [$this->getTopic()],
			],
			['Content-Type' => 'application/vnd.kafka.v2+json']
		);
	}

	/**
	 * @return array<mixed>[]
	 */
	private function getRecords(): array
	{
		$this->shouldSetConsumerInstance();

		$request = sprintf(
			"/consumers/%s/instances/%s/records?max_bytes=%s&timeout=%s",
			$this->getGroup(),
			$this->consumerInstance,
			3000,
			3000
		);

		$response = $this->client->exec(
			'GET',
			$request,
			[],
			['Accept' => 'application/vnd.kafka.json.v2+json']
		);

		return json_decode($response, true);
	}

	private function commit(Envelope $envelope): void
	{
		$this->shouldSetConsumerInstance();

		$pos = $envelope->last(KafkaMessagePositionStamp::class);

		$this->client->exec(
			'POST',
			"/consumers/{$this->getGroup()}/instances/{$this->consumerInstance}/offsets",
			[
				'offsets' => [
					[
						'topic' => $this->getTopic(),
						'partition' => $pos->partition,
						'offset' => $pos->offset,
					]
				]
			],
			['Content-Type' => 'application/vnd.kafka.v2+json']
		);
	}

	private function shouldSetConsumerInstance(): void
	{
		if ($this->consumerInstance === null) {
			throw new Exception("need to create consumer instance first");
		}
	}

	public function close(): void
	{
		if ($this->consumerInstance === null) {
			return;
		}

		$this->client->execSilent(
			'DELETE',
			"/consumers/{$this->getGroup()}/instances/{$this->getConsumerInstance()}",
			['Content-Type' => 'application/vnd.kafka.v2+json']
		);

		$this->consumerInstance = null;
	}

	protected function getTopic(): string
	{
		$topic = $this->config->topic;

		if (!$topic) {
			throw new LogicException('Topic was not given');
		}

		return $topic;
	}

	protected function getGroup(): string
	{
		$group = $this->config->group;

		if (!$group) {
			throw new LogicException('Consumer group not set');
		}

		return $group;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function getConsumerInstanceConfig(): array
	{
		return [
			'name' => $this->getConsumerInstance(),
			'format' => 'json',
			'auto.offset.reset' => match ($this->config->autoOffsetReset) {
				KafkaAutoOffsetReset::EARLIEST => 'earliest',
				KafkaAutoOffsetReset::LATEST => 'latest',
			},
			'auto.commit.enable' => $this->config->autoCommit ? 'true' : 'false'
		];
	}

	protected function getConsumerInstance(): string
	{
		if ($this->consumerInstance !== null) {
			return $this->consumerInstance;
		}

		if ($this->config->formatConsumerInstance !== null) {
			$inst = $this->config
				->formatConsumerInstance
				->call($this, $this->config->consumerInstancePrefix);
		} else {
			$inst = $this->config->consumerInstancePrefix . "_" . uniqid();
		}

		$this->consumerInstance = $inst;
		return $inst;
	}
}
