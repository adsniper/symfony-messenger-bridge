# Agent Guidelines

## Project
Wrapper around Symfony Messenger providing:
- Kafka transport for async message handling
- Handler autodiscovery by namespace (via `ClassFinder`)
- Entry point: `MessengerBridge` class — creates buses and consume commands

## Commands
- `composer lint` — run phpstan (level 8) on `src/`

## Structure
- Single library: `src/` with namespace `Adsniper\SymfonyMessengerBridge`
- Main classes: `MessengerBridge` (entry), `KafkaTransport`, `AutodiscoveryHandlersLocatorFactory`
- No tests yet; `composer lint` is the only verification step
