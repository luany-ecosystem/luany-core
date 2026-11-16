<?php

namespace Luany\Core\Tests;

use Luany\Core\Exceptions\RouteNotFoundException;
use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class ScalarParamController
{
    public function show(Request $request, int $id): string
    {
        return 'user:' . var_export($id, true);
    }

    public function price(Request $request, float $amount): string
    {
        return 'price:' . var_export($amount, true);
    }
}

/**
 * A route parameter that cannot be converted to the action's declared
 * int/float type means "no such resource" — 404, not a TypeError (500).
 */
class ScalarRouteParamTest extends TestCase
{
    public function test_numeric_segment_reaches_int_parameter_as_int(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', [ScalarParamController::class, 'show']);

        $this->assertSame('user:42', $router->handle(new Request('GET', '/users/42'))->getBody());
    }

    public function test_non_numeric_segment_for_int_parameter_is_not_found(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', [ScalarParamController::class, 'show']);

        $this->expectException(RouteNotFoundException::class);
        $router->handle(new Request('GET', '/users/abc'));
    }

    /** @return array<string, array{0: string}> */
    public static function invalidInts(): array
    {
        return [
            'leading digits' => ['12abc'],
            'decimal'        => ['1.5'],
            'exponent'       => ['1e3'],
            'overflow'       => ['99999999999999999999999'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInts')]
    public function test_malformed_ints_are_not_found(string $segment): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', fn (Request $r, int $id) => "id:{$id}");

        $this->expectException(RouteNotFoundException::class);
        $router->handle(new Request('GET', '/users/' . $segment));
    }

    public function test_negative_int_is_accepted(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/n/{id}', fn (Request $r, int $id) => 'n:' . var_export($id, true));

        $this->assertSame('n:-3', $router->handle(new Request('GET', '/n/-3'))->getBody());
    }

    public function test_float_parameter(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/price/{amount}', [ScalarParamController::class, 'price']);

        $this->assertSame('price:9.5', $router->handle(new Request('GET', '/price/9.5'))->getBody());

        $this->expectException(RouteNotFoundException::class);
        $router->handle(new Request('GET', '/price/cheap'));
    }

    public function test_string_and_untyped_parameters_are_untouched(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/a/{slug}', fn (Request $r, string $slug) => "s:{$slug}");
        $router->addRoute('GET', '/b/{slug}', fn (Request $r, $slug) => 's:' . var_export($slug, true));
        $router->addRoute('GET', '/c/{id}', fn (Request $r, int|string $id) => 'c:' . var_export($id, true));

        $this->assertSame('s:hello', $router->handle(new Request('GET', '/a/hello'))->getBody());
        $this->assertSame("s:'7'", $router->handle(new Request('GET', '/b/7'))->getBody());
        $this->assertSame("c:'x'", $router->handle(new Request('GET', '/c/x'))->getBody());
    }

    public function test_parameter_matched_by_name_with_custom_invoker(): void
    {
        $router = new Router();
        $router->setInvoker(fn (callable $action, Request $r, array $p) => $action($p['id'], $r));
        $router->addRoute('GET', '/users/{id}', fn (int $id, Request $r) => 'id:' . var_export($id, true));

        $this->assertSame('id:7', $router->handle(new Request('GET', '/users/7'))->getBody());

        $this->expectException(RouteNotFoundException::class);
        $router->handle(new Request('GET', '/users/abc'));
    }

    public function test_bound_parameters_are_not_checked(): void
    {
        $router = new Router();
        $router->bind('id', fn (string $v) => strlen($v));
        $router->addRoute('GET', '/users/{id}', fn (Request $r, int $id) => "len:{$id}");

        $this->assertSame('len:3', $router->handle(new Request('GET', '/users/abc'))->getBody());
    }

    public function test_not_found_exception_names_the_uri(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/users/{id}', fn (Request $r, int $id) => Response::make('ok'));

        try {
            $router->handle(new Request('GET', '/users/abc'));
            $this->fail('Expected RouteNotFoundException');
        } catch (RouteNotFoundException $e) {
            $this->assertStringContainsString('/users/abc', $e->getMessage());
        }
    }
}
