<?php declare(strict_types=1);

namespace Adsniper\SymfonyMessengerBridge;

use Exception;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
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

		$this->map->setContainer($this->container);

		return $this->map;
	}

	private function discoverAndBuild(): HandlerMap
	{
		$map = new HandlerMap();

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
			[$target->getDeclaringClass()->getName(), $target->getName()],
			$args[0] ?? $args["bus"] ?? null
		);
	}
}

class HandlerMap
{
	/** @var array<class-string, callable[]> */
	private array $handlers = [];

	/** @var array<class-string,> */
	private array $senders = [];

	private ?ContainerInterface $container = null;

	public function setContainer(ContainerInterface $container): self
	{
		$this->container = $container;
		return $this;
	}

	public function addHandler(string $message, callable|array $handler, ?string $bus): self
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
		if ($this->container === null) {
			throw new LogicException("No container set");
		}

		$resolved = [];

		foreach ($this->handlers as $message => $handlers) {
			$resolved[$message] = [];

			foreach ($handlers as $handler) {
				if ($handler instanceof CallableObject) {
					$resolved[$message][] = $handler->toCallable($this->container);
					continue;
				}

				if (is_array($handler)) {
					$resolved[$message][] = [$this->container->get($handler[0]), $handler[1]];
					continue;
				}

				$resolved[$message][] = $handler;
			}
		}

		return $resolved;
	}

	public function getSenders(): array
	{
		return $this->senders;
	}

	public function __serialize(): array
	{
		$res = [];

		foreach ($this->handlers as $message => $handlers) {
			$res[$message] = [];

			foreach ($handlers as $handler) {
				if (is_array($handler)) {
					assert(count($handler) === 2);
					assert(array_is_list($handler));
					
					$res[$message][] = new ArrayCallable(
						$handler[0],
						$handler[1] ?? null
					);
					continue;
				}

				throw new Exception("unknown or unsupported callable type");
			}
		}

		return ["handlers" => $res, "senders" => $this->senders];
	}

	public function __unserialize(array $data): void
	{
		$res = $data["handlers"];

		foreach ($res as $message => $handlers) {
			$this->handlers[$message] = $handlers;
		}

		$this->senders = $data["senders"];
	}
}

/**
 * @internal
 */
interface CallableObject
{
	public function toCallable(ContainerInterface $container): callable;
}

/**
 * @internal
 */
class ArrayCallable implements CallableObject
{
	public function __construct(
		public string $class,
		public ?string $method = null
	) {
	}

	public function toCallable(ContainerInterface $classLocator): callable
	{
		return [$classLocator->get($this->class), $this->method];
	}
}
