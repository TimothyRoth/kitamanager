<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Functional HTTP tests that touch Doctrine/uploads must extend this
 * so a misconfigured environment cannot wipe production data.
 */
abstract class AppWebTestCase extends WebTestCase
{
    use EnsuresIsolatedTestResourcesTrait;

    protected static function bootKernel(array $options = []): KernelInterface
    {
        $kernel = parent::bootKernel($options);
        static::assertIsolatedTestResources();

        return $kernel;
    }
}
