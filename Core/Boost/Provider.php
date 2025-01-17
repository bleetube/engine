<?php

namespace Minds\Core\Boost;

use Minds\Core\Boost\Network;
use Minds\Core\Data;
use Minds\Core\Data\Client;
use Minds\Core\Di;

/**
 * Boost Providers
 */
class Provider extends Di\Provider
{
    /**
     * Registers providers onto DI
     * @return void
     */
    public function register()
    {
        $this->di->bind('Boost\Repository', function ($di) {
            return new Repository();
        }, ['useFactory' => true]);

        $this->di->bind('Boost\Network', function ($di) {
            return new Network(Client::build('MongoDB'));
        }, ['useFactory' => true]);
        $this->di->bind('Boost\Network\Manager', function ($di) {
            return new Network\Manager;
        }, ['useFactory' => false]);
        $this->di->bind('Boost\Network\Iterator', function ($di) {
            return new Network\Iterator();
        }, ['useFactory' => false]);
        $this->di->bind('Boost\Network\Metrics', function ($di) {
            return new Network\Metrics();
        }, ['useFactory' => false]);
        $this->di->bind('Boost\Network\Review', function ($di) {
            return new Network\Review();
        }, ['useFactory' => false]);
        $this->di->bind('Boost\Network\Expire', function ($di) {
            return new Network\Expire();
        }, ['useFactory' => false]);
        $this->di->bind('Boost\Network\Rates', function ($di) {
            return new Network\Rates();
        }, ['useFactory' => true]);
        $this->di->bind('Boost\Newsfeed', function ($di) {
            return new Newsfeed(Client::build('MongoDB'));
        }, ['useFactory' => true]);
        $this->di->bind('Boost\Content', function ($di) {
            return new Content(Client::build('MongoDB'));
        }, ['useFactory' => true]);

        $this->di->bind('Boost\Peer\Metrics', function ($di) {
            return new Peer\Metrics(Client::build('MongoDB'));
        }, ['useFactory' => false]);
        $this->di->bind('Boost\Peer\Review', function ($di) {
            return new Peer\Review();
        }, ['useFactory' => false]);
        $this->di->bind('Boost\Payment', function ($di) {
            return new Payment();
        }, ['useFactory' => true]);

        //
        $this->di->bind('Boost\LiquiditySpot\Manager', function ($di) {
            return new LiquiditySpot\Manager();
        });

        $this->di->bind('Boost\LiquiditySpot\Controller', function ($di) {
            return new LiquiditySpot\Controller();
        });
    }
}
