<?php

declare(strict_types=1);

namespace Pest\Browser\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Builds the file payloads Playwright expects when setting the files of a file
 * input.
 *
 * The contents are sent rather than the paths because the Playwright server runs
 * as a separate process, connected over a websocket, so it treats this client as
 * remote and refuses local paths it cannot be sure it can read. Payloads are the
 * transport Playwright provides for exactly that case.
 *
 * @internal
 */
final class FilePayload
{
    /**
     * The largest total upload Playwright accepts for a single call.
     */
    private const int SIZE_LIMIT = 50 * 1024 * 1024;

    /**
     * Builds the payloads for the given local paths.
     *
     * @return array<int, array{name: string, mimeType: string, buffer: string}>
     */
    public static function forPaths(string ...$paths): array
    {
        if ($paths === []) {
            throw new InvalidArgumentException('At least one file must be given.');
        }

        self::assertWithinSizeLimit(...$paths);

        $payloads = [];

        foreach ($paths as $path) {
            $payloads[] = self::forPath($path);
        }

        return $payloads;
    }

    /**
     * Builds the payload for a single local path.
     *
     * @return array{name: string, mimeType: string, buffer: string}
     */
    private static function forPath(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("The file [{$path}] could not be read.");
        }

        return [
            'name' => basename($path),
            'mimeType' => self::mimeType($path),
            'buffer' => base64_encode($contents),
        ];
    }

    /**
     * Asserts every path is a readable file, and that they fit in one call.
     */
    private static function assertWithinSizeLimit(string ...$paths): void
    {
        $total = 0;

        foreach ($paths as $path) {
            if (! is_file($path)) {
                throw new InvalidArgumentException("The file [{$path}] does not exist.");
            }

            $size = filesize($path);

            $total += $size === false ? 0 : $size;
        }

        if ($total >= self::SIZE_LIMIT) {
            throw new InvalidArgumentException(sprintf(
                'The given files total %d bytes, which is over the %d byte limit Playwright accepts for a single upload.',
                $total,
                self::SIZE_LIMIT,
            ));
        }
    }

    /**
     * Guesses the media type of the given file.
     *
     * The type is sniffed from the contents where the fileinfo extension is
     * available, and falls back to the type a browser sends for a file it cannot
     * place.
     */
    private static function mimeType(string $path): string
    {
        if (! function_exists('mime_content_type')) {
            return 'application/octet-stream';
        }

        $mimeType = mime_content_type($path);

        return $mimeType === false ? 'application/octet-stream' : $mimeType;
    }
}
