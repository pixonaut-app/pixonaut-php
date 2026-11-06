<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use LogicException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Http\EventStream;
use Pixonaut\Sdk\Http\StreamEvent;
use Pixonaut\Sdk\Tests\Unit\Support\ChunkedStream;
use Pixonaut\Sdk\Tests\Unit\Support\Harness;
use RuntimeException;

final class EventStreamTest extends TestCase
{
    public function testJoinsDataLinesAndSkipsComments(): void
    {
        $events = self::read(": keep-alive\ndata: first\ndata:second\ndata\n\n");

        self::assertEquals([new StreamEvent(StreamEvent::DEFAULT_TYPE, "first\nsecond\n")], $events);
    }

    public function testReadsTheEventTypeIdAndRetry(): void
    {
        $events = self::read("event: turn.delta\nid: 7\nretry: 1500\ndata: {\"text\":\"Hi\"}\n\ndata: next\n\nid\ndata: reset\n\n");

        self::assertEquals([
            new StreamEvent('turn.delta', '{"text":"Hi"}', '7', 1500),
            // The id and the reconnection time carry over; the type does not.
            new StreamEvent(StreamEvent::DEFAULT_TYPE, 'next', '7', 1500),
            new StreamEvent(StreamEvent::DEFAULT_TYPE, 'reset', '', 1500),
        ], $events);
    }

    public function testIgnoresUnknownFieldsAnInvalidRetryAndAnIdWithNul(): void
    {
        $events = self::read("id: 1\n\nfoo: bar\nretry: soon\nid: a\0b\ndata: x\n\n");

        self::assertEquals([new StreamEvent(StreamEvent::DEFAULT_TYPE, 'x', '1')], $events);
    }

    public function testDispatchesNothingForABlockWithoutData(): void
    {
        self::assertSame([], self::read("event: ping\n\n\n\n"));
    }

