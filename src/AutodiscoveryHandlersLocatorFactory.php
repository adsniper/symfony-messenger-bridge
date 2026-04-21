<?php

namespace Adsniper\SymfonyMessengerBridge;

use Exception;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Serializable;
use stdClass;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AutodiscoveryHandlersLocatorFactory
{
	private const CACHE_PREFIX = "message-bus-locator-factory-map";

	private ?HandlerMap $map = null;

	/**
	 * @param iterable<class-string> $targetClasses
	 */
	public function __construct(
		private ContainerInterface $container,
		private CacheInterface $cache,
		private iterable $targetClasses = []
	) {
	}

	public function createHandlersLocator(): HandlersLocatorInterface
	{
		return new HandlersLocator($this->getMap()->getMap());
	}

	/**
	 * @param array<string, TransportInterface> $transports
	 */
	public function createSendersLocator(array $transports): SendersLocatorInterface
	{
		return new SendersLocator($this->getMap()->getSenders(), new ArrayContainer($transports));
	}

	private function getMap(): HandlerMap
	{
		if ($this->map === null) {
			$this->map = $this->cache->get(
				self::CACHE_PREFIX,
				function (ItemInterface $item) {
					return $this->discoverAndBuild();
				}
			);
		}

		return $this->map;
	}

	private function discoverAndBuild(): HandlerMap
	{
		$map = new HandlerMap($this->container);

		foreach ($this->targetClasses as $class) {
			$class = new ReflectionClass($class);
			$classAttrs = $class->getAttributes(AsMessageHandler::class);

			if (count($classAttrs) > 0) {
				foreach ($classAttrs as $attr) {
					$this->handleAttribute($map, $attr, $class);
				}
				continue;
			}

			foreach ($class->getMethods() as $method) {
				$attrs = $method->getAttributes(AsMessageHandler::class);

				if (count($attrs) < 1) {
					continue;
				}

				foreach ($attrs as $attr) {
					$this->handleAttribute($map, $attr, $method);
				}
			}
		}

		return $map;
	}

	/**
	 * @param ReflectionAttribute<AsMessageHandler> $attr
	 * @param ReflectionClass<object>|ReflectionMethod $target
	 */
	private function handleAttribute(HandlerMap $map, ReflectionAttribute $attr, ReflectionClass|ReflectionMethod $target): void
	{
		$args = $attr->getArguments();

		if ($target instanceof ReflectionClass) {
			$methodName = $args["method"] ?? "__invoke";
			$target = $target->getMethod($methodName);
		}

		$params = $target->getParameters();

		if (count($params) !== 1) {
			throw new Exception(
				"message handler should be a callable with one param that is a message"
			);
		}

		$type = $params[0]->getType();

		if ($type === null) {
			throw new Exception("message handler param type is not defined");
		}

		$messageClass = $type->getName();

		if (!class_exists($messageClass)) {
			throw new Exception(
				"message handler param should be a custom class, but it is: " . $messageClass
			);
		}

		$map->addHandler(
			$messageClass,
			[
				$this->container->get($target->getDeclaringClass()->getName()),
				$target->getName()
			],
			$args[0] ?? $args["bus"] ?? null
		);
	}
}

class HandlerMap implements Serializable
{
	/** @var array<class-string, callable[]> */
	private array $handlers = [];

	/** @var array<class-string,> */
	private array $senders = [];

	public function __construct(
		private ContainerInterface $container
	) {
	}

	public function addHandler(string $message, callable $handler, ?string $bus): self
	{
		if (!isset($this->handlers[$message])) {
			$this->handlers[$message] = [];
		}

		$this->handlers[$message][] = $handler;

		if ($bus === null) {
			return $this;
		}

		if (!isset($this->senders[$message])) {
			$this->senders[$message] = [];
		}

		$this->senders[$message][] = $bus;
		return $this;
	}

	public function getMap(): array
	{
		return $this->handlers;
	}

	public function getSenders(): array
	{
		return $this->senders;
	}

	public function serialize(): ?string
	{
		$res = [];

		foreach ($this->handlers as $message => $handlers) {
			$res[$message] = [];

			foreach ($handlers as $handler) {
				if (is_array($handler)) {
					assert(count($handler) === 2);
					assert(array_is_list($handler));
					
					$res[$message][] = new ArrayCallable(
						$this->container,
						$handler[0],
						$handler[1] ?? null
					);
					continue;
				}

				throw new Exception("unknown or unsupported callable type");
			}
		}

		return serialize(["handlers" => $res, "senders" => $this->senders]);
	}

	public function unserialize(string $data): void
	{
		$data = unserialize($data);
		$res = $data["handlers"];

		foreach ($res as $message => $handlers) {
			$this->handlers[$message] = [];

			foreach ($handlers as $callableObj) {
				$this->handlers[$message][] = $callableObj->toCallable();
			}
		}

		$this->senders = $data["senders"];
	}
}

interface CallableObject
{
	public function toCallable(): callable;
}

readonly class ArrayCallable implements CallableObject, Serializable
{
	public function __construct(
		private ContainerInterface $container,
		public object $obj,
		public ?string $method = null
	) {
		if ($this->obj instanceof stdClass) {
			throw new LogicException("stdClass as handler is not supported");
		}

		assert(class_exists(get_class($this->obj)));

		if ($this->method !== null) {
			assert(method_exists($this->obj, $this->method));
		}
	}

	public function toCallable(): callable
	{
		return [$this->obj, $this->method];
	}

	public function serialize(): ?string
	{
		return serialize(["class" => get_class($this->obj), "method" => $this->method]);
	}

	public function unserialize(string $data): void
	{
		["class" => $class, "method" => $method] = unserialize($data);

		$this->obj = $this->container->get($class);
		$this->method = $method;
	}
}
