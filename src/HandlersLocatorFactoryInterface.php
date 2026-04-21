<?php

namespace Adsniper\SymfonyMessengerBridge;

use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

interface HandlersLocatorFactoryInterface
{
	public function createHandlersLocator(): HandlersLocatorInterface;

	/**
	 * @param array<string, TransportInterface> $transports
	 */
	public function createSendersLocator(array $transports): SendersLocatorInterface;
}
