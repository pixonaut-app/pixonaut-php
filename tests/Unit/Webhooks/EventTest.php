<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Webhooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\Sdk\Generated\EventType;
use Pixonaut\Sdk\Generated\Model\AutomationWebhookEvent;
use Pixonaut\Sdk\Generated\Model\EventsListResponse;
use Pixonaut\Sdk\Generated\Model\OrderStatusChangedEvent;
use Pixonaut\Sdk\Generated\Model\WebhookEvent;
use Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\Sdk\Webhooks\UnknownEvent;
use UnexpectedValueException;

final class EventTest extends TestCase
{
    private const STATUS_CHANGED = [
        'id' => '1790086400000-0f1e2d3c-4b5a-4968-8776-655443322110',
        'type' => 'order.status_changed',
        'createdAt' => 1790086400000,
        'payload' => [
            'orderId' => 'e1f2a3b4-c5d6-4e7f-8a9b-0c1d2e3f4a5b',
            'printId' => 'c4d5e6f7-a8b9-4c0d-9e1f-2a3b4c5d6e7f',
            'status' => 'shipped',
            'previousStatus' => 'printed',
        ],
    ];

    /** A type no release knows yet. */
    private const UNKNOWN = [
        'id' => 'evt_2',
        'type' => 'pigment.mixed',
        'createdAt' => 1790086400000,
        'payload' => ['pigmentId' => 'pg_1', 'shade' => ['r' => 1]],
        'later' => true,
    ];

    public function testReadsTheEnvelopeOfADelivery(): void
    {
        $event = Event::fromJson(json_encode(self::STATUS_CHANGED, JSON_THROW_ON_ERROR));

        self::assertSame(self::STATUS_CHANGED['id'], $event->id);
        self::assertSame(EventType::ORDER_STATUS_CHANGED, $event->type);
        self::assertSame(self::STATUS_CHANGED['createdAt'], $event->createdAt);
        self::assertSame(self::STATUS_CHANGED['payload'], $event->payload);
        self::assertSame(self::STATUS_CHANGED['payload'], $event->rawPayload());
        self::assertSame(self::STATUS_CHANGED, $event->raw());
    }

    public function testAKnownTypeIsItsTypedEvent(): void
    {
        $typed = Event::fromArray(self::STATUS_CHANGED)->typed();

        self::assertInstanceOf(OrderStatusChangedEvent::class, $typed);
        self::assertSame('shipped', $typed->payload->status);
        self::assertSame('printed', $typed->payload->previousStatus);
    }

    public function testATypeThisReleaseDoesNotKnowIsAnUnknownEventWithItsRawPayload(): void
    {
        $typed = Event::fromArray(self::UNKNOWN)->typed();

        self::assertInstanceOf(UnknownEvent::class, $typed);
        self::assertSame('evt_2', $typed->id);
        self::assertSame('pigment.mixed', $typed->type);
        self::assertSame(1790086400000, $typed->createdAt);
        self::assertSame(self::UNKNOWN['payload'], $typed->payload);
        self::assertSame(self::UNKNOWN, $typed->raw());
    }

    public function testThePolledEventStreamReadsIntoTheSameClasses(): void
    {
        $page = EventsListResponse::fromArray(['events' => [self::STATUS_CHANGED, self::UNKNOWN], 'nextCursor' => null]);

        self::assertInstanceOf(OrderStatusChangedEvent::class, $page->events[0]);
        self::assertInstanceOf(UnknownEvent::class, $page->events[1]);
    }

