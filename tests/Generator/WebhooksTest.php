<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generator\Codegen\Config;
use Pixonaut\Sdk\Generator\Codegen\Generator;
use Pixonaut\Sdk\Tests\Generator\Support\Calls;
use Pixonaut\Sdk\Tests\Generator\Support\GeneratesCode;
use Pixonaut\Sdk\Webhooks\UnknownEvent;
use ReflectionClassConstant;

final class WebhooksTest extends TestCase
{
    use GeneratesCode;

    public function testEachWebhookGetsAnEventClassAndATypeConstant(): void
    {
        $namespace = $this->load(self::eventsSpec());

        self::assertSame(['order.created', 'webhook.ping'], self::constant($namespace . '\\EventType', 'KNOWN'));
        self::assertSame('webhook.ping', self::constant($namespace . '\\EventType', 'WEBHOOK_PING'));
        self::assertInstanceOf(
            Calls::class($namespace . '\\Model\\OrderCreatedEvent'),
            Calls::decode($namespace . '\\Model\\WebhookEvent', self::envelope('order.created', ['orderId' => 'o_1'])),
        );
        // An inline body is named after its type.
        self::assertInstanceOf(
            Calls::class($namespace . '\\Model\\WebhookPingEvent'),
            Calls::decode($namespace . '\\Model\\WebhookEvent', self::envelope('webhook.ping', ['endpointId' => 'wh_1'])),
        );
    }

    public function testADeliveryOfATypeTheReleaseDoesNotKnowIsAnUnknownEvent(): void
    {
        $namespace = $this->load(self::eventsSpec());

        $event = Calls::decode($namespace . '\\Model\\WebhookEvent', self::envelope('order.lost', ['orderId' => 'o_1']));

        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertSame(['orderId' => 'o_1'], $event->payload);
    }

    public function testAListOfEventsFallsBackToUnknownEventToo(): void
    {
        $namespace = $this->load(self::eventsSpec());

        $list = Calls::fromArray($namespace . '\\Model\\EventsListResponse', ['events' => [
            self::envelope('order.created', ['orderId' => 'o_1']),
            self::envelope('order.lost', ['orderId' => 'o_2']),
        ]]);
        $events = Calls::get($list, 'events');

        self::assertIsArray($events);
        self::assertInstanceOf(Calls::class($namespace . '\\Model\\OrderCreatedEvent'), $events[0]);
        self::assertInstanceOf(UnknownEvent::class, $events[1]);
    }

    public function testASpecWithoutWebhooksHasNoEventTypes(): void
    {
        $files = (new Generator('Fixture', new Config()))->files(self::json(self::spec()));

        self::assertArrayNotHasKey('EventType.php', $files);
        self::assertArrayNotHasKey('Model/WebhookEvent.php', $files);
    }

    /**
     * @return array<string, mixed>
     */
    private static function eventsSpec(): array
    {
        $body = static fn(array $schema): array => ['post' => [
            'requestBody' => ['content' => ['application/json' => ['schema' => $schema]]],
            'responses' => ['200' => ['description' => 'Acknowledged']],
        ]];
        $event = static fn(string $type, string $field): array => [
            'type' => 'object',
            'required' => ['id', 'type', 'createdAt', 'payload'],
            'properties' => [
                'id' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => [$type]],
                'createdAt' => ['type' => 'integer'],
                'payload' => ['type' => 'object', 'required' => [$field], 'properties' => [$field => ['type' => 'string']]],
            ],
        ];

        return self::spec(
            ['OrderCreatedEvent' => $event('order.created', 'orderId')],
            ['/v1/events' => ['get' => [
                'operationId' => 'events.list',
                'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['events'],
                    'properties' => ['events' => ['type' => 'array', 'items' => [
                        'oneOf' => [['$ref' => '#/components/schemas/OrderCreatedEvent']],
                        'discriminator' => ['propertyName' => 'type', 'mapping' => [
                            'order.created' => '#/components/schemas/OrderCreatedEvent',
                        ]],
                    ]]],
                ]]]]],
            ]]],
            ['webhooks' => [
                'webhook.ping' => $body($event('webhook.ping', 'endpointId')),
                'order.created' => $body(['$ref' => '#/components/schemas/OrderCreatedEvent']),
            ]],
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function envelope(string $type, array $payload): array
    {
        return ['id' => 'evt_1', 'type' => $type, 'createdAt' => 1790000000000, 'payload' => $payload];
    }

    private static function constant(string $class, string $name): mixed
    {
        return (new ReflectionClassConstant(Calls::class($class), $name))->getValue();
    }
}
