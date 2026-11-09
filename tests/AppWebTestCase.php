<?php

namespace App\Tests;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Functional HTTP tests that touch Doctrine/uploads must extend this
 * so a misconfigured environment cannot wipe production data.
 */
abstract class AppWebTestCase extends WebTestCase
{
    use DatabaseFixturesTrait;
    use EnsuresIsolatedTestResourcesTrait;

    protected static function bootKernel(array $options = []): KernelInterface
    {
        $kernel = parent::bootKernel($options);
        static::assertIsolatedTestResources();

        return $kernel;
    }

    /**
     * Runs the action with a file where the user's upload folder should be,
     * so nothing can be stored there, and returns what was logged meanwhile.
     */
    protected function whileTheUploadFolderIsBlocked(User $owner, callable $action): string
    {
        $uploadFolder = static::getContainer()->getParameter('uploads_directory') . '/' . $owner->getId();
        $filesystem = new Filesystem();
        $filesystem->remove($uploadFolder);
        $filesystem->dumpFile($uploadFolder, 'a file where the upload folder should be');
        $logFile = static::getContainer()->getParameter('kernel.logs_dir') . '/test.log';
        $logSizeBefore = is_file($logFile) ? filesize($logFile) : 0;

        try {
            $action();
        } finally {
            $filesystem->remove($uploadFolder);
        }

        return (string) file_get_contents($logFile, false, null, $logSizeBefore);
    }
}
