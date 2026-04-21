<?php

namespace Adsniper\SymfonyMessengerBridge;

use HaydenPierce\ClassFinder\ClassFinder;
use LogicException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Contracts\Cache\CacheInterface;

class MessengerBridge
{
	private static ?ContainerInterface $container = null;

	private static ?TransportInterface $asyncTransport = null;

	public static function setContainer(ContainerInterface $container): void
	{
		self::$container = $container;
	}

	public static function setAsyncTransport(TransportInterface $asyncTransport): void
	{
		self::$asyncTransport = $asyncTransport;
	}

	public static function createBusByNamespace(string $namespace, ?CacheInterface $autodiscoveryCache = null): MessageBusInterface
	{
		self::containerNeeded();

		ClassFinder::disablePSR4Vendors();

		$classes = ClassFinder::getClassesInNamespace($namespace, ClassFinder::RECURSIVE_MODE);
		$cache = $autodiscoveryCache ?? new ArrayAdapter();
		$factory = new AutodiscoveryHandlersLocatorFactory(self::$container, $cache, $classes);

		$middlewares = [
			new AddBusNameStampMiddleware("default"), // Needed for RoutableMessageBus
		];

		if (self::$asyncTransport !== null) {
			$middlewares[] = new SendMessageMiddleware(
				$factory->createSendersLocator([
					"async" => self::$asyncTransport
				])
			);
		}

		$middlewares[] = new HandleMessageMiddleware($factory->createHandlersLocator());

		return new MessageBus($middlewares);
	}

	public static function createConsumeMessagesCommandForSingleBus(MessageBusInterface $messageBus): ConsumeMessagesCommand
	{
		$receivers = [];

		if (self::$asyncTransport !== null) {
			$receivers["async"] = self::$asyncTransport;
		}

		$command = new ConsumeMessagesCommand(
			new RoutableMessageBus(
				new class($messageBus) implements ContainerInterface {
					public function __construct(
						private MessageBusInterface $messageBus
					) {
					}

					public function has(string $id): bool
					{
						return true;
					}

					public function get(string $id)
					{
						return $this->messageBus;
					}
				}
			),
			new ArrayContainer($receivers),
			new EventDispatcher()
		);

		return $command;
	}

	private static function containerNeeded(): void
	{
		if (self::$container !== null) {
			return;
		}

		throw new LogicException("Container not set");
	}
}
