<?php

declare(strict_types=1);

use Pest\Browser\Support\FilePayload;

/**
 * Writes the given contents to a temporary file with the given extension.
 */
function filePayload_file(string $contents, string $extension = '.txt'): string
{
    $path = tempnam(sys_get_temp_dir(), 'pest-browser-payload').$extension;

    file_put_contents($path, $contents);

    return $path;
}

it('builds a payload carrying the file name, media type and contents', function (): void {
    $path = filePayload_file('the file contents');

    $payloads = FilePayload::forPaths($path);

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['name'])->toBe(basename($path))
        ->and($payloads[0]['mimeType'])->toBe('text/plain')
        ->and(base64_decode($payloads[0]['buffer'], true))->toBe('the file contents');

    unlink($path);
});

it('derives the media type from the contents rather than the extension', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

    expect($png)->not->toBeFalse();

    $path = filePayload_file((string) $png, '.txt');

    expect(FilePayload::forPaths($path)[0]['mimeType'])->toBe('image/png');

    unlink($path);
});

it('keeps binary contents intact', function (): void {
    $contents = random_bytes(4096);

    $path = filePayload_file($contents, '.bin');

    expect(base64_decode(FilePayload::forPaths($path)[0]['buffer'], true))->toBe($contents);

    unlink($path);
});

it('builds a payload for every given file, in order', function (): void {
    $first = filePayload_file('first');
    $second = filePayload_file('second');

    $payloads = FilePayload::forPaths($first, $second);

    expect($payloads)->toHaveCount(2)
        ->and(base64_decode($payloads[0]['buffer'], true))->toBe('first')
        ->and(base64_decode($payloads[1]['buffer'], true))->toBe('second');

    unlink($first);
    unlink($second);
});

it('names the file that does not exist', function (): void {
    $path = sys_get_temp_dir().'/pest-browser-does-not-exist.txt';

    expect(fn (): array => FilePayload::forPaths($path))
        ->toThrow(InvalidArgumentException::class, "The file [{$path}] does not exist.");
});

it('refuses to attach nothing', function (): void {
    expect(fn (): array => FilePayload::forPaths())
        ->toThrow(InvalidArgumentException::class, 'At least one file must be given.');
});

it('refuses files over the size Playwright accepts', function (): void {
    $path = filePayload_file('', '.bin');

    $handle = fopen($path, 'r+');

    expect($handle)->not->toBeFalse();

    assert($handle !== false);

    ftruncate($handle, 50 * 1024 * 1024);
    fclose($handle);

    expect(fn (): array => FilePayload::forPaths($path))
        ->toThrow(InvalidArgumentException::class, 'which is over the 52428800 byte limit');

    unlink($path);
});
