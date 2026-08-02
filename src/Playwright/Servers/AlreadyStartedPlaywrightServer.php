<?php

declare(strict_types=1);

namespace Pest\Browser\Playwright\Servers;

use JsonException;
use Pest\Browser\Contracts\PlaywrightServer;
use Pest\Plugins\Parallel;
use RuntimeException;

/**
 * @internal
 */
final readonly class AlreadyStartedPlaywrightServer implements PlaywrightServer
{
    /**
     * The environment variable holding the path of this run's state file.
     */
    private const string STATE_FILE_VARIABLE = 'PEST_BROWSER_PLAYWRIGHT_STATE_FILE';

    /**
     * Creates a new already started playwright server instance.
     */
    public function __construct(
        public string $host,
        public int $port,
    ) {
        //
    }

    /**
     * Creates a new instance of the Playwright server with the persisted host and port.
     *
     * @throws JsonException
     */
    public static function fromPersisted(): self
    {
        $path = self::path();

        $path = file_get_contents($path);

        if ($path === false) {
            throw new RuntimeException('Could not read Playwright server data from file.');
        }

        // @phpstan-ignore-next-line
        ['host' => $host, 'port' => $port] = json_decode($path, true, 512, JSON_THROW_ON_ERROR);

        assert(is_string($host) && is_numeric($port), 'Invalid Playwright server data persisted.');

        return new self($host, (int) $port);
    }

    /**
     * Persists the Playwright server instance with the given host and port
     * as already started; this is useful for scenarios where the server is
     * already running and you want to connect to it as if it were started.
     *
     * @throws JsonException
     */
    public static function persist(string $host, int $port): void
    {
        $data = [
            'host' => $host,
            'port' => $port,
        ];

        $path = self::path();

        if (! file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Marks the Playwright server a stopped by removing the persisted state file.
     */
    public static function markAsStopped(): void
    {
        $path = self::path();

        @unlink($path);
    }

    /**
     * Starts the process until the given "output" condition is met.
     */
    public function start(): void
    {
        //
    }

    /**
     * Stops the process if it is running.
     */
    public function stop(): void
    {
        //
    }

    /**
     * Flushes the process.
     */
    public function flush(): void
    {
        //
    }

    /**
     * Returns the URL of the process.
     *
     * @throws RuntimeException If the process has not been started yet or has stopped unexpectedly.
     */
    public function url(): string
    {
        return sprintf('%s:%d', $this->host, $this->port);
    }

    /**
     * Returns the state file of the Playwright server.
     *
     * The path is unique per run and lives in the system temporary directory,
     * so that concurrent Pest runs cannot overwrite or delete each other's
     * state: every run terminates by unlinking this file, including runs that
     * never touched a browser test.
     *
     * The main process resolves the path once and exports it to the
     * environment; parallel workers inherit it and never resolve their own.
     */
    private static function path(): string
    {
        $path = getenv(self::STATE_FILE_VARIABLE);

        if (is_string($path) && $path !== '') {
            return $path;
        }

        if (Parallel::isWorker()) {
            throw new RuntimeException(
                'The Playwright server state file was not inherited from the main process.'
            );
        }

        $processId = getmypid();

        if ($processId === false) {
            throw new RuntimeException('Could not determine the current process id.');
        }

        $path = sprintf(
            '%s%spest-playwright-server.%d.json',
            sys_get_temp_dir(),
            DIRECTORY_SEPARATOR,
            $processId,
        );

        putenv(self::STATE_FILE_VARIABLE.'='.$path);

        // Symfony's Process intersects getenv() with $_SERVER when composing a
        // child's environment, so a putenv-only value never reaches a worker.
        $_ENV[self::STATE_FILE_VARIABLE] = $path;
        $_SERVER[self::STATE_FILE_VARIABLE] = $path;

        return $path;
    }
}
