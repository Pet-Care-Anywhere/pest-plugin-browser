<?php

declare(strict_types=1);

use Pest\Browser\Support\PackageJsonDirectory;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * The version of Playwright installed alongside this test run, if it can be read.
 */
function actionTimeoutTest_playwrightVersion(): ?string
{
    $path = PackageJsonDirectory::find().'/node_modules/playwright/package.json';

    if (! is_file($path)) {
        return null;
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        return null;
    }

    $data = json_decode($contents, true);

    return is_array($data) && isset($data['version']) && is_string($data['version'])
        ? $data['version']
        : null;
}

it('describes the page when an action times out', function (): void {
    Route::get('/', fn (): string => '<h1>Pet profile</h1><p>Bella, cat, 4 years old.</p><button>Edit pet</button>');

    $page = visit('/');

    expect(fn () => $page->press('Delete pet'))->toThrow(
        function (ExpectationFailedException $e): void {
            expect($e->getMessage())
                ->toContain('exceeded while waiting for [click]')
                ->toContain('Delete pet')
                ->toContain('the current url [http://127.0.0.1')
                ->toContain("The page's visible text was: [Pet profile Bella, cat, 4 years old. Edit pet]");
        },
    );
})->skip(
    fn (): bool => version_compare(actionTimeoutTest_playwrightVersion() ?? '0.0.0', '1.60.0', '<'),
    'Playwright below 1.60 enforces the timeout it is sent, so the client-side deadline this test covers never fires.',
);
