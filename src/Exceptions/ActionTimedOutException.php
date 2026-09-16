<?php

declare(strict_types=1);

namespace Pest\Browser\Exceptions;

use RuntimeException;

/**
 * Marks a failure as the protocol client giving up on a reply.
 *
 * This is never thrown on its own. It rides as the `previous` of the
 * ExpectationFailedException the client actually raises, because PHPUnit's
 * ExpectationFailedException is final and every layer between here and the test
 * already handles that type: making the timeout a subclass is not available,
 * and making it a sibling would quietly stop the retry loop from treating it as
 * a failed attempt.
 *
 * It carries the subject apart from the message so the layer holding the page
 * can rebuild the sentence with the page described in it. The protocol client
 * knows the method and the selector and nothing else; where the browser had
 * actually got to is only knowable further up, and that is the half a reader
 * needs.
 *
 * @internal
 */
final class ActionTimedOutException extends RuntimeException
{
    /**
     * Creates a new action timed out exception instance.
     */
    public function __construct(public readonly string $subject)
    {
        parent::__construct($subject.'.');
    }
}