    public function testDropsAnEventTheStreamWasCutOffIn(): void
    {
        $events = self::read("data: whole\n\nevent: turn.delta\ndata: half");

        self::assertEquals([new StreamEvent(StreamEvent::DEFAULT_TYPE, 'whole')], $events);
        self::assertSame([], self::read("data: no blank line\n"));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function lineEnds(): iterable
    {
        yield 'CRLF' => [["data: a\r\n\r\ndata: b\r\n\r\n"]];
        yield 'CR' => [["data: a\r\rdata: b\r\r"]];
        yield 'CRLF split between reads' => [["data: a\r", "\n\r", "\ndata: b\r\n", "\r\n"]];
        yield 'a line split between reads' => [["da", "ta: a\n", "\nda", "ta: b\n\n"]];
        yield 'a byte order mark' => [["\xEF\xBB", "\xBFdata: a\n\ndata: b\n\n"]];
    }

    /**
     * @param list<string> $chunks
     */
    #[DataProvider('lineEnds')]
    public function testReadsEveryLineEndAcrossReads(array $chunks): void
    {
        $events = iterator_to_array(new EventStream(new ChunkedStream($chunks)), false);

        self::assertEquals([new StreamEvent(StreamEvent::DEFAULT_TYPE, 'a'), new StreamEvent(StreamEvent::DEFAULT_TYPE, 'b')], $events);
    }

    public function testReadsTheBodyOnlyAsFarAsTheCallerIterates(): void
    {
        $body = new ChunkedStream(["data: one\n\n", "data: two\n\n", "data: three\n\n"]);
        $stream = new EventStream($body);

        foreach ($stream as $event) {
            self::assertSame('one', $event->data);
            self::assertSame(1, $body->reads);
            break;
        }

        self::assertTrue($body->closed, 'Stopping early closes the body.');
    }

    public function testYieldsACarriageReturnEndedEventWithoutWaitingForTheNextRead(): void
    {
        $body = new ChunkedStream(["data: first\r\r", "\ndata: second\r\n\r\n"]);
        $events = (new EventStream($body))->getIterator();

        self::assertSame('first', $events->current()->data);
        self::assertSame(1, $body->reads);
        $events->next();
        self::assertSame('second', $events->current()->data);
    }

    public function testATimedOutReadEndsWithATransportError(): void
    {
        $body = new ChunkedStream(["data: one\n\n", null, "data: two\n\n"]);
        $events = [];

        try {
            foreach (new EventStream($body, 'req_1', 'pixelTurns.create') as $event) {
                $events[] = $event->data;
            }
            self::fail('No error.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::TRANSPORT_FAILED, $e->errorCode);
            self::assertSame('pixelTurns.create', $e->operation);
            self::assertSame('req_1', $e->requestId);
            self::assertFalse($e->retryable, 'A turn read in part is not sent again.');
        }
        self::assertSame(['one'], $events);
        self::assertTrue($body->closed);
    }

    public function testAFailedReadEndsWithATransportErrorCarryingItsCause(): void
    {
        $cause = new RuntimeException('Connection reset');
        $body = new ChunkedStream(["data: one\n\n", $cause]);

        try {
            iterator_to_array(new EventStream($body, 'req_1', 'pixelTurns.create'));
            self::fail('No error.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::TRANSPORT_FAILED, $e->errorCode);
            self::assertSame($cause, $e->getPrevious());
            self::assertSame(['message' => 'Connection reset'], $e->details);
        }
        self::assertTrue($body->closed);
    }

    public function testIsReadOnce(): void
    {
        $stream = new EventStream("data: a\n\n");
        iterator_to_array($stream);

        $this->expectException(LogicException::class);
        iterator_to_array($stream);
    }

    public function testAnOperationAnsweringEventsReturnsBeforeReadingTheBody(): void
    {
        $harness = new Harness();
        $body = new ChunkedStream(["event: turn.started\ndata: {}\n\n", "event: turn.ended\ndata: {}\n\n"]);
        $harness->http->addResponse(new Response(200, ['Content-Type' => 'text/event-stream', 'X-Request-Id' => 'req_9'], $body));

        $stream = $harness->pixonaut->call('pixelTurns.create', body: ['message' => 'Hi'])->eventStream();

        self::assertSame(0, $body->reads);
        self::assertSame('text/event-stream', $harness->lastSent()->getHeaderLine('Accept'));
        self::assertSame('req_9', $stream->requestId);
        self::assertSame(['turn.started', 'turn.ended'], array_map(
            static fn(StreamEvent $event): string => $event->event,
            iterator_to_array($stream, false),
        ));
    }

    public function testAnOperationAnsweringEventsStillReadsAnError(): void
    {
        $harness = new Harness();
        $harness->answer(409, '{"error":"turn_in_progress","message":"A turn is running."}');

        try {
            $harness->pixonaut->call('pixelTurns.create', body: ['message' => 'Hi']);
            self::fail('No error.');
        } catch (ApiException $e) {
            self::assertSame('turn_in_progress', $e->errorCode);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherContentTypes(): iterable
    {
        yield 'a proxy page' => ['text/html'];
        yield 'JSON' => ['application/json'];
        yield 'none' => [''];
    }

    #[DataProvider('otherContentTypes')]
    public function testAnOperationAnsweringEventsRefusesASuccessOfAnotherType(string $contentType): void
    {
        $harness = new Harness();
        $harness->http->addResponse(new Response(200, $contentType === '' ? [] : ['Content-Type' => $contentType], '<html>Bad gateway</html>'));

        try {
            $harness->pixonaut->call('pixelTurns.create', body: ['message' => 'Hi']);
            self::fail('No error.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::UNEXPECTED_RESPONSE, $e->errorCode);
            self::assertSame(['body' => '<html>Bad gateway</html>'], $e->details);
        }
    }

    public function testHandleReadsAnEventStreamAndChecksItsType(): void
    {
        $harness = new Harness();
        $request = $harness->pixonaut->prepare('pixelTurns.create', body: ['message' => 'Hi']);

        $stream = $harness->pixonaut->handle($request, new Response(200, ['Content-Type' => 'Text/Event-Stream; charset=utf-8'], "data: a\n\n"))->eventStream();
        self::assertSame(['a'], array_map(static fn(StreamEvent $event): string => $event->data, iterator_to_array($stream, false)));

        $this->expectException(ApiException::class);
        $harness->pixonaut->handle($request, new Response(200, ['Content-Type' => 'text/plain'], 'data: a'));
    }

    /**
     * @return list<StreamEvent>
     */
    private static function read(string $body): array
    {
        return iterator_to_array(new EventStream($body), false);
    }
}
