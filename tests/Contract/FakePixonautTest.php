<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract;

use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Exception\IdempotencyBugException;
use Pixonaut\Sdk\Tests\Contract\Support\Arguments;
use Pixonaut\Sdk\Tests\Contract\Support\ContractClient;
use Pixonaut\Sdk\Tests\Fake\Fault;
use Pixonaut\Sdk\Tests\Fake\FakePixonaut;
use Pixonaut\Sdk\Tests\Fake\Spec;

/**
 * The fake refuses what the spec refuses, and goes wrong the ways a test
 * scripts it to.
 */
final class FakePixonautTest extends TestCase
{
    public function testAQueryNameTheOperationDoesNotHaveIsAViolation(): void
    {
        $client = new ContractClient();

        $this->callRefused($client, 'events.list', query: ['afterr' => 'evt_1']);

        self::assertSame(['events.list query afterr: the operation has no such query parameter'], $client->fake()->violations());
    }

    public function testAValueOfAnotherTypeIsAViolationWithTheSchemaError(): void
    {
        $client = new ContractClient();

        $this->callRefused($client, 'events.list', query: ['limit' => 'many']);

        self::assertSame(['events.list query limit = many: /: The data (string) must match the type: integer'], $client->fake()->violations());
    }

    public function testABodyThatBreaksTheSchemaIsAViolationNamingThePath(): void
    {
        $client = new ContractClient();

        $this->callRefused($client, 'designs.commit', body: ['proofToken' => 5], idempotencyKey: 'k1');

        $violations = implode("\n", $client->fake()->violations());
        self::assertStringStartsWith('designs.commit body ', $violations);
        self::assertStringContainsString('/proofToken', $violations);
    }

    public function testABodyWithoutItsContentTypeIsAViolation(): void
    {
        $fake = new FakePixonaut();

        $answer = $fake->sendRequest(new Request('POST', '/v1/designs', ['Authorization' => 'Bearer sk_1', 'Idempotency-Key' => 'k1'], '{"proofToken":"proof_1"}'));

        self::assertSame(400, $answer->getStatusCode());
        self::assertSame(['designs.commit header Content-Type: "", not application/json'], $fake->violations());
    }

    public function testAKeyOnAnOperationThatTakesNoneIsAViolation(): void
    {
        $fake = new FakePixonaut();

        $fake->sendRequest(new Request('GET', '/v1/events', ['Authorization' => 'Bearer sk_1', 'Idempotency-Key' => 'k1']));

        self::assertSame(['events.list header Idempotency-Key: the operation takes none'], $fake->violations());
    }

    public function testARequestWithoutTheCredentialTheOperationNeedsIsRefused(): void
    {
        $fake = new FakePixonaut();

        $none = $fake->sendRequest(new Request('GET', '/v1/events'));
        $wrong = $fake->sendRequest(new Request('POST', '/v1/orders', ['Authorization' => 'Bearer pk_1', 'Content-Type' => 'application/json', 'Idempotency-Key' => 'k1'], '{}'));

        self::assertSame([401, 403], [$none->getStatusCode(), $wrong->getStatusCode()]);
        self::assertSame([
            'events.list header Authorization: the operation needs a credential, and none was sent',
            'orders.create header Authorization: a publishableKey, which the operation does not take',
        ], $fake->violations());
    }

    public function testAPathNoOperationHasIsAViolation(): void
    {
        $fake = new FakePixonaut();

        self::assertSame(404, $fake->sendRequest(new Request('GET', '/v1/galaxies'))->getStatusCode());
        self::assertSame(405, $fake->sendRequest(new Request('PUT', '/v1/events'))->getStatusCode());
        self::assertSame(['GET /v1/galaxies: no operation has this path', 'PUT /v1/events: the path takes no PUT'], $fake->violations());
    }

    public function testRoutesToTheMostLiteralPath(): void
    {
        $route = Spec::pinned()->route('GET', '/v1/automations/placeholders');
        $templated = Spec::pinned()->route('GET', '/v1/automations/a%2F1');

        self::assertSame('automations.placeholders', $route[0]->id ?? null);
        self::assertSame(['automations.get', ['id' => 'a/1']], [$templated[0]->id ?? null, $templated[1] ?? null]);
    }

    public function testARepeatedKeyWithTheSameBodyIsReplayed(): void
    {
        $client = new ContractClient();

        $first = $client->pixonaut->call('designs.commit', body: ['proofToken' => 'proof_1'], idempotencyKey: 'k1');
        $again = $client->pixonaut->call('designs.commit', body: ['proofToken' => 'proof_1'], idempotencyKey: 'k1');

        self::assertFalse($first->replayed());
        self::assertTrue($again->replayed());
        self::assertSame($first->body, $again->body);
        self::assertSame(1, $client->fake()->made('designs.commit'));
        self::assertSame([], $client->fake()->violations());
    }

