<?php

declare(strict_types=1);

namespace Pest\Browser\Playwright;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Generator;
use Pest\Browser\Exceptions\PlaywrightOutdatedException;
use PHPUnit\Framework\ExpectationFailedException;

use function Amp\Websocket\Client\connect;

/**
 * @internal
 */
final class Client
{
    /**
     * The grace, in milliseconds, added to a request's own timeout before this
     * client gives up waiting for its reply.
     *
     * The deadline is a safety net, not a second source of truth for the
     * timeout, so it must never fire before the timeout the request itself
     * carries. The grace covers the round trip and any scheduling delay on a
     * loaded machine.
     */
    private const int DEADLINE_GRACE_MILLISECONDS = 5_000;

    /**
     * How many abandoned request ids to remember.
     *
     * Only enough to cover replies still in flight when their request was
     * abandoned; the list is per process and never needs to be long.
     */
    private const int ABANDONED_HISTORY = 64;

    /**
     * Client instance.
     */
    private static ?Client $instance = null;

    /**
     * WebSocket client instance.
     */
    private ?WebsocketConnection $websocketConnection = null;

    /**
     * Default timeout for requests in milliseconds.
     */
    private int $timeout = 5_000;

    /**
     * Ids of requests this client stopped waiting for.
     *
     * A reply may still arrive for one of them. It belongs to nobody, so it is
     * skipped rather than handed to whichever request happens to be reading the
     * socket at the time; an abandoned error would otherwise fail an unrelated
     * assertion.
     *
     * @var array<int, string>
     */
    private array $abandoned = [];

    /**
     * Returns the current client instance.
     */
    public static function instance(): self
    {
        if (! self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Connects to the Playwright server.
     */
    public function connectTo(string $url): void
    {
        if (! $this->websocketConnection instanceof WebsocketConnection) {
            $browser = Playwright::defaultBrowserType()->toPlaywrightName();

            $launchOptions = json_encode([
                'headless' => Playwright::isHeadless(),
                'ignoreHTTPSErrors' => true,
                'bypassCSP' => true,
            ]);

            $this->websocketConnection = connect(
                "ws://$url?browser=$browser&launch-options=$launchOptions",
            );
        }
    }

    /**
     * Executes a method on the Playwright instance.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $meta
     * @return Generator<array<string, mixed>>
     */
    public function execute(string $guid, string $method, array $params = [], array $meta = []): Generator
    {
        assert($this->websocketConnection instanceof WebsocketConnection, 'WebSocket client is not connected.');

        $requestId = uniqid();

        $params = ['timeout' => $this->timeout, ...$params];

        $requestJson = (string) json_encode([
            'id' => $requestId,
            'guid' => $guid,
            'method' => $method,
            'params' => $params,
            'metadata' => $meta,
        ]);

        $this->websocketConnection->sendText($requestJson);

        $timeout = is_numeric($params['timeout']) ? (int) $params['timeout'] : $this->timeout;
        $deadline = $timeout > 0
            ? microtime(true) + (($timeout + self::DEADLINE_GRACE_MILLISECONDS) / 1_000)
            : null;

        while (true) {
            $responseJson = $this->fetch($this->websocketConnection, $requestId, $method, $timeout, $deadline);
            /** @var array{id: string|null, params: array{add: string|null}, error: array{error: array{message: string|null}}} $response */
            $response = json_decode($responseJson, true);

            if (isset($response['id']) && in_array($response['id'], $this->abandoned, true)) {
                continue;
            }

            if (isset($response['error']['error']['message'])) {
                $message = $response['error']['error']['message'];

                if (str_contains($message, 'Playwright was just installed or updated')) {
                    throw new PlaywrightOutdatedException();
                }

                throw new ExpectationFailedException($message);
            }

            yield $response;

            if (
                (isset($response['id']) && $response['id'] === $requestId)
                || (isset($params['waitUntil']) && isset($response['params']['add']) && $params['waitUntil'] === $response['params']['add'])
            ) {
                break;
            }
        }
    }

    /**
     * Sets the timeout in milliseconds for requests.
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Returns the current timeout for requests.
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * Fetches the response from the Playwright server.
     *
     * Playwright no longer enforces the `timeout` carried in a request's
     * params. Its own client now keeps the clock and aborts locally: on 1.63 a
     * `fill` sent over the raw protocol with `timeout: 5` still succeeds, and
     * one sent for a selector that never matches is never answered at all. On
     * 1.59 the same call came back with "Timeout 4000ms exceeded".
     *
     * So a raw protocol client has no timeout, and an action that can never
     * succeed, such as a click on a selector that has drifted, blocks the
     * caller for ever. Keeping the clock here is what the supported client now
     * does, and it is the only thing standing between one drifted selector and
     * a test run that never ends.
     *
     * @throws ExpectationFailedException When no reply arrives before the deadline.
     */
    private function fetch(
        WebsocketConnection $client,
        string $requestId,
        string $method,
        int $timeout,
        ?float $deadline,
    ): string {
        if ($deadline === null) {
            return (string) $client->receive()?->read();
        }

        $remaining = $deadline - microtime(true);

        try {
            if ($remaining <= 0) {
                throw new CancelledException();
            }

            $cancellation = new TimeoutCancellation($remaining);

            return (string) $client->receive($cancellation)?->read($cancellation);
        } catch (CancelledException) {
            $this->abandon($requestId);

            throw new ExpectationFailedException(
                sprintf('Timeout %dms exceeded while waiting for [%s].', $timeout, $method)
            );
        }
    }

    /**
     * Records a request this client has stopped waiting for.
     */
    private function abandon(string $requestId): void
    {
        $this->abandoned[] = $requestId;

        if (count($this->abandoned) > self::ABANDONED_HISTORY) {
            array_shift($this->abandoned);
        }
    }
}
