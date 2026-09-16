<?php

declare(strict_types=1);

namespace Pest\Browser\Api;

use Pest\Browser\Api\Concerns\DescribesPage;
use Pest\Browser\Exceptions\ActionTimedOutException;
use Pest\Browser\Exceptions\BrowserExpectationFailedException;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Playwright\Playwright;
use Pest\Browser\ServerManager;
use PHPUnit\Framework\ExpectationFailedException;
use Throwable;

/**
 * @mixin Webpage
 */
final readonly class AwaitableWebpage
{
    use DescribesPage;

    /**
     * The timeout, in milliseconds, allowed for snapshotting a failed page.
     *
     * The snapshot is a protocol call like any other, so a page that has really
     * stopped answering would otherwise spend a second full timeout here on top
     * of the one that already failed. The description is worth a moment and no
     * more; if it cannot be had in that moment the reader gets a marker.
     */
    private const int SNAPSHOT_TIMEOUT_MILLISECONDS = 2_000;

    /**
     * Creates a new awaitable webpage instance.
     *
     * @param  array<int, string>  $nonAwaitableMethods
     */
    public function __construct(
        private Page $page,
        private string $initialUrl,
        private array $nonAwaitableMethods = [
            'assertScreenshotMatches',
            'assertNoAccessibilityIssues',
        ],
    ) {
        //
    }

    /**
     * Awaits for the given method to assert true or fail.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        $webpage = new Webpage($this->page, $this->initialUrl);

        try {
            if (
                in_array($name, $this->nonAwaitableMethods, true)
                || Playwright::timeout() <= 1000
            ) {
                // @phpstan-ignore-next-line
                $result = $webpage->{$name}(...$arguments);
            } else {
                $result = Execution::instance()->waitForExpectation(
                    // @phpstan-ignore-next-line
                    fn () => $webpage->{$name}(...$arguments),
                );
            }
        } catch (ExpectationFailedException $e) {
            ServerManager::instance()->http()->throwLastThrowableIfNeeded();

            $timedOut = $e->getPrevious();

            if ($timedOut instanceof ActionTimedOutException) {
                $e = $this->describingPage($timedOut, $e);
            }

            try {
                $browserException = BrowserExpectationFailedException::from($this->page, $e);
            } catch (Throwable) { // @phpstan-ignore-line
                throw $e;
            }

            throw $browserException;
        }

        ServerManager::instance()->http()->throwLastThrowableIfNeeded();

        return $result === $webpage
            ? $this
            : $result;
    }

    /**
     * Return the page instance.
     */
    public function page(): Page
    {
        return $this->page;
    }

    /**
     * Returns the timeout with the page it timed out on described in it.
     *
     * An action that times out says only that Playwright never answered. On its
     * own that is the least diagnosable failure the suite produces: a press on
     * a label that no longer exists, a label behind a flag that is off, and a
     * label matching two elements all read identically. The page's own text
     * separates them, and it is readable here because a timed-out action leaves
     * the browser alive and answering.
     *
     * Only ever reached on the way to failing, so the cost is paid once, by a
     * test that has already lost. If the description cannot be taken the
     * original exception is returned untouched, because a failure to explain a
     * failure must never replace it.
     */
    private function describingPage(ActionTimedOutException $timedOut, ExpectationFailedException $e): ExpectationFailedException
    {
        try {
            $description = Playwright::usingTimeout(
                self::SNAPSHOT_TIMEOUT_MILLISECONDS,
                fn (): string => 'on the page with '.$this->pageUrls().'.'.$this->pageText(),
            );
        } catch (Throwable) {
            return $e;
        }

        return new ExpectationFailedException($timedOut->subject.' '.$description, null, $timedOut);
    }
}
