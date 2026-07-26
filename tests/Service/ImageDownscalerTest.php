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

    public function testGifCannotBeDownscaled(): void
    {
        $path = $this->tmpDir . '/anim.gif';
        $img = imagecreatetruecolor(40, 30);
        imagegif($img, $path);

        $this->expectException(ImageDownscaleException::class);
        $this->expectExceptionMessage('GIF');
        $this->downscaler->downscale($path);
    }

    public function testUnsupportedMimeThrows(): void
    {
        $path = $this->tmpDir . '/pixel.bmp';
        // Minimal BMP so getimagesize recognises image/bmp / image/x-ms-bmp.
        $img = imagecreatetruecolor(8, 8);
        imagebmp($img, $path);

        $this->expectException(ImageDownscaleException::class);
        $this->expectExceptionMessage('Bildformat');
        $this->downscaler->downscale($path);
    }

    public function testMegapixelGuardRejectsBeforeDecode(): void
    {
        // Synthetic PNG IHDR with 10000×10000 (>64 MP) — never decoded by GD.
        $path = $this->tmpDir . '/huge.png';
        file_put_contents($path, $this->pngWithDimensions(10000, 10000));

        $this->expectException(ImageDownscaleException::class);
        $this->expectExceptionMessage('Megapixel');
        $this->downscaler->downscale($path);
    }

    public function testOpaquePngIsReencodedAsJpeg(): void
    {
        $path = $this->makeOpaquePngAboveLimit('opaque.png');
        self::assertTrue($this->downscaler->needsDownscale($path));

        $this->downscaler->downscale($path);

        self::assertLessThanOrEqual(ImageDownscaler::MAX_BYTES, filesize($path));
        $info = getimagesize($path);
        self::assertNotFalse($info);
        self::assertSame('image/jpeg', $info['mime']);
    }

    public function testTransparentPngStaysPng(): void
    {
        $path = $this->makeTransparentPngAboveLimit('alpha.png');
        self::assertTrue($this->downscaler->needsDownscale($path));

        $this->downscaler->downscale($path);

        self::assertLessThanOrEqual(ImageDownscaler::MAX_BYTES, filesize($path));
        $info = getimagesize($path);
        self::assertNotFalse($info);
        self::assertSame('image/png', $info['mime']);
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

    private function makeOpaquePngAboveLimit(string $name): string
    {
        $path = $this->tmpDir . '/' . $name;
        $width = 2200;
        $height = 1800;
        $img = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += 3) {
            $c = imagecolorallocate($img, $y % 256, ($y * 3) % 256, ($y * 7) % 256);
            imageline($img, 0, $y, $width - 1, $y, $c);
        }
        imagepng($img, $path, 0);
        self::assertGreaterThan(ImageDownscaler::MAX_BYTES, filesize($path));

        return $path;
    }

    private function makeTransparentPngAboveLimit(string $name): string
    {
        $path = $this->tmpDir . '/' . $name;
        $width = 1800;
        $height = 1600;
        $img = imagecreatetruecolor($width, $height);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        for ($i = 0; $i < 80000; $i++) {
            $c = imagecolorallocatealpha(
                $img,
                random_int(0, 255),
                random_int(0, 255),
                random_int(0, 255),
                random_int(0, 60)
            );
            imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1), $c);
        }
        imagepng($img, $path, 0);
        self::assertGreaterThan(ImageDownscaler::MAX_BYTES, filesize($path));

        return $path;
    }

    /**
     * Minimal valid PNG signature + IHDR with the given dimensions (no IDAT).
     * Enough for getimagesize() / alpha detection; not decodable by GD.
     */
    private function pngWithDimensions(int $width, int $height): string
    {
        $ihdrData = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $ihdr = pack('N', 13) . 'IHDR' . $ihdrData . pack('N', crc32('IHDR' . $ihdrData));
        $iend = pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));

        return "\x89PNG\r\n\x1a\n" . $ihdr . $iend;
    }
}
