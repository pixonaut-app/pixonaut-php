<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Live;

use Closure;
use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Config;
use Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Generated\Operations;
use Pixonaut\Sdk\Http\Listener;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Http\Result;
use Pixonaut\Sdk\Pixonaut;
use Pixonaut\Sdk\Tests\Fake\Spec;

/**
 * Read-only calls against a Pixonaut on this machine: each answer is
 * decoded by the SDK and held to the pinned spec's schema. No value is
 * compared, so any seeded Pixonaut will do.
 *
 * Run with `composer test:live`; skipped unless `LiveTarget`'s variables
 * are set, and refused for any host that is not loopback.
 */
final class LiveSmokeTest extends TestCase
{
    /**
     * @return array<string, array{string, Closure(Pixonaut): mixed}>
     */
    public static function reads(): array
    {
        return [
            'templates' => ['templates.list', static fn(Pixonaut $p): mixed => $p->templates()->list()],
            'blanks' => ['blanks.list', static fn(Pixonaut $p): mixed => $p->blanks()->list()],
            'events' => ['events.list', static fn(Pixonaut $p): mixed => $p->events()->list()],
            'webhook endpoints' => ['webhookEndpoints.list', static fn(Pixonaut $p): mixed => $p->webhooks()->list()],
            'printshop connections' => ['printshopConnections.list', static fn(Pixonaut $p): mixed => $p->printshops()->listConnections()],
        ];
    }

    /**
     * @param Closure(Pixonaut): mixed $read
     */
    #[DataProvider('reads')]
    public function testTheAnswerIsDecodedAndFitsThePinnedSpec(string $operation, Closure $read): void
    {
        // Before any client exists: a URL that is not loopback fails here.
        $target = LiveTarget::fromEnvironment(getenv());
        if ($target === null) {
            self::markTestSkipped(LiveTarget::SKIPPED);
        }
        $listener = new class extends Listener {
            public ?Result $result = null;

            public function after(PreparedRequest $request, ?Result $result, ?ApiException $error): void
            {
                $this->result = $result;
            }
        };
        $factory = new Psr17Factory();
        $pixonaut = new Pixonaut(
            new Config(baseUrl: $target->baseUrl, product: 'pixonaut-php-live-smoke/1.0'),
            new LoopbackOnlyClient(new Client()),
            $factory,
            $factory,
            new StaticCredentials(integrationKey: $target->integrationKey, secretKey: $target->secretKey),
            Operations::catalogue(),
            listener: $listener,
        );

        self::assertNotNull($read($pixonaut));

        $result = $listener->result;
        self::assertInstanceOf(Result::class, $result);
        $problems = Spec::pinned()->operation($operation)->validateAnswer($result->status, json_decode($result->body, false, 512, JSON_THROW_ON_ERROR));
        self::assertSame([], $problems, $operation . ' answered what the pinned spec does not describe.');
    }
}
