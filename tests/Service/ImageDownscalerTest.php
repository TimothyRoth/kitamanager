<?php

namespace App\Tests\Service;

use App\Service\ImageDownscaleException;
use App\Service\ImageDownscaler;
use App\Tests\ImageFixturesTrait;
use PHPUnit\Framework\TestCase;

final class ImageDownscalerTest extends TestCase
{
    use ImageFixturesTrait;

    private ImageDownscaler $downscaler;

    protected function setUp(): void
    {
        ini_set('memory_limit', '512M');
        $this->downscaler = new ImageDownscaler();
        $this->createTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testSmallJpegDoesNotNeedDownscale(): void
    {
        $path = $this->makeJpeg('small.jpg', 100, 80, 90, 0);
        self::assertFalse($this->downscaler->needsTvPreparation($path));
    }

    public function testLargeJpegIsDownscaledBelowLimit(): void
    {
        $path = $this->makeJpeg('large.jpg', 4500, 3500, 95, 150000);
        self::assertGreaterThan(ImageDownscaler::MAX_BYTES, filesize($path));
        self::assertTrue($this->downscaler->needsTvPreparation($path));

        $this->downscaler->prepareForTv($path);

        self::assertLessThanOrEqual(ImageDownscaler::MAX_BYTES, filesize($path));
        $info = getimagesize($path);
        self::assertNotFalse($info);
        self::assertSame('image/jpeg', $info['mime']);
    }

    public function testSmallPhotoRotatedOnlyByExifIsStoredUpright(): void
    {
        $path = $this->makeJpeg('sideways.jpg', 400, 300, 90, 0);
        $this->tagAsRotatedClockwise($path);
        self::assertLessThan(ImageDownscaler::MAX_BYTES, filesize($path));

        self::assertTrue($this->downscaler->needsTvPreparation($path));
        $this->downscaler->prepareForTv($path);

        [$width, $height] = getimagesize($path);
        self::assertSame([300, 400], [$width, $height]);
        self::assertFalse($this->downscaler->needsTvPreparation($path));
    }

    public function testCorruptFileThrows(): void
    {
        $path = $this->tmpDir . '/corrupt.jpg';
        file_put_contents($path, 'not-an-image');

        $this->expectException(ImageDownscaleException::class);
        $this->downscaler->prepareForTv($path);
    }

    public function testGifCannotBeDownscaled(): void
    {
        $path = $this->tmpDir . '/anim.gif';
        $img = imagecreatetruecolor(40, 30);
        imagegif($img, $path);

        $this->expectException(ImageDownscaleException::class);
        $this->expectExceptionMessage('GIF');
        $this->downscaler->prepareForTv($path);
    }

    public function testUnsupportedMimeThrows(): void
    {
        $path = $this->tmpDir . '/pixel.bmp';
        // Minimal BMP so getimagesize recognises image/bmp / image/x-ms-bmp.
        $img = imagecreatetruecolor(8, 8);
        imagebmp($img, $path);

        $this->expectException(ImageDownscaleException::class);
        $this->expectExceptionMessage('Bildformat');
        $this->downscaler->prepareForTv($path);
    }

    public function testMegapixelGuardRejectsBeforeDecode(): void
    {
        // Synthetic PNG IHDR with 10000×10000 (>64 MP) — never decoded by GD.
        $path = $this->tmpDir . '/huge.png';
        file_put_contents($path, $this->pngWithDimensions(10000, 10000));

        $this->expectException(ImageDownscaleException::class);
        $this->expectExceptionMessage('Megapixel');
        $this->downscaler->prepareForTv($path);
    }

    public function testOpaquePngIsReencodedAsJpeg(): void
    {
        $path = $this->makeOpaquePngAboveLimit('opaque.png');
        self::assertTrue($this->downscaler->needsTvPreparation($path));

        $this->downscaler->prepareForTv($path);

        self::assertLessThanOrEqual(ImageDownscaler::MAX_BYTES, filesize($path));
        $info = getimagesize($path);
        self::assertNotFalse($info);
        self::assertSame('image/jpeg', $info['mime']);
    }

    public function testTransparentPngStaysPng(): void
    {
        $path = $this->makeTransparentPngAboveLimit('alpha.png');
        self::assertTrue($this->downscaler->needsTvPreparation($path));

        $this->downscaler->prepareForTv($path);

        self::assertLessThanOrEqual(ImageDownscaler::MAX_BYTES, filesize($path));
        $info = getimagesize($path);
        self::assertNotFalse($info);
        self::assertSame('image/png', $info['mime']);
    }

    /**
     * Inserts an EXIF block with Orientation = 6 ("rotate 90° clockwise to display").
     */
    private function tagAsRotatedClockwise(string $jpegPath): void
    {
        $orientationEntry = "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00";
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . $orientationEntry . "\x00\x00\x00\x00";
        $exif = "Exif\x00\x00" . $tiff;
        $jpeg = file_get_contents($jpegPath);

        file_put_contents($jpegPath, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2));
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
