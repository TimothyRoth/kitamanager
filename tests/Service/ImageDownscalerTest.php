<?php

namespace App\Tests\Service;

use App\Service\ImageDownscaleException;
use App\Service\ImageDownscaler;
use PHPUnit\Framework\TestCase;

final class ImageDownscalerTest extends TestCase
{
    private ImageDownscaler $downscaler;
    private string $tmpDir;

    protected function setUp(): void
    {
        ini_set('memory_limit', '512M');
        $this->downscaler = new ImageDownscaler();
        $this->tmpDir = sys_get_temp_dir() . '/km_ds_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testSmallJpegDoesNotNeedDownscale(): void
    {
        $path = $this->makeJpeg('small.jpg', 100, 80, 90, 0);
        self::assertFalse($this->downscaler->needsDownscale($path));
    }

    public function testLargeJpegIsDownscaledBelowLimit(): void
    {
        $path = $this->makeJpeg('large.jpg', 4500, 3500, 95, 150000);
        self::assertGreaterThan(ImageDownscaler::MAX_BYTES, filesize($path));
        self::assertTrue($this->downscaler->needsDownscale($path));

        $this->downscaler->downscale($path);

        self::assertLessThanOrEqual(ImageDownscaler::MAX_BYTES, filesize($path));
        $info = getimagesize($path);
        self::assertNotFalse($info);
        self::assertSame('image/jpeg', $info['mime']);
    }

    public function testCorruptFileThrows(): void
    {
        $path = $this->tmpDir . '/corrupt.jpg';
        file_put_contents($path, 'not-an-image');

        $this->expectException(ImageDownscaleException::class);
        $this->downscaler->downscale($path);
    }

    private function makeJpeg(string $name, int $width, int $height, int $quality, int $noise): string
    {
        $path = $this->tmpDir . '/' . $name;
        $img = imagecreatetruecolor($width, $height);
        for ($i = 0; $i < $noise; $i++) {
            $c = imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1), $c);
        }
        imagejpeg($img, $path, $quality);

        return $path;
    }
}
