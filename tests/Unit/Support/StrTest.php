<?php

declare(strict_types=1);

use Pest\Browser\Support\Str;

test('one character non-regex string', function (): void {
    $string = 'a';

    $result = Str::isRegex($string);

    expect($result)->toBeFalse();
});

it('detects regex expressions', function (): void {
    $regex = '/^.*$/';

    $result = Str::isRegex($regex);

    expect($result)->toBeTrue();
});

it('detects non-regex expressions', function (): void {
    $string = 'string';

    $result = Str::isRegex($string);

    expect($result)->toBeFalse();
});

it('collapses whitespace in a snippet', function (): void {
    $result = Str::snippet("  Pending\n\t  Requests  ", 40);

    expect($result)->toBe('Pending Requests');
});

it('truncates a snippet longer than the given length', function (): void {
    $result = Str::snippet('Pending Requests', 7);

    expect($result)->toBe('Pending...');
});

it('leaves a snippet of exactly the given length alone', function (): void {
    $result = Str::snippet('Pending', 7);

    expect($result)->toBe('Pending');
});

it('returns an empty snippet for a string of only whitespace', function (): void {
    $result = Str::snippet("  \n  ", 40);

    expect($result)->toBe('');
});
