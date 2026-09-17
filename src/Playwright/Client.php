<?php

declare(strict_types=1);

namespace Pest\Browser\Playwright;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Generator;
use Pest\Browser\Exceptions\ActionTimedOutException;
use Pest\Browser\Exceptions\PlaywrightOutdatedException;
use Pest\Browser\Support\JavaScriptSerializer;
use Pest\Browser\Support\Str;
use PHPUnit\Framework\ExpectationFailedException;
use Throwable;

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
     * The whole budget, in milliseconds, allowed for describing a failed match.
     *
     * Both diagnostic calls share this one clock, so the worst case a failing
     * action can pay for its own explanation is a single constant, however many
     * queries the explanation turns out to need. It is deliberately far below a
     * normal action timeout: neither query waits for anything on the page, so a
     * healthy browser answers in milliseconds and the budget is only ever
     * reached by a browser that has stopped answering at all.
     */
    private const int DIAGNOSTIC_BUDGET_MILLISECONDS = 1_000;

    /**
     * How many of the matched elements a failure message names.
     *
     * Two is enough to tell the reader what kind of collision they have - a
     * heading sharing a button's label, a table header eating a click - without
     * turning the message into a DOM dump.
     */
    private const int NAMED_MATCHES = 2;

    /**
     * The maximum number of characters of a matched element's text included.
     */
    private const int MATCH_TEXT_LENGTH = 40;

    /**
     * Reads the tag and text of the first few elements a selector matched.
     */
    private const string NAMED_MATCHES_EXPRESSION = '(elements, limit) => elements.slice(0, limit).map((element) => ({ tag: element.tagName, text: element.innerText || element.textContent || "" }))';

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

        try {
            while (true) {
                $responseJson = $this->fetch($this->websocketConnection, $deadline);
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
        } catch (CancelledException) {
            $this->abandon($requestId);

            $subject = $this->subject($method, $params, $timeout, $this->describeMatches($guid, $params));

            throw new ExpectationFailedException($subject.'.', null, new ActionTimedOutException($subject));
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
     * @throws CancelledException When no reply arrives before the deadline.
     */
    private function fetch(WebsocketConnection $client, ?float $deadline): string
    {
        if ($deadline === null) {
            return (string) $client->receive()?->read();
        }

        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            throw new CancelledException();
        }

        $cancellation = new TimeoutCancellation($remaining);

        return (string) $client->receive($cancellation)?->read($cancellation);
    }

    /**
     * Describes what a request was waiting for, for use in a failure message.
     *
     * The selector is named whenever the request carries one, because it is the
     * difference between a reader knowing a label was renamed and a reader
     * having to open the test to find out which label was even asked for.
     *
     * The match count rides alongside the selector because the two are read
     * together: a selector that matched nothing and a selector that matched
     * three things are opposite problems - a label that has been renamed
     * against a label that is ambiguous - and without the count they produce a
     * byte-identical message.
     *
     * @param  array<string, mixed>  $params
     */
    private function subject(string $method, array $params, int $timeout, ?string $matches = null): string
    {
        $subject = sprintf('Timeout %dms exceeded while waiting for [%s]', $timeout, $method);

        if (isset($params['selector']) && is_string($params['selector']) && $params['selector'] !== '') {
            $subject .= sprintf(' on selector [%s]', $params['selector']);
        }

        if ($matches !== null) {
            $subject .= sprintf(' (%s)', $matches);
        }

        return $subject;
    }

    /**
     * Says how many elements the selector matched, for use in a failure message.
     *
     * This is the half of a timeout the message could not previously supply.
     * The method, the selector, the url and the page's text all describe what
     * was asked for and where; the count describes what answered, and it is the
     * only one of them that separates "the label has been renamed" from "the
     * label is ambiguous". Those need opposite fixes and otherwise look alike.
     *
     * Everything here is best effort and nothing here may make the failure
     * worse. The queries run against the same frame the action ran against, on
     * a budget of their own that cannot extend the wait that has already
     * elapsed, and any failure at all - a wrong guid for a page-level call, a
     * protocol error, a browser that has stopped answering - returns null,
     * which leaves the message exactly as it reads today.
     *
     * @param  array<string, mixed>  $params
     */
    private function describeMatches(string $guid, array $params): ?string
    {
        $selector = $params['selector'] ?? null;

        if (! is_string($selector) || $selector === '') {
            return null;
        }

        $deadline = microtime(true) + (self::DIAGNOSTIC_BUDGET_MILLISECONDS / 1_000);

        try {
            $count = $this->diagnose($guid, 'queryCount', ['selector' => $selector], $deadline);

            if (! is_int($count)) {
                return null;
            }

            $description = sprintf('%d element%s matched', $count, $count === 1 ? '' : 's');

            if ($count <= 1) {
                return $description;
            }

            $named = $this->nameMatches($guid, $selector, $deadline);

            return $named === null ? $description : $description.': '.$named;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Names the first few elements the selector matched, as tag and text.
     *
     * A bare count of three says the selector is ambiguous but not what it is
     * ambiguous with. "TH" against "BUTTON" says a table header is eating the
     * click, which is otherwise a screenshot and a DOM probe away.
     */
    private function nameMatches(string $guid, string $selector, float $deadline): ?string
    {
        $value = $this->diagnose($guid, 'evalOnSelectorAll', [
            'selector' => $selector,
            'expression' => self::NAMED_MATCHES_EXPRESSION,
            'isFunction' => true,
            'arg' => JavaScriptSerializer::serializeArgument(self::NAMED_MATCHES),
        ], $deadline);

        $matches = JavaScriptSerializer::parseValue($value);

        if (! is_array($matches) || $matches === []) {
            return null;
        }

        $named = [];

        foreach ($matches as $match) {
            if (! is_array($match) || ! isset($match['tag']) || ! is_string($match['tag'])) {
                continue;
            }

            $text = isset($match['text']) && is_string($match['text'])
                ? Str::snippet($match['text'], self::MATCH_TEXT_LENGTH)
                : '';

            $named[] = $text === ''
                ? sprintf('[%s]', $match['tag'])
                : sprintf('[%s "%s"]', $match['tag'], $text);
        }

        return $named === [] ? null : implode(', ', $named);
    }

    /**
     * Asks the browser one question on the failure path, or gives up quickly.
     *
     * A request of its own rather than a call back into execute(), because
     * execute() is built for an action: it adds the client's own timeout, it
     * grants a five second grace on top of that, and it raises an assertion
     * failure when the answer does not come. All three are wrong for a question
     * asked by a failure that has already happened, which must be cheap, must
     * be bounded by the caller's clock, and must never raise anything.
     *
     * The id is abandoned if the answer is late, so a reply that arrives after
     * this has given up is skipped rather than handed to whichever request is
     * reading the socket by then.
     *
     * @param  array<string, mixed>  $params
     */
    private function diagnose(string $guid, string $method, array $params, float $deadline): mixed
    {
        if (! $this->websocketConnection instanceof WebsocketConnection) {
            return null;
        }

        $requestId = uniqid();

        $this->websocketConnection->sendText((string) json_encode([
            'id' => $requestId,
            'guid' => $guid,
            'method' => $method,
            'params' => $params,
            'metadata' => [],
        ]));

        while (true) {
            try {
                $responseJson = $this->fetch($this->websocketConnection, $deadline);
            } catch (CancelledException) {
                $this->abandon($requestId);

                return null;
            }

            $response = json_decode($responseJson, true);

            if (! is_array($response) || ($response['id'] ?? null) !== $requestId) {
                continue;
            }

            $result = $response['result'] ?? null;

            return is_array($result) ? ($result['value'] ?? null) : null;
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
