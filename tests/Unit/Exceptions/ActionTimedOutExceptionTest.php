<?php

declare(strict_types=1);

use Pest\Browser\Exceptions\ActionTimedOutException;
use PHPUnit\Framework\ExpectationFailedException;

it('reads as a sentence on its own', function (): void {
    $timedOut = new ActionTimedOutException('Timeout 16000ms exceeded while waiting for [fill]');

    expect($timedOut->getMessage())->toBe('Timeout 16000ms exceeded while waiting for [fill].');
});

it('keeps the subject apart from the message so a caller can rebuild it', function (): void {
    $subject = 'Timeout 16000ms exceeded while waiting for [click] on selector [#save]';

    $timedOut = new ActionTimedOutException($subject);

    expect($timedOut->subject)->toBe($subject)
        ->and($timedOut->getMessage())->toBe($subject.'.');
});

it('survives as the previous of the failure the test actually sees', function (): void {
    $timedOut = new ActionTimedOutException('Timeout 5000ms exceeded while waiting for [fill]');

    $failure = new ExpectationFailedException($timedOut->getMessage(), null, $timedOut);

    expect($failure->getPrevious())->toBe($timedOut);
});
