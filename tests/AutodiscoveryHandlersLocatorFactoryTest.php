<?php declare(strict_types=1);

namespace Adsniper\SymfonyMessengerBridge\Tests;

use Adsniper\SymfonyMessengerBridge\ArrayContainer;
use Adsniper\SymfonyMessengerBridge\AutodiscoveryHandlersLocatorFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class AutodiscoveryHandlersLocatorFactoryTest extends TestCase
{
    private NullAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new NullAdapter();
    }

    public function testCreateHandlersLocatorReturnsHandlersLocatorInterface(): void
    {
        $container = new ArrayContainer([]);
        $factory = new AutodiscoveryHandlersLocatorFactory($container, $this->cache, []);

        $result = $factory->createHandlersLocator();

        $this->assertInstanceOf(HandlersLocatorInterface::class, $result);
    }

    public function testCreateSendersLocatorReturnsSendersLocatorInterface(): void
    {
        $container = new ArrayContainer([]);
        $factory = new AutodiscoveryHandlersLocatorFactory($container, $this->cache, []);

        $transports = ['default' => $this->createMock(TransportInterface::class)];
        $result = $factory->createSendersLocator($transports);

        $this->assertInstanceOf(SendersLocatorInterface::class, $result);
    }

    public function testDiscoversClassLevelAttribute(): void
    {
        $container = new ArrayContainer([
            ClassLevelHandler::class => new ClassLevelHandler(),
        ]);

        $factory = new AutodiscoveryHandlersLocatorFactory($container, $this->cache, [ClassLevelHandler::class]);
        $locator = $factory->createHandlersLocator();

        $this->assertHandlerDiscovered($locator, ClassLevelHandler::class, '__invoke');
    }

    public function testDiscoversMethodLevelAttribute(): void
    {
        $container = new ArrayContainer([
            MethodLevelHandler::class => new MethodLevelHandler(),
        ]);

        $factory = new AutodiscoveryHandlersLocatorFactory($container, $this->cache, [MethodLevelHandler::class]);
        $locator = $factory->createHandlersLocator();

        $this->assertHandlerDiscovered($locator, MethodLevelHandler::class, 'handle');
    }

    public function testHandlerWithInvalidParamCountThrowsException(): void
    {
        $container = new ArrayContainer([]);
        $factory = new AutodiscoveryHandlersLocatorFactory($container, $this->cache, [InvalidParamCountHandler::class]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('message handler should be a callable with one param that is a message');

        $factory->createHandlersLocator();
    }

    public function testHandlerWithNoTypeHintThrowsException(): void
    {
        $container = new ArrayContainer([]);
        $factory = new AutodiscoveryHandlersLocatorFactory($container, $this->cache, [NoTypeHintHandler::class]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('message handler param type is not defined');

        $factory->createHandlersLocator();
    }

    public function testHandlerWithBuiltinTypeThrowsException(): void
    {
        $container = new ArrayContainer([]);
        $factory = new AutodiscoveryHandlersLocatorFactory($container, $this->cache, [BuiltinTypeHandler::class]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('message handler param should be a custom class, but it is: string');

        $factory->createHandlersLocator();
    }

    public function testCustomMethodNameViaArgument(): void
    {
        $container = new ArrayContainer([
            CustomMethodHandler::class => new CustomMethodHandler(),
        ]);

        $factory = new AutodiscoveryHandlersLocatorFactory($container, $this->cache, [CustomMethodHandler::class]);
        $locator = $factory->createHandlersLocator();

        $this->assertHandlerDiscovered($locator, CustomMethodHandler::class, 'process');
    }

    private function assertHandlerDiscovered(HandlersLocatorInterface $locator, string $handlerClass, string $methodName): void
    {
        $handlers = iterator_to_array($locator->getHandlers(new Envelope(new TestMessage())));

        $this->assertNotEmpty($handlers);
        $descriptor = array_values($handlers)[0];
        $this->assertInstanceOf(HandlerDescriptor::class, $descriptor);
        $this->assertSame($handlerClass . '::' . $methodName, $descriptor->getName());
    }
}

class TestMessage
{
}

#[AsMessageHandler]
class ClassLevelHandler
{
    public function __invoke(TestMessage $message): void
    {
    }
}

class MethodLevelHandler
{
    #[AsMessageHandler]
    public function handle(TestMessage $message): void
    {
    }
}

class InvalidParamCountHandler
{
    #[AsMessageHandler]
    public function handle(TestMessage $message, string $extra): void
    {
    }
}

class NoTypeHintHandler
{
    #[AsMessageHandler]
    public function handle($message): void
    {
    }
}

class BuiltinTypeHandler
{
    #[AsMessageHandler]
    public function handle(string $message): void
    {
    }
}

#[AsMessageHandler(method: 'process')]
class CustomMethodHandler
{
    public function process(TestMessage $message): void
    {
    }
}
