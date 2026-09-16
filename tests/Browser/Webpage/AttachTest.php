<?php

declare(strict_types=1);

it('may attach a file to a file input', function (): void {
    Route::get('/', fn (): string => '
        <form>
            <input type="file" id="avatar" name="avatar">
        </form>
    ');

    $page = visit('/');

    // Create a temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'test');
    file_put_contents($tempFile, 'test content');

    $page->attach('avatar', $tempFile);

    // Check that the file is attached
    $fileName = basename($tempFile);
    expect($page->script('() => document.querySelector("input[name=avatar]").files[0].name'))->toBe($fileName);

    // Clean up
    unlink($tempFile);
});

it('may attach a file to a file input using an id selector', function (): void {
    Route::get('/', fn (): string => '
        <form>
            <input type="file" id="avatar" name="avatar">
        </form>
    ');

    $page = visit('/');

    // Create a temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'test');
    file_put_contents($tempFile, 'test content');

    $page->attach('#avatar', $tempFile);

    // Check that the file is attached
    $fileName = basename($tempFile);
    expect($page->script('() => document.querySelector("#avatar").files[0].name'))->toBe($fileName);

    // Clean up
    unlink($tempFile);
});

it('sends the file contents rather than its path, so a remote Playwright accepts it', function (): void {
    Route::get('/', fn (): string => '
        <form>
            <input type="file" id="avatar" name="avatar">
        </form>
    ');

    $page = visit('/');

    // A PNG behind a .txt name: Playwright would read "text/plain" off the path,
    // so seeing "image/png" in the browser proves the contents were sent.
    $tempFile = tempnam(sys_get_temp_dir(), 'test').'.txt';
    file_put_contents($tempFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));

    $page->attach('avatar', $tempFile);

    expect($page->script('() => document.querySelector("#avatar").files[0].type'))->toBe('image/png');

    unlink($tempFile);
});

it('may attach several files to one file input', function (): void {
    Route::get('/', fn (): string => '
        <form>
            <input type="file" id="documents" name="documents[]" multiple>
        </form>
    ');

    $page = visit('/');

    $first = tempnam(sys_get_temp_dir(), 'test');
    $second = tempnam(sys_get_temp_dir(), 'test');
    file_put_contents($first, 'first');
    file_put_contents($second, 'second');

    $page->attach('#documents', $first, $second);

    expect($page->script('() => document.querySelector("#documents").files.length'))->toBe(2)
        ->and($page->script('() => document.querySelector("#documents").files[0].name'))->toBe(basename($first))
        ->and($page->script('() => document.querySelector("#documents").files[1].name'))->toBe(basename($second));

    unlink($first);
    unlink($second);
});

it('names a file that cannot be attached because it does not exist', function (): void {
    Route::get('/', fn (): string => '
        <form>
            <input type="file" id="avatar" name="avatar">
        </form>
    ');

    $page = visit('/');

    $path = sys_get_temp_dir().'/pest-browser-missing-attachment.txt';

    expect(fn () => $page->attach('avatar', $path))
        ->toThrow(InvalidArgumentException::class, "The file [{$path}] does not exist.");
});
