<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Generated\Model\ConnectedStoreExchangeCodeRequest;
use Pixonaut\Sdk\Generated\Model\DesignsCommitRequestOption1;
use Pixonaut\Sdk\Generated\Model\OrdersCreateRequest;
use Pixonaut\Sdk\Generated\Model\OrdersCreateRequestPrintsItem;
use Pixonaut\Sdk\Generated\Model\OrdersCreateRequestRecipient;
use Pixonaut\Sdk\Generated\Model\OrdersCreateRequestShippingAddress;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Tests\Contract\Support\ContractClient;
use Pixonaut\Sdk\Tests\Fake\Fault;

/**
 * A connected store's calls, in the order a store makes them, against the
 * fake: each request is one the spec accepts, and each answer is read.
 */
final class StoreScenarioTest extends TestCase
{
    public function testAStoreConnectsSellsADesignOrdersItAndDisconnects(): void
    {
        $client = new ContractClient();
        $fake = $client->fake();
        $pixonaut = $client->pixonaut;

        $pixonaut->connect()->exchangeCode(new ConnectedStoreExchangeCodeRequest(code: 'code_1'));

        $templates = $pixonaut->templates()->list(ids: ['tshirt-basic@2', 'mug@1']);
        $blanks = $pixonaut->blanks()->list();
        self::assertNotSame([], $templates->raw());
        self::assertNotSame([], $blanks->raw());

        $design = $pixonaut->designs()->commit(
            new DesignsCommitRequestOption1(proofToken: 'proof_1', externalRef: 'cart-10482-1'),
            idempotencyKey: 'commit-proof_1',
        );
        self::assertNotSame('', $design->designId);

        // The order is prepared and stored before it is sent. Its answer is
        // lost; the store sends the stored request again, and Pixonaut
        // answers it with the order the first one made.
        $prepared = $pixonaut->orders()->prepareCreate(new OrdersCreateRequest(
            externalRef: 'WEB-10482',
            prints: [new OrdersCreateRequestPrintsItem(printId: 'print_1')],
            recipient: new OrdersCreateRequestRecipient(name: 'Alexandra Lind', email: 'alexandra.lind@example.com'),
            shippingAddress: new OrdersCreateRequestShippingAddress(city: 'London', country: 'GB', line1: '12 High Street', postalCode: 'SW1A 1AA'),
        ));
        $stored = json_encode($prepared->toArray(), JSON_THROW_ON_ERROR);
        $fake->fault(Fault::lostAnswer(), 'orders.create');
        try {
            $pixonaut->orders()->sendCreate($prepared);
            self::fail('The lost answer was read.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::TRANSPORT_FAILED, $e->errorCode);
            self::assertTrue($e->retryable);
        }
        $order = $pixonaut->orders()->sendCreate(PreparedRequest::fromArray((array) json_decode($stored, true)));
        self::assertNotSame('', $order->id);
        self::assertSame(1, $fake->made('orders.create'));
        self::assertTrue($fake->log()[count($fake->log()) - 1]['replayed']);

        $events = $pixonaut->events()->list(after: 'evt_1', limit: 50);
        self::assertNotSame([], $events->events);

        $pixonaut->webhooks()->ping('we_1');
        $pixonaut->connect()->delete();

        self::assertSame([], $fake->violations());
        self::assertSame(
            [
                'connectedStore.exchangeCode',
                'templates.list',
                'blanks.list',
                'designs.commit',
                'orders.create',
                'orders.create',
                'events.list',
                'webhookEndpoints.ping',
                'connectedStore.delete',
            ],
            array_column($fake->log(), 'operation'),
        );
    }
}
