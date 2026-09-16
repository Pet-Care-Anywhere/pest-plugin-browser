<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

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
