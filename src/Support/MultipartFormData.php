<?php

declare(strict_types=1);

namespace Pest\Browser\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Splits a `multipart/form-data` body into the parameters and uploaded files a
 * traditional SAPI would have handed to PHP, so Symfony's request handling sees
 * the same shape it does under Apache or FPM.
 *
 * Only the splitting is done here. The bracketed field names are resolved by
 * `parse_str`, and the uploads become `UploadedFile` instances, exactly as they
 * would have been built from `$_POST` and `$_FILES`.
 *
 * @internal
 */
final readonly class MultipartFormData
{
    /**
     * Creates a new multipart form data instance.
     *
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $files
     * @param  array<int, string>  $temporaryPaths
     */
    private function __construct(
        public array $parameters,
        public array $files,
        public array $temporaryPaths,
    ) {
        //
    }

    /**
     * Determines whether the given content type describes a multipart form body.
     */
    public static function matches(string $contentType): bool
    {
        return str_starts_with(mb_strtolower($contentType), 'multipart/form-data');
    }

    /**
     * Parses the given body using the boundary from the given content type.
     */
    public static function parse(string $contentType, string $body): self
    {
        $boundary = self::boundary($contentType);

        if ($boundary === null || $body === '') {
            return new self([], [], []);
        }

        $parameterPairs = [];
        $filePairs = [];
        $uploads = [];
        $temporaryPaths = [];

        foreach (self::parts($body, $boundary) as [$headers, $contents]) {
            $disposition = $headers['content-disposition'] ?? '';
            $name = self::parameterOf($disposition, 'name');

            if ($name === null) {
                continue;
            }

            $filename = self::parameterOf($disposition, 'filename');

            if ($filename === null) {
                $parameterPairs[] = urlencode($name).'='.urlencode($contents);

                continue;
            }

            $mimeType = $headers['content-type'] ?? null;

            if ($filename === '') {
                $uploads[] = new UploadedFile('', '', $mimeType, UPLOAD_ERR_NO_FILE, true);
            } else {
                $path = tempnam(sys_get_temp_dir(), 'pest-browser-upload');

                if ($path === false) {
                    continue;
                }

                file_put_contents($path, $contents);

                $temporaryPaths[] = $path;
                $uploads[] = new UploadedFile($path, $filename, $mimeType, UPLOAD_ERR_OK, true);
            }

            $filePairs[] = urlencode($name).'='.(count($uploads) - 1);
        }

        parse_str(implode('&', $parameterPairs), $parameters);
        parse_str(implode('&', $filePairs), $fileIndexes);

        return new self(
            $parameters,
            self::hydrate($fileIndexes, $uploads),
            $temporaryPaths,
        );
    }

    /**
     * Removes the temporary files created for the uploads.
     *
     * The application may already have moved one of them, which is why each is
     * checked before being removed.
     */
    public function cleanup(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Gets the boundary from the given content type, if any.
     */
    private static function boundary(string $contentType): ?string
    {
        if (preg_match('/boundary="?([^";,]+)"?/i', $contentType, $matches) !== 1) {
            return null;
        }

        return mb_trim($matches[1]);
    }

    /**
     * Splits the body into its parts, each a map of headers and its contents.
     *
     * The body is binary, so every operation here is byte based. The leading
     * delimiter is prefixed to the body so the first part is split like the rest.
     *
     * @return array<int, array{0: array<string, string>, 1: string}>
     */
    private static function parts(string $body, string $boundary): array
    {
        $parts = [];

        foreach (explode("\r\n--".$boundary, "\r\n".$body) as $index => $segment) {
            if ($index === 0) {
                continue;
            }

            if (str_starts_with($segment, '--')) {
                break;
            }

            $segments = explode("\r\n\r\n", $segment, 2);

            if (count($segments) !== 2) {
                continue;
            }

            $parts[] = [self::headers($segments[0]), $segments[1]];
        }

        return $parts;
    }

    /**
     * Parses the headers of a single part.
     *
     * @return array<string, string>
     */
    private static function headers(string $raw): array
    {
        $headers = [];

        foreach (explode("\r\n", mb_ltrim($raw, "\r\n")) as $line) {
            $position = mb_strpos($line, ':');

            if ($position === false) {
                continue;
            }

            $name = mb_strtolower(mb_trim(mb_substr($line, 0, $position)));

            $headers[$name] = mb_trim(mb_substr($line, $position + 1));
        }

        return $headers;
    }

    /**
     * Gets the given parameter from a part header, if any.
     */
    private static function parameterOf(string $header, string $parameter): ?string
    {
        $parameter = preg_quote($parameter, '/');

        if (preg_match('/\b'.$parameter.'="([^"]*)"/i', $header, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\b'.$parameter.'=([^;]+)/i', $header, $matches) === 1) {
            return mb_trim($matches[1]);
        }

        return null;
    }

    /**
     * Replaces the upload indexes left by `parse_str` with their uploaded files.
     *
     * @param  array<array-key, mixed>  $indexes
     * @param  array<int, UploadedFile>  $uploads
     * @return array<array-key, mixed>
     */
    private static function hydrate(array $indexes, array $uploads): array
    {
        $files = [];

        foreach ($indexes as $key => $index) {
            if (is_array($index)) {
                $files[$key] = self::hydrate($index, $uploads);

                continue;
            }

            if (! is_string($index) || ! isset($uploads[(int) $index])) {
                continue;
            }

            $files[$key] = $uploads[(int) $index];
        }

        return $files;
    }
}
