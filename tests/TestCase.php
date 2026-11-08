<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // This runs before RefreshDatabase/DatabaseTransactions traits can mutate data.
        $app = parent::createApplication();
        $connection = $app['config']->get('database.connections.mysql');

        if (! $app->environment('testing')
            || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'mysql'
            || ($connection['database'] ?? null) !== 'ecommerce_api_testing'
            || ! empty($connection['url'])
            || ! empty($connection['read'])
            || ! empty($connection['write'])
            || ($connection['host'] ?? null) !== '127.0.0.1'
            || (string) ($connection['port'] ?? '') !== '3306') {
            throw new \RuntimeException('Tests require uncached, isolated local MySQL ecommerce_api_testing configuration.');
        }

        $actualDatabase = $app['db']->connection()->selectOne('SELECT DATABASE() AS name')->name;
        if ($actualDatabase !== 'ecommerce_api_testing') {
            throw new \RuntimeException('Refusing tests: actual database is not ecommerce_api_testing.');
        }

        return $app;
    }
}
