<?php

namespace App\Tests;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Generated pictures in a temporary folder of their own.
 */
trait ImageFixturesTrait
{
    private string $tmpDir;

    private function createTempDir(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/km_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    private function removeTempDir(): void
    {
        (new Filesystem())->remove($this->tmpDir);
    }

    /**
     * @param int $noise number of random pixels; they keep the file from compressing well
     */
    private function makeJpeg(string $name, int $width = 120, int $height = 80, int $quality = 90, int $noise = 0): string
    {
        $path = $this->tmpDir . '/' . $name;
        $image = imagecreatetruecolor($width, $height);
        for ($i = 0; $i < $noise; $i++) {
            $colour = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $colour);
        }
        imagejpeg($image, $path, $quality);

        return $path;
    }
}
