<?php

namespace Adsniper\SymfonyMessengerBridge;

enum KafkaAutoOffsetReset
{
	case EARLIEST;
	case LATEST;
}