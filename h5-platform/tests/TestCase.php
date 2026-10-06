<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $db = $app['config']['database.connections.pgsql.database'];
        if (! is_string($db) || ! str_ends_with($db, '_test') || $db !== getenv('H5_TEST_DATABASE')) {
            throw new \RuntimeException('Tests require the isolated database selected by scripts/dev.py test.');
        }

        return $app;
    }
}
