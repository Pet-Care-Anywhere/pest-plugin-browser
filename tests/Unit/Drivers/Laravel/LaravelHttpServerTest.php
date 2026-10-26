<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('rewrites the URLs on JS files', function (): void {
    @file_put_contents(
        public_path('app.js'),
        <<<'JS'
        console.log('Hello http://localhost');
        JS,
    );

    $page = visit('/app.js');

    $page->assertSee('http://127.0.0.1')
        ->assertDontSee('http://localhost');
});

it('parses multipart form data, including uploaded files and spoofed methods', function (): void {
    Route::get('/upload', fn (): string => '
        <form method="POST" action="/upload" enctype="multipart/form-data">
            <input type="hidden" name="_method" value="PUT">
            <input type="text" name="title" value="Quarterly report">
            <input type="file" id="document" name="document">
            <button type="submit">Send</button>
        </form>
    ');

    Route::put('/upload', function (Request $request): string {
        $document = $request->file('document');

        return sprintf(
            '<p>method=%s</p><p>title=%s</p><p>name=%s</p><p>contents=%s</p>',
            $request->method(),
            (string) $request->input('title'),
            $document instanceof UploadedFile ? $document->getClientOriginalName() : 'none',
            $document instanceof UploadedFile ? $document->getContent() : 'none',
        );
    });

    $path = tempnam(sys_get_temp_dir(), 'pest-browser-test');
    file_put_contents($path, 'the file contents');

    $page = visit('/upload');

    $page->attach('document', $path)
        ->click('Send')
        ->assertSee('method=PUT')
        ->assertSee('title=Quarterly report')
        ->assertSee('name='.basename($path))
        ->assertSee('contents=the file contents');

    unlink($path);
});

it('delivers several attached files to the application', function (): void {
    Route::get('/uploads', fn (): string => '
        <form method="POST" action="/uploads" enctype="multipart/form-data">
            <input type="file" id="documents" name="documents[]" multiple>
            <button type="submit">Send</button>
        </form>
    ');

    Route::post('/uploads', function (Request $request): string {
        $documents = $request->file('documents');

        return '<p>files='.implode(' ', array_map(
            fn (UploadedFile $document): string => $document->getClientOriginalName().':'.$document->getContent(),
            is_array($documents) ? $documents : [],
        )).'</p>';
    });

    $first = tempnam(sys_get_temp_dir(), 'pest-browser-test');
    $second = tempnam(sys_get_temp_dir(), 'pest-browser-test');
    file_put_contents($first, 'first contents');
    file_put_contents($second, 'second contents');

    $page = visit('/uploads');

    $page->attach('#documents', $first, $second)
        ->click('Send')
        ->assertSee('files='.basename($first).':first contents '.basename($second).':second contents');

    unlink($first);
    unlink($second);
});

it('returns a streamed body byte for byte, binary and invalid UTF-8 included', function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==', true);
    $bytes = "\n ".$png."\xff\xfe\x80\xc3 ";

    expect(mb_strlen($png, '8bit'))->toBe(70);

    Route::get('/streamed.bin', fn (): StreamedResponse => new StreamedResponse(
        function () use ($bytes): void {
            echo $bytes;
        },
        200,
        ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) mb_strlen($bytes, '8bit')],
    ));

    Route::get('/streamed.png', fn (): StreamedResponse => new StreamedResponse(
        function () use ($png): void {
            echo $png;
        },
        200,
        ['Content-Type' => 'image/png', 'Content-Length' => (string) mb_strlen($png, '8bit')],
    ));

    Route::get('/streamed', fn (): string => '<img id="picture" src="/streamed.png" alt="picture">');

    $page = visit('/streamed');

    $received = $page->script(<<<'JS'
        async () => {
            const response = await fetch('/streamed.bin');
            const buffer = await response.arrayBuffer();

            return {
                contentLength: response.headers.get('content-length'),
                byteLength: buffer.byteLength,
                hex: Array.from(new Uint8Array(buffer), (byte) => byte.toString(16).padStart(2, '0')).join(''),
            };
        }
        JS);

    expect($received['hex'])->toBe(bin2hex($bytes))
        ->and($received['byteLength'])->toBe(mb_strlen($bytes, '8bit'))
        ->and($received['contentLength'])->toBe((string) mb_strlen($bytes, '8bit'));

    $decoded = $page->script(<<<'JS'
        async () => {
            const image = document.getElementById('picture');

            if (! image.complete) {
                await new Promise((resolve) => {
                    image.addEventListener('load', resolve);
                    image.addEventListener('error', resolve);
                });
            }

            return image.naturalWidth;
        }
        JS);

    expect($decoded)->toBe(1);
});
