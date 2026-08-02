<?php

declare(strict_types=1);

use Pest\Browser\Playwright\Servers\AlreadyStartedPlaywrightServer;

beforeEach(function (): void {
    $this->originalPath = getenv('PEST_BROWSER_PLAYWRIGHT_STATE_FILE');
    $this->originalParatest = $_SERVER['PARATEST'] ?? null;

    putenv('PEST_BROWSER_PLAYWRIGHT_STATE_FILE');

    unset(
        $_ENV['PEST_BROWSER_PLAYWRIGHT_STATE_FILE'],
        $_SERVER['PEST_BROWSER_PLAYWRIGHT_STATE_FILE'],
        $_SERVER['PARATEST'],
    );
});

afterEach(function (): void {
    $path = getenv('PEST_BROWSER_PLAYWRIGHT_STATE_FILE');

    if (is_string($path) && $path !== '') {
        @unlink($path);
    }

    putenv('PEST_BROWSER_PLAYWRIGHT_STATE_FILE');

    unset(
        $_ENV['PEST_BROWSER_PLAYWRIGHT_STATE_FILE'],
        $_SERVER['PEST_BROWSER_PLAYWRIGHT_STATE_FILE'],
        $_SERVER['PARATEST'],
    );

    if (is_string($this->originalPath)) {
        putenv('PEST_BROWSER_PLAYWRIGHT_STATE_FILE='.$this->originalPath);

        $_ENV['PEST_BROWSER_PLAYWRIGHT_STATE_FILE'] = $this->originalPath;
        $_SERVER['PEST_BROWSER_PLAYWRIGHT_STATE_FILE'] = $this->originalPath;
    }

    if ($this->originalParatest !== null) {
        $_SERVER['PARATEST'] = $this->originalParatest;
    }
});

it('persists the state outside the package, on a path unique to this run', function (): void {
    AlreadyStartedPlaywrightServer::persist('127.0.0.1', 4444);

    $path = getenv('PEST_BROWSER_PLAYWRIGHT_STATE_FILE');

    expect($path)
        ->toBeString()
        ->toStartWith(sys_get_temp_dir())
        ->toContain((string) getmypid())
        ->not->toContain('.temp');

    expect(file_exists((string) $path))->toBeTrue();
});

it('exports the resolved path so that parallel workers inherit it', function (): void {
    AlreadyStartedPlaywrightServer::persist('127.0.0.1', 4444);

    $path = getenv('PEST_BROWSER_PLAYWRIGHT_STATE_FILE');

    // Symfony's Process intersects getenv() with $_SERVER, so a putenv-only
    // value is dropped on the way to a worker.
    expect($_ENV['PEST_BROWSER_PLAYWRIGHT_STATE_FILE'])->toBe($path)
        ->and($_SERVER['PEST_BROWSER_PLAYWRIGHT_STATE_FILE'])->toBe($path);
});

it('reads back the host and port it persisted', function (): void {
    AlreadyStartedPlaywrightServer::persist('127.0.0.1', 4444);

    $server = AlreadyStartedPlaywrightServer::fromPersisted();

    expect($server->host)->toBe('127.0.0.1')
        ->and($server->port)->toBe(4444)
        ->and($server->url())->toBe('127.0.0.1:4444');
});

it('honours an inherited state file path', function (): void {
    $path = sys_get_temp_dir().'/pest-playwright-server.inherited.json';

    putenv('PEST_BROWSER_PLAYWRIGHT_STATE_FILE='.$path);

    AlreadyStartedPlaywrightServer::persist('127.0.0.1', 5555);

    expect(file_exists($path))->toBeTrue()
        ->and(AlreadyStartedPlaywrightServer::fromPersisted()->port)->toBe(5555);
});

it('leaves the state of a concurrent run untouched when stopping', function (): void {
    $concurrent = sys_get_temp_dir().'/pest-playwright-server.concurrent.json';

    file_put_contents($concurrent, json_encode(['host' => '127.0.0.1', 'port' => 6666]));

    AlreadyStartedPlaywrightServer::persist('127.0.0.1', 4444);

    $path = (string) getenv('PEST_BROWSER_PLAYWRIGHT_STATE_FILE');

    AlreadyStartedPlaywrightServer::markAsStopped();

    expect(file_exists($path))->toBeFalse()
        ->and(file_exists($concurrent))->toBeTrue();

    unlink($concurrent);
});

it('fails loudly when a worker did not inherit a state file path', function (): void {
    $_SERVER['PARATEST'] = 1;

    AlreadyStartedPlaywrightServer::persist('127.0.0.1', 4444);
})->throws(RuntimeException::class, 'The Playwright server state file was not inherited from the main process.');