    public function testARepeatedKeyWithAnotherBodyIsRefusedAsReused(): void
    {
        $client = new ContractClient();
        $client->pixonaut->call('designs.commit', body: ['proofToken' => 'proof_1'], idempotencyKey: 'k1');

        try {
            $client->pixonaut->call('designs.commit', body: ['proofToken' => 'proof_2'], idempotencyKey: 'k1');
            self::fail('The reused key was taken.');
        } catch (IdempotencyBugException $e) {
            self::assertSame([422, ApiException::IDEMPOTENCY_KEY_REUSED], [$e->status, $e->errorCode]);
        }
        self::assertSame([], $client->fake()->violations());
    }

    public function testAKeyStillInFlightIsAnsweredInUseWithRetryAfter(): void
    {
        $client = new ContractClient();
        $client->fake()->keyInFlight('k1', 2, 2);

        $result = $client->pixonaut->call('designs.commit', body: ['proofToken' => 'proof_1'], idempotencyKey: 'k1');

        self::assertSame(201, $result->status);
        self::assertSame([2, 2], $client->slept);
        self::assertSame([409, 409, 201], array_column($client->fake()->log(), 'status'));
        self::assertSame([], $client->fake()->violations());
    }

    public function testAServerErrorIsRetryableWithItsRetryAfter(): void
    {
        $client = new ContractClient();
        $client->fake()->fault(Fault::serverError(503, 'lock_timeout', 7), 'orders.create');

        $error = $this->callRefused($client, 'orders.create', body: (array) Spec::pinned()->operation('orders.create')->requestExample(), idempotencyKey: 'k1');

        self::assertSame([503, 'lock_timeout', true, 7], [$error->status, $error->errorCode, $error->retryable, $error->retryAfter]);
        self::assertSame([], $client->fake()->violations());
    }

    public function testARateLimitCarriesItsRetryAfter(): void
    {
        $client = new ContractClient();
        $client->fake()->fault(Fault::rateLimited(30));

        $error = $this->callRefused($client, 'templates.mockup.get', ...Arguments::forCall(Spec::pinned()->operation('templates.mockup.get')));

        self::assertSame([429, 'rate_limited', true, 30], [$error->status, $error->errorCode, $error->retryable, $error->retryAfter]);
        self::assertSame([], $client->fake()->violations());
    }

    public function testADeprecatedAnswerCarriesTheDeprecationAndSunset(): void
    {
        $client = new ContractClient();
        $client->fake()->fault(Fault::deprecated('@1790000000', 'Wed, 01 Sep 2027 00:00:00 GMT'), 'events.list');

        $result = $client->pixonaut->call('events.list');

        $deprecation = $result->deprecation;
        self::assertNotNull($deprecation);
        self::assertSame('@1790000000', $deprecation->header);
        self::assertSame(1_819_756_800, $deprecation->sunset?->getTimestamp());
    }

    public function testATransportFailureReachesTheCallerAsRetryable(): void
    {
        $client = new ContractClient();
        $client->fake()->fault(Fault::transportFailure());

        $error = $this->callRefused($client, 'events.list');

        self::assertSame([0, ApiException::TRANSPORT_FAILED, true], [$error->status, $error->errorCode, $error->retryable]);
    }

    public function testASuccessThatIsNotJsonIsAnUnexpectedResponse(): void
    {
        $client = new ContractClient();
        $client->fake()->fault(Fault::notJson(), 'events.list');

        $error = $this->callRefused($client, 'events.list');

        self::assertSame([200, ApiException::UNEXPECTED_RESPONSE], [$error->status, $error->errorCode]);
    }

    public function testAnAnswerATestSetIsHeldToTheSpecToo(): void
    {
        $client = new ContractClient();
        $client->fake()->answer('events.list', 200, ['events' => 'none', 'nextCursor' => null]);
        $client->fake()->answer('events.list', 418, ['error' => 'teapot']);

        $client->pixonaut->call('events.list');
        $this->callRefused($client, 'events.list');

        $violations = $client->fake()->violations();
        self::assertCount(2, $violations);
        self::assertStringStartsWith('events.list answer 200 /events: ', $violations[0]);
        self::assertSame('events.list answer 418: the spec documents no such status', $violations[1]);
    }

    /**
     * @param array<string, string|int>               $path
     * @param array<string, scalar|list<scalar>|null> $query
     * @param array<array-key, mixed>|null            $body
     * @param array<string, string|null>              $headers
     */
    private function callRefused(ContractClient $client, string $operation, array $path = [], array $query = [], ?array $body = null, ?string $idempotencyKey = null, array $headers = []): ApiException
    {
        try {
            $client->pixonaut->call($operation, $path, $query, $body, $idempotencyKey, $headers);
        } catch (ApiException $e) {
            return $e;
        }
        self::fail($operation . ' was answered.');
    }
}
