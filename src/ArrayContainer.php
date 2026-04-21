<?php

namespace Adsniper\SymfonyMessengerBridge;

use Psr\Container\ContainerInterface;

class ArrayContainer implements ContainerInterface
{
	/**
	 * @param array<string, mixed> $services
	 */
	public function __construct(
		private array $services
	) {
	}

	public function has(string $id): bool
	{
		return isset($this->services[$id]);
	}

	public function get(string $id)
	{
		return $this->services[$id];
	}
}
