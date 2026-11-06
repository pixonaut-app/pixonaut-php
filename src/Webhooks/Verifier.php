<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Webhooks;

use Closure;
use Pixonaut\Sdk\Exception\InvalidSignature;
use Psr\Http\Message\ServerRequestInterface;
use UnexpectedValueException;

/**
 * Checks a webhook delivery's signature and reads its event.
 *
 * ```php
 * $event = (new Verifier($secret))->verify($request);
 * $typed = $event->typed();
 * ```
 */
final class Verifier
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string                $secret The endpoint's signing secret.
     * @param (Closure(): int)|null $clock  Unix time; `time()` when null.
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $secret,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * The delivery's event, once its signature holds.
     *
     * @throws InvalidSignature         When the signature does not verify, or is out of the tolerance.
     * @throws UnexpectedValueException When the body, signed, is not an event envelope.
     */
    public function verify(ServerRequestInterface $request): Event
    {
        $body = (string) $request->getBody();
        $verified = Signature::verify(
            $this->secret,
            $request->getHeaderLine(Signature::TIMESTAMP_HEADER),
            $request->getHeaderLine(Signature::SIGNATURE_HEADER),
            $body,
            $this->now(),
        );
        if (!$verified) {
            throw new InvalidSignature('The webhook delivery\'s signature does not verify.');
        }

        return Event::fromJson($body);
    }

    private function now(): int
    {
        $now = $this->clock->__invoke();

        return is_int($now) ? $now : throw new UnexpectedValueException('The clock must return Unix time as an int.');
    }
}
