<?php

namespace App\Tests;

/**
 * Aborts the test run if Doctrine or uploads still point at production paths.
 */
trait EnsuresIsolatedTestResourcesTrait
{
    protected static function assertIsolatedTestResources(): void
    {
        $container = static::getContainer();

        $params = $container->get('doctrine')->getConnection()->getParams();
        $path = (string) ($params['path'] ?? $params['dbname'] ?? $params['url'] ?? '');

        self::assertTrue(
            str_contains($path, 'data_test') || str_contains($path, ':memory:'),
            sprintf(
                'Refusing to run tests: Doctrine is not using the isolated test database (got "%s").',
                $path
            )
        );

        $driver = (string) ($params['driver'] ?? '');
        self::assertTrue(
            str_contains($driver, 'sqlite') || str_contains($path, 'sqlite'),
            sprintf(
                'Refusing to run tests: expected SQLite test driver, got driver="%s" path="%s".',
                $driver,
                $path
            )
        );

        $uploads = (string) $container->getParameter('uploads_directory');
        self::assertStringContainsString(
            'uploads_test',
            $uploads,
            sprintf(
                'Refusing to run tests: uploads_directory must be the isolated test dir (got "%s").',
                $uploads
            )
        );
        self::assertStringNotContainsString(
            DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads',
            $uploads,
            'Refusing to run tests: uploads_directory must not be public/uploads.'
        );
    }
}
