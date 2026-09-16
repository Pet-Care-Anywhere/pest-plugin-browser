<?php

declare(strict_types=1);

use Pest\Browser\Support\MultipartFormData;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Builds a multipart body from the given parts, using CRLF line endings.
 *
 * @param  array<int, array{0: string, 1: string, 2: string|null}>  $parts
 */
function multipartFormData_body(string $boundary, array $parts): string
{
    $body = '';

    foreach ($parts as [$disposition, $contents, $contentType]) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: {$disposition}\r\n";

        if ($contentType !== null) {
            $body .= "Content-Type: {$contentType}\r\n";
        }

        $body .= "\r\n{$contents}\r\n";
    }

    return $body."--{$boundary}--\r\n";
}

it('detects multipart content types', function (): void {
    expect(MultipartFormData::matches('multipart/form-data; boundary=abc'))->toBeTrue()
        ->and(MultipartFormData::matches('MULTIPART/FORM-DATA; boundary=abc'))->toBeTrue()
        ->and(MultipartFormData::matches('application/x-www-form-urlencoded'))->toBeFalse();
});

it('parses plain fields, including bracketed names', function (): void {
    $body = multipartFormData_body('abc123', [
        ['form-data; name="title"', 'Hello World', null],
        ['form-data; name="_method"', 'PUT', null],
        ['form-data; name="tags[]"', 'one', null],
        ['form-data; name="tags[]"', 'two', null],
        ['form-data; name="owner[name]"', 'Taylor', null],
    ]);

    $form = MultipartFormData::parse('multipart/form-data; boundary=abc123', $body);

    expect($form->parameters)->toBe([
        'title' => 'Hello World',
        '_method' => 'PUT',
        'tags' => ['one', 'two'],
        'owner' => ['name' => 'Taylor'],
    ])->and($form->files)->toBe([]);
});

it('parses uploaded files, keeping their contents and client names', function (): void {
    $body = multipartFormData_body('abc123', [
        ['form-data; name="title"', 'Hello World', null],
        ['form-data; name="document"; filename="invoice.txt"', "line one\r\nline two", 'text/plain'],
    ]);

    $form = MultipartFormData::parse('multipart/form-data; boundary="abc123"', $body);

    expect($form->parameters)->toBe(['title' => 'Hello World'])
        ->and($form->files)->toHaveKey('document')
        ->and($form->files['document'])->toBeInstanceOf(UploadedFile::class);

    $file = $form->files['document'];

    expect($file->getClientOriginalName())->toBe('invoice.txt')
        ->and($file->getClientMimeType())->toBe('text/plain')
        ->and($file->getError())->toBe(UPLOAD_ERR_OK)
        ->and(file_get_contents($file->getPathname()))->toBe("line one\r\nline two");

    $form->cleanup();

    expect(file_exists($file->getPathname()))->toBeFalse();
});

it('parses binary uploads without corrupting them', function (): void {
    $contents = random_bytes(2048);

    $body = multipartFormData_body('abc123', [
        ['form-data; name="files[0]"; filename="one.bin"', $contents, 'application/octet-stream'],
    ]);

    $form = MultipartFormData::parse('multipart/form-data; boundary=abc123', $body);

    expect($form->files['files'][0])->toBeInstanceOf(UploadedFile::class)
        ->and(file_get_contents($form->files['files'][0]->getPathname()))->toBe($contents);

    $form->cleanup();
});

it('marks an empty file input as having no file, like PHP does', function (): void {
    $body = multipartFormData_body('abc123', [
        ['form-data; name="document"; filename=""', '', 'application/octet-stream'],
    ]);

    $form = MultipartFormData::parse('multipart/form-data; boundary=abc123', $body);

    expect($form->files['document']->getError())->toBe(UPLOAD_ERR_NO_FILE)
        ->and($form->temporaryPaths)->toBe([]);
});

it('returns nothing when the content type carries no boundary', function (): void {
    $form = MultipartFormData::parse('multipart/form-data', 'whatever');

    expect($form->parameters)->toBe([])
        ->and($form->files)->toBe([])
        ->and($form->temporaryPaths)->toBe([]);
});
