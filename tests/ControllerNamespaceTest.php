<?php

namespace App\Controllers {
    final class LuanyNsSkeletonController
    {
        public function index(): string
        {
            return 'app-controllers';
        }
    }

    final class LuanyNsBothController
    {
        public function index(): string
        {
            return 'app-controllers';
        }
    }
}

namespace App\Http\Controllers {
    final class LuanyNsLegacyController
    {
        public function index(): string
        {
            return 'app-http-controllers';
        }
    }

    final class LuanyNsBothController
    {
        public function index(): string
        {
            return 'app-http-controllers';
        }
    }
}

namespace Luany\Core\Tests {

    use Luany\Core\Http\Request;
    use Luany\Core\Routing\Router;
    use PHPUnit\Framework\TestCase;

    /**
     * Short controller names resolve against the skeleton's App\Controllers
     * namespace first, then the legacy App\Http\Controllers.
     */
    class ControllerNamespaceTest extends TestCase
    {
        public function test_short_name_resolves_in_app_controllers(): void
        {
            $router = new Router();
            $router->addRoute('GET', '/', ['LuanyNsSkeletonController', 'index']);

            $this->assertSame('app-controllers', $router->handle(new Request('GET', '/'))->getBody());
        }

        public function test_short_name_still_resolves_in_app_http_controllers(): void
        {
            $router = new Router();
            $router->addRoute('GET', '/', ['LuanyNsLegacyController', 'index']);

            $this->assertSame('app-http-controllers', $router->handle(new Request('GET', '/'))->getBody());
        }

        public function test_app_controllers_wins_when_both_exist(): void
        {
            $router = new Router();
            $router->addRoute('GET', '/', ['LuanyNsBothController', 'index']);

            $this->assertSame('app-controllers', $router->handle(new Request('GET', '/'))->getBody());
        }

        public function test_unknown_controller_still_throws(): void
        {
            $router = new Router();
            $router->addRoute('GET', '/', ['LuanyNsMissingController', 'index']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Controller not found: LuanyNsMissingController');
            $router->handle(new Request('GET', '/'));
        }
    }
}
