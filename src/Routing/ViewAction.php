<?php

namespace Luany\Core\Routing;

/**
 * ViewAction
 *
 * Invokable action behind Route::view(). Being a plain object with
 * __set_state() it survives route caching, unlike a closure.
 *
 * View data is exactly what the developer registered — request input is
 * never merged into it (query strings must not override view variables).
 */
final class ViewAction
{
    /** @var (\Closure(string, array<string, mixed>): mixed)|null */
    private static ?\Closure $renderer = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $view,
        public readonly array $data = [],
    ) {}

    /**
     * @param \Closure(string, array<string, mixed>): mixed $renderer
     */
    public static function setRenderer(?\Closure $renderer): void
    {
        self::$renderer = $renderer;
    }

    public function __invoke(mixed $request = null): mixed
    {
        if (self::$renderer !== null) {
            return (self::$renderer)($this->view, $this->data);
        }

        if (function_exists('view')) {
            return view($this->view, $this->data);
        }

        throw new \RuntimeException(
            'No view renderer configured. Call Route::setViewRenderer() at bootstrap.'
        );
    }

    /**
     * Restore from a route cache file (var_export).
     *
     * @param array{view: string, data?: array<string, mixed>} $state
     */
    public static function __set_state(array $state): self
    {
        return new self($state['view'], $state['data'] ?? []);
    }
}
