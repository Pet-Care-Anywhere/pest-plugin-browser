<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;

it('may see text on a page', function (): void {
    Route::get('/', fn (): string => 'Hello World');

    $page = visit('/');

    $page->assertSee('Hello World');
});

it('may not see text on a page', function (): void {
    Route::get('/', fn (): string => 'Hello World');

    $page = visit('/');

    $page->assertSee('Hello Universe');
})->throws(ExpectationFailedException::class);

it('reports the current url and the visible page text when the text is not found', function (): void {
    Route::get('/', fn (): string => '<a href="/dashboard">Go to dashboard</a>');
    Route::get('/dashboard', fn (): string => '<h1>Dashboard</h1><p>Welcome back.</p>');

    $page = visit('/');

    $page->click('Go to dashboard');

    expect(fn () => $page->assertSee('Refund recorded'))->toThrow(
        function (ExpectationFailedException $e): void {
            expect($e->getMessage())
                ->toContain('the current url [http://127.0.0.1')
                ->toContain('/dashboard]')
                ->toContain("The page's visible text was: [Dashboard Welcome back.]");
        },
    );
});
