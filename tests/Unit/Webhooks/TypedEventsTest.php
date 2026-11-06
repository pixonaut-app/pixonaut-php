<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Webhooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\EventType;
use Pixonaut\Sdk\Generated\Model\WebhookEvent;
use Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\Sdk\Webhooks\UnknownEvent;

/**
 * The typed events against the pinned spec's webhooks.
 */
final class TypedEventsTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function examples(): array
    {
        return Vectors::webhookExamples();
    }

    /**
     * @param array<string, mixed> $example
     */
    #[DataProvider('examples')]
    public function testEveryWebhookExampleParsesIntoItsTypedEvent(string $type, array $example): void
    {
        $event = Event::fromJson(json_encode($example, JSON_THROW_ON_ERROR));
        $typed = $event->typed();

        self::assertSame($type, $event->type);
        self::assertNotInstanceOf(UnknownEvent::class, $typed);
        self::assertSame($example, $typed->raw());
    }

    public function testEveryEventTypeInTheSpecHasAConstantAndAClass(): void
    {
        $types = array_keys(Vectors::webhooks());
        sort($types);

        self::assertSame($types, EventType::KNOWN);
        $examples = [];
        foreach (Vectors::webhookExamples() as [$type, $example]) {
            $examples[$type] ??= $example;
        }
        foreach ($types as $type) {
            self::assertArrayHasKey($type, $examples, $type . ' has no example to decode.');
            self::assertNotInstanceOf(UnknownEvent::class, WebhookEvent::decode($examples[$type]), $type . ' has no class.');
        }
    }
}
