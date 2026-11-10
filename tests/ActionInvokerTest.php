<?php

namespace Luany\Core\Tests;

use Luany\Core\Http\Request;
use Luany\Core\Http\Response;
use Luany\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class InvokerController
{
    public function show(Request $request, string $id): string
    {
        return "show:{$id}";
    }
}

class ActionInvokerTest extends TestCase
{
    public function test_default_invocation_is_positional(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/items/{id}', [InvokerController::class, 'show']);

        $this->assertSame('show:5', $router->handle(new Request('GET', '/items/5'))->getBody());
    }

    public function test_invoker_receives_callable_request_and_named_params(): void
    {
        $router = new Router();
        $seen   = [];
        $router->setInvoker(function (callable $action, Request $request, array $params) use (&$seen) {
            $seen = $params;
            return Response::make('invoked:' . $request->uri());
        });
        $router->bind('user', fn (string $v) => strtoupper($v));
        $router->addRoute('GET', '/users/{user}/posts/{post}', fn () => 'never');

        $response = $router->handle(new Request('GET', '/users/ana/posts/9'));

        $this->assertSame('invoked:/users/ana/posts/9', $response->getBody());
        $this->assertSame(['user' => 'ANA', 'post' => '9'], $seen);
    }

    public function test_invoker_is_used_for_controller_actions(): void
    {
        $router = new Router();
        $router->setInvoker(fn (callable $action, Request $r, array $p) => $action($r, 'from-invoker'));
        $router->addRoute('GET', '/items/{id}', [InvokerController::class, 'show']);

        $this->assertSame('show:from-invoker', $router->handle(new Request('GET', '/items/5'))->getBody());
    }

    public function test_clearing_the_invoker_restores_default(): void
    {
        $router = new Router();
        $router->setInvoker(fn () => 'x');
        $router->setInvoker(null);
        $router->addRoute('GET', '/items/{id}', [InvokerController::class, 'show']);

        $this->assertSame('show:1', $router->handle(new Request('GET', '/items/1'))->getBody());
    }
}
