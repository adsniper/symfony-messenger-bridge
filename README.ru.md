# symfony-messenger-bridge

Обёртка над Symfony Messenger, предоставляющая реализацию Kafka REST транспорта и автообнаружение обработчиков.

## Установка
```bash
composer require adsniper/symfony-messenger-bridge
```

## Использование

### Настройка
```php
use Adsniper\SymfonyMessengerBridge\MessengerBridge;
use Adsniper\SymfonyMessengerBridge\KafkaTransportBuilder;
use Adsniper\SymfonyMessengerBridge\KafkaTransportConfig;
use Adsniper\SymfonyMessengerBridge\KafkaAutoOffsetReset;

// 1. Настройте и создайте асинхронный транспорт
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

// 2. Установите ваш DI контейнер (обязательно)
MessengerBridge::setContainer($container);

// 3. Установите асинхронный транспорт (опционально - если не указан, все обработчики работают в синхронном режиме)
MessengerBridge::setAsyncTransport($asyncTransport);

// 4. Создайте шину сообщений для указанного пространства имён
// Для продакшена рекомендуется передать CacheInterface вторым аргументом.
$bus = MessengerBridge::createBusByNamespace("App\Example");
```

### Асинхронные обработчики сообщений

Создайте обработчики сообщений в указанном пространстве имён (например, App\Example). Мост автоматически обнаружит и зарегистрирует их.

```php
namespace App\Example;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

class MyMessageHandler
{
    #[AsMessageHandler]
    public function __invoke(MyMessage $message): void
    {
        var_dump($message);
    }
}
```

Чтобы сделать обработчик асинхронным (в фоне), просто передайте аргумент "async" в атрибут AsMessageHandler:

```php
namespace App\Example;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

class MyMessageHandler
{
    #[AsMessageHandler("async")] // Или #[AsMessageHandler(bus: "async")]
    public function __invoke(MyMessage $message): void
    {
        // Этот обработчик будет выполняться через асинхронный транспорт (Kafka)
        var_dump($message);
    }
}
```

### Потребление сообщений

Зарегистрируйте команду потребления в вашем Symfony Console Application для запуска обработки сообщений.

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

После этого вы можете запустить воркер из терминала:

```bash
php bin/console messenger:consume async
```

## Справочник по KafkaTransportConfig
| Свойство | Тип | По умолчанию | Описание |
|----------|------|---------|-------------|
| `host` | string | обязательно | Базовый URL Kafka REST Proxy |
| `topic` | string | обязательно | Целевая Kafka тема |
| `group` | string | обязательно | ID группы потребителей |
| `consumerInstancePrefix` | string | обязательно | Префикс для ID экземпляров потребителей |
| `autoOffsetReset` | `KafkaAutoOffsetReset` | `EARLIEST` | С какой позиции начинать чтение |
| `autoCommit` | bool | `false` | Включить автофиксацию |
| `messageKeyPrefix` | string | `""` | Префикс для ключей сообщений |