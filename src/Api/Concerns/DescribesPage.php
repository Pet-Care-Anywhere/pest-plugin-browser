<?php

declare(strict_types=1);

namespace Pest\Browser\Api\Concerns;

use Pest\Browser\Api\Webpage;
use Pest\Browser\Support\Str;
use Throwable;

/**
 * @mixin Webpage
 */
trait DescribesPage
{
    /**
     * The maximum number of characters of page text included in a failure message.
     */
    private const int PAGE_TEXT_LENGTH = 400;

    /**
     * Describes where the browser is, for use in failure messages.
     *
     * The current url comes first because that is where the assertion actually
     * ran. The initial url is kept because it names the visit the test asked
     * for, and the two differ the moment anything redirects.
     */
    private function pageUrls(): string
    {
        try {
            $url = $this->page->url();
        } catch (Throwable) {
            $url = '<unavailable>';
        }

        return "the current url [{$url}] (initially visited [{$this->initialUrl}])";
    }

    /**
     * Describes the page's visible text, for use in failure messages.
     */
    private function pageText(): string
    {
        return " The page's visible text was: [{$this->visibleText()}].";
    }

    /**
     * Snapshots the page's visible text, truncated to stay readable.
     */
    private function visibleText(): string
    {
        try {
            $text = $this->page->evaluate('() => document.body ? document.body.innerText : ""');
        } catch (Throwable) {
            return '<unavailable>';
        }

        if (! is_string($text)) {
            return '<unavailable>';
        }

        $text = Str::snippet($text, self::PAGE_TEXT_LENGTH);

        return $text === '' ? '<empty>' : $text;
    }
}
