<?php
namespace Minds\Core\Matrix;

use Minds\Core\Di\Ref;
use Minds\Core\Router\ModuleRoutes;
use Minds\Core\Router\Route;

/**
 * Matrix Routes
 * @package Minds\Core\Matrix
 */
class Routes extends ModuleRoutes
{
    /**
     * @inheritDoc
     */
    public function register(): void
    {
        $this->route
            ->withPrefix('.well-known/matrix')
            ->withMiddleware([
            ])
            ->do(function (Route $route) {
                $route->get(
                    'server',
                    Ref::_('Matrix\WellKnownController', 'getServer')
                );
                $route->get(
                    'client',
                    Ref::_('Matrix\WellKnownController', 'getClient')
                );
            });
    }
}