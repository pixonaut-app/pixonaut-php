<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Resource;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\Model\ConnectedStoreExchangeCodeRequest;
use Pixonaut\Sdk\Generated\Model\DesignsApproveRequest;
use Pixonaut\Sdk\Generated\Model\DesignsApproveRequestConsent;
use Pixonaut\Sdk\Generated\Model\DesignsCommitRequestOption1;
use Pixonaut\Sdk\Generated\Model\OrdersCreateRequest;
use Pixonaut\Sdk\Generated\Model\OrdersCreateRequestPrintsItem;
use Pixonaut\Sdk\Generated\Model\OrdersCreateRequestRecipient;
use Pixonaut\Sdk\Generated\Model\OrdersCreateRequestShippingAddress;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Tests\Unit\Resource\Support\SpecClient;
use Pixonaut\Sdk\Tests\Unit\Resource\Support\SpecExamples;

/**
 * A connected store's day, written against the SDK the way a host would.
 */
final class StoreFlowTest extends TestCase
{
    public function testAStoreConnectsSellsADesignAndOrdersIt(): void
    {
        $client = new SpecClient();
        $pixonaut = $client->pixonaut;

        $client->answerJson(201, SpecExamples::answer('connectedStore.exchangeCode', 201));
        $connection = $pixonaut->connect()->exchangeCode(new ConnectedStoreExchangeCodeRequest(code: 'code_1'));
        self::assertEquals(SpecExamples::answer('connectedStore.exchangeCode', 201), $connection->raw());
        self::assertSame('POST /v1/connect/token {"code":"code_1"}', self::line($client));

        $client->answerJson(200, SpecExamples::answer('templates.list', 200));
        $pixonaut->templates()->list(ids: ['tshirt-basic@2', 'mug@1']);
        self::assertSame('GET /v1/templates?ids=tshirt-basic%402&ids=mug%401', self::line($client));

        $client->answerJson(201, SpecExamples::answer('designs.commit', 201));
        $design = $pixonaut->designs()->commit(
            new DesignsCommitRequestOption1(proofToken: 'proof_1', externalRef: 'cart-10482-1'),
            idempotencyKey: 'commit-proof_1',
        );
        self::assertSame('POST /v1/designs {"proofToken":"proof_1","externalRef":"cart-10482-1"}', self::line($client));
        self::assertSame('commit-proof_1', $client->lastSent()->getHeaderLine('Idempotency-Key'));

        $client->answerJson(200, SpecExamples::answer('designs.approve', 200));
        $pixonaut->designs()->approve($design->designId, new DesignsApproveRequest(
            consent: new DesignsApproveRequestConsent(at: 1_790_000_050_000, kind: 'checkbox'),
            expectedRevision: 1,
        ));
        self::assertSame(
            'POST /v1/designs/' . $design->designId . '/approve {"consent":{"at":1790000050000,"kind":"checkbox"},"expectedRevision":1}',
            self::line($client),
        );

        // The order is prepared and stored before it is sent: a retry after a
        // lost answer sends the stored request, the same bytes under the same key.
        $prepared = $pixonaut->orders()->prepareCreate(new OrdersCreateRequest(
            externalRef: 'WEB-10482',
            prints: [new OrdersCreateRequestPrintsItem(printId: 'print_1')],
            recipient: new OrdersCreateRequestRecipient(name: 'Alexandra Lind', email: 'alexandra.lind@example.com'),
            shippingAddress: new OrdersCreateRequestShippingAddress(city: 'London', country: 'GB', line1: '12 High Street', postalCode: 'SW1A 1AA'),
        ));
        $stored = json_encode($prepared->toArray(), JSON_THROW_ON_ERROR);
        $client->answerJson(201, SpecExamples::answer('orders.create', 201));
        $order = $pixonaut->orders()->sendCreate(PreparedRequest::fromArray((array) json_decode($stored, true)));
        self::assertNotSame('', $order->id);
        self::assertSame($prepared->idempotencyKey, $client->lastSent()->getHeaderLine('Idempotency-Key'));
        self::assertSame($prepared->body, (string) $client->lastSent()->getBody());

        $client->answerJson(200, SpecExamples::answer('events.list', 200));
        $events = $pixonaut->events()->list(after: 'evt_1', limit: 50);
        self::assertSame('GET /v1/events?after=evt_1&limit=50', self::line($client));
        self::assertCount(count(SpecExamples::map(SpecExamples::map(SpecExamples::answer('events.list', 200))['events'] ?? [])), $events->events);
    }

    public function testAStoredRequestIsSentOnlyByItsOwnOperation(): void
    {
        $client = new SpecClient();
        $prepared = $client->pixonaut->designs()->prepareCommit(new DesignsCommitRequestOption1(proofToken: 'proof_1'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The request was prepared for designs.commit, not orders.create.');

        $client->pixonaut->orders()->sendCreate($prepared);
    }

    private static function line(SpecClient $client): string
    {
        $sent = $client->lastSent();
        $body = (string) $sent->getBody();

        return trim($sent->getMethod() . ' ' . $sent->getUri()->getPath() . ($sent->getUri()->getQuery() === '' ? '' : '?' . $sent->getUri()->getQuery()) . ' ' . $body);
    }
}