    public function testAnUnknownEventInTheStreamStillNeedsAnEnvelope(): void
    {
        $broken = self::UNKNOWN;
        unset($broken['createdAt']);

        $this->expectExceptionObject(new UnexpectedResponse('$.events[0]', 'missing required field "createdAt"'));
        EventsListResponse::fromArray(['events' => [$broken], 'nextCursor' => null]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function notAnEnvelope(): array
    {
        $without = static function (string $field): array {
            $data = self::STATUS_CHANGED;
            unset($data[$field]);

            return $data;
        };

        return [
            'no id' => [$without('id'), '$'],
            'an empty id' => [['id' => ''] + self::STATUS_CHANGED, '$.id'],
            'an id that is not a string' => [['id' => 42] + self::STATUS_CHANGED, '$.id'],
            'no type' => [$without('type'), '$'],
            'an empty type' => [['type' => ''] + self::STATUS_CHANGED, '$.type'],
            'no createdAt' => [$without('createdAt'), '$'],
            'a createdAt with a fraction' => [['createdAt' => 1790086400000.5] + self::STATUS_CHANGED, '$.createdAt'],
            'a createdAt as a string' => [['createdAt' => '1790086400000'] + self::STATUS_CHANGED, '$.createdAt'],
            'no payload' => [$without('payload'), '$'],
            'a payload that is a list' => [['payload' => [1, 2]] + self::STATUS_CHANGED, '$.payload'],
            'a payload that is a string' => [['payload' => 'shipped'] + self::STATUS_CHANGED, '$.payload'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('notAnEnvelope')]
    public function testRefusesWhatIsNotAnEnvelope(array $data, string $path): void
    {
        try {
            Event::fromArray($data);
            self::fail('It read an envelope it should refuse.');
        } catch (UnexpectedResponse $e) {
            self::assertInstanceOf(UnexpectedValueException::class, $e);
            self::assertSame($path, $e->jsonPath);
        }
    }

    public function testAPayloadObjectWithNumberKeysIsStillAnObject(): void
    {
        $unknown = Event::fromJson('{"id":"evt_3","type":"pigment.mixed","createdAt":1,"payload":{"0":"value"}}');
        $automation = Event::fromJson('{"id":"awh_1","type":"automation.webhook","createdAt":1,"via":"automation","automation":{"id":"aut_1","name":"A"},"payload":{"0":"value"}}');

        $typed = $unknown->typed();
        self::assertInstanceOf(UnknownEvent::class, $typed);
        self::assertSame([0 => 'value'], $typed->payload);
        self::assertSame([0 => 'value'], $automation->payload);
    }

    public function testAnEmptyJsonObjectIsAPayloadAndAnEmptyListIsNot(): void
    {
        self::assertSame([], Event::fromJson('{"id":"evt_4","type":"pigment.mixed","createdAt":1,"payload":{}}')->payload);
        self::assertSame([], Event::fromJson('{"id":"awh_1","type":"automation.webhook","createdAt":1,"payload":[]}')->payload);

        $this->expectExceptionObject(new UnexpectedResponse('$.payload', 'expected object'));
        Event::fromJson('{"id":"evt_4","type":"pigment.mixed","createdAt":1,"payload":[]}');
    }

    public function testAKnownEventIsHeldToTheSameEnvelopeRules(): void
    {
        $this->expectExceptionObject(new UnexpectedResponse('$.events[0].id', 'expected a non-empty string'));
        EventsListResponse::fromArray(['events' => [['id' => ''] + self::STATUS_CHANGED], 'nextCursor' => null]);
    }

    public function testADeliveryOfAKnownTypeIsHeldToTheSameEnvelopeRules(): void
    {
        $this->expectExceptionObject(new UnexpectedResponse('$.id', 'expected a non-empty string'));
        WebhookEvent::decode(['id' => ''] + self::STATUS_CHANGED);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notAnObject(): array
    {
        return [
            'not JSON' => ['{"id":'],
            'a JSON list' => ['[1,2]'],
            'a JSON string' => ['"evt_1"'],
        ];
    }

    #[DataProvider('notAnObject')]
    public function testRefusesABodyThatIsNotAJsonObject(string $body): void
    {
        $this->expectException(UnexpectedValueException::class);
        Event::fromJson($body);
    }

    /**
     * @return array<string, array{mixed, array<string, mixed>}>
     */
    public static function automationPayloads(): array
    {
        return [
            'an object' => [['order' => 'ord_1', 'status' => 'failed'], ['order' => 'ord_1', 'status' => 'failed']],
            'a list' => [['ord_1', 'ord_2'], []],
            'a string' => ['failed', []],
            'a number' => [42, []],
            'null' => [null, []],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('automationPayloads')]
    public function testAnAutomationWebhookCarriesWhateverJsonItsStepWrote(mixed $sent, array $payload): void
    {
        $event = Event::fromArray(self::automationWebhook() + ['payload' => $sent]);

        self::assertSame($payload, $event->payload);
        self::assertSame($sent, $event->rawPayload());
        $typed = $event->typed();
        self::assertInstanceOf(AutomationWebhookEvent::class, $typed);
        self::assertSame($sent, $typed->payload);
    }

    public function testAnAutomationWebhookMayCarryNoPayload(): void
    {
        $event = Event::fromArray(self::automationWebhook());

        self::assertSame([], $event->payload);
        self::assertNull($event->rawPayload());
        self::assertInstanceOf(AutomationWebhookEvent::class, $event->typed());
    }

    /**
     * @return array<string, mixed>
     */
    private static function automationWebhook(): array
    {
        return [
            'id' => 'awh_4f1c9a7e2b6d8035c1e9a4f7',
            'type' => EventType::AUTOMATION_WEBHOOK,
            'createdAt' => 1790000000000,
            'via' => 'automation',
            'automation' => ['id' => 'aut_1', 'name' => 'Tell the warehouse'],
        ];
    }
}
