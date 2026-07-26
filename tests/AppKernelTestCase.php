<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Kernel tests that touch Doctrine/uploads must extend this (or AppWebTestCase)
 * so a misconfigured environment cannot wipe production data.
 */
abstract class AppKernelTestCase extends KernelTestCase
{
    use EnsuresIsolatedTestResourcesTrait;

    protected static function bootKernel(array $options = []): KernelInterface
    {
        $kernel = parent::bootKernel($options);
        static::assertIsolatedTestResources();

        return $kernel;
    }
}
