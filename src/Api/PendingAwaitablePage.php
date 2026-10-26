<?php

declare(strict_types=1);

namespace Pest\Browser\Api;

use Pest\Browser\Enums\BrowserType;
use Pest\Browser\Enums\ColorScheme;
use Pest\Browser\Enums\Device;
use Pest\Browser\Playwright\InitScript;
use Pest\Browser\Playwright\Playwright;
use Pest\Browser\Support\ComputeUrl;

/**
 * @mixin Webpage|AwaitableWebpage
 */
final class PendingAwaitablePage
{
    /**
     * The options key holding the scripts added with withInitScript(). It is
     * kept in the options so From and On carry it, and removed before the
     * options reach Playwright.
     */
    private const string INIT_SCRIPTS = '__pestInitScripts';

    /**
     * The webpage instance that will be returned when the page is visited.
     */
    private ?AwaitableWebpage $waitablePage = null;

    /**
     * Creates a new pending awaitable page instance.
     *
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly BrowserType $browserType,
        private readonly Device $device,
        private readonly string $url,
        private readonly array $options,
    ) {
        //
    }

    /**
     * Creates the actual visit page instance, and calls the given method on it.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        $this->waitablePage ??= $this->createAwaitablePage();

        // @phpstan-ignore-next-line
        return $this->waitablePage->{$name}(...$arguments);
    }

    /**
     * Sets the color scheme to dark mode.
     */
    public function inDarkMode(): self
    {
        return new self($this->browserType, $this->device, $this->url, [
            'colorScheme' => ColorScheme::DARK->value,
            ...$this->options,
        ]);
    }

    /**
     * Sets the color scheme to light mode.
     */
    public function inLightMode(): self
    {
        return new self($this->browserType, $this->device, $this->url, [
            'colorScheme' => ColorScheme::LIGHT->value,
            ...$this->options,
        ]);
    }

    /**
     * Allows you to set a different locale, timezone, and location for the page.
     */
    public function from(): From
    {
        return new From(
            $this->browserType,
            $this->device,
            $this->url,
            $this->options,
        );
    }

    /**
     * Allows you to set a different device for the page.
     */
    public function on(): On
    {
        return new On(
            $this->browserType,
            $this->device,
            $this->url,
            $this->options,
        );
    }

    /**
     * Sets the locale for the page.
     */
    public function withLocale(string $locale): self
    {
        return new self($this->browserType, $this->device, $this->url, [
            'locale' => $locale,
            ...$this->options,
        ]);
    }

    /**
     * Sets the userAgent for the page.
     */
    public function withUserAgent(string $userAgent): self
    {
        return new self($this->browserType, $this->device, $this->url, [
            'userAgent' => $userAgent,
            ...$this->options,
        ]);
    }

    /**
     * Sets the timezone for the page.
     */
    public function withTimezone(string $timezone): self
    {
        return new self($this->browserType, $this->device, $this->url, [
            'timezoneId' => $timezone,
            ...$this->options,
        ]);
    }

    /**
     * Adds a script the browser evaluates in every document before any of the
     * page's own scripts, after the plugin's own init script. Scripts run in
     * the order they were added.
     */
    public function withInitScript(string $source): self
    {
        $scripts = $this->options[self::INIT_SCRIPTS] ?? [];
        assert(is_array($scripts));

        return new self($this->browserType, $this->device, $this->url, [
            ...$this->options,
            self::INIT_SCRIPTS => [...$scripts, $source],
        ]);
    }

    /**
     * Sets the geolocation for the page.
     */
    public function geolocation(float $latitude, float $longitude): self
    {
        $geolocation = ['latitude' => $latitude, 'longitude' => $longitude];

        return new self($this->browserType, $this->device, $this->url, [
            'geolocation' => $geolocation,
            'permissions' => ['geolocation'],
            ...$this->options,
        ]);
    }

    /**
     * Creates the webpage instance.
     */
    private function createAwaitablePage(): AwaitableWebpage
    {
        $browser = Playwright::browser($this->browserType)->launch();

        $options = $this->options;
        $initScripts = $options[self::INIT_SCRIPTS] ?? [];
        unset($options[self::INIT_SCRIPTS]);

        $context = $browser->newContext([
            'locale' => 'en-US',
            'timezoneId' => 'UTC',
            'colorScheme' => Playwright::defaultColorScheme()->value,
            ...$this->device->context(),
            ...$options,
        ]);

        $context->addInitScript(InitScript::get());

        assert(is_array($initScripts));

        foreach ($initScripts as $source) {
            assert(is_string($source));

            $context->addInitScript($source);
        }

        $url = ComputeUrl::from($this->url);

        return new AwaitableWebpage(
            $context->newPage()->goto($url, $options),
            $url,
        );
    }
}
