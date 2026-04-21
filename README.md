# symfony-messenger-bridge

Wrapper around Symfony Messenger providing Kafka Rest transport implementation and handler autodiscovery.

[Русская версия](./README.ru.md)

## Installation
```bash
composer require adsniper/symfony-messenger-bridge
```

## Usage

### Setup
```php
use Adsniper\SymfonyMessengerBridge\MessengerBridge;
use Adsniper\SymfonyMessengerBridge\KafkaTransportBuilder;
use Adsniper\SymfonyMessengerBridge\KafkaTransportConfig;
use Adsniper\SymfonyMessengerBridge\KafkaAutoOffsetReset;

// 1. Configure and build the async transport
$asyncTransport = (new KafkaTransportBuilder())
    ->setConfig(new KafkaTransportConfig(
        host: 'http://kafka-rest-proxy:8082',
        topic: 'my-topic',
        group: 'my-consumer-group',
        consumerInstancePrefix: 'instance',
        autoOffsetReset: KafkaAutoOffsetReset::EARLIEST,
        autoCommit: false,
        messageKeyPrefix: 'msg_'
    ))
    ->build();

// 2. Set your DI container (required)
MessengerBridge::setContainer($container);

// 3. Set the async transport (optional - if omitted, all handlers work in sync mode)
MessengerBridge::setAsyncTransport($asyncTransport);

// 4. Create the message bus for a specific namespace
// Consider passing a proper CacheInterface as the second argument for production use.
$bus = MessengerBridge::createBusByNamespace("App\Example");
```

### Async Message Handlers

Create message handlers in the namespace you specified (e.g., App\Example). The bridge will automatically discover and register them.

```php
namespace App\Example;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class MyMessageHandler
{
    public function __invoke(MyMessage $message): void
    {
        var_dump($message);
    }
}
```

To make a handler run asynchronously (in the background), simply pass the "async" argument to the AsMessageHandler attribute:

```php
namespace App\Example;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler("async")] // Or #[AsMessageHandler(bus: "async")]
class MyMessageHandler
{
    public function __invoke(MyMessage $message): void
    {
        // This will be handled by the async transport (Kafka)
        var_dump($message);
    }
}
```

### Consuming Messages

Finally, register the consume command with your Symfony Console Application to start processing messages.

```php
use Symfony\Component\Console\Application;

$application = new Application();
// ...
$application->add(
    MessengerBridge::createConsumeMessagesCommandForSingleBus($bus)
);
// ...
$application->run();
```

You can then run the worker from your terminal:

```bash
php bin/console messenger:consume async
```

## KafkaTransportConfig Reference
| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `host` | string | required | Kafka REST Proxy base URL |
| `topic` | string | required | Target Kafka topic |
| `group` | string | required | Consumer group ID |
| `consumerInstancePrefix` | string | required | Prefix for consumer instance IDs |
| `autoOffsetReset` | `KafkaAutoOffsetReset` | `EARLIEST` | Where to start reading |
| `autoCommit` | bool | `false` | Enable auto commit |
| `messageKeyPrefix` | string | `""` | Prefix for message keys |
