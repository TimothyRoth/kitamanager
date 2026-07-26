<?php

namespace App\Tests\Service;

use App\Service\ImageUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ImageUploaderTest extends TestCase
{
    private string $targetDir;
    private string $tmpDir;
    private ImageUploader $uploader;

    protected function setUp(): void
    {
        $this->targetDir = sys_get_temp_dir() . '/km_uploads_' . bin2hex(random_bytes(4));
        $this->tmpDir = sys_get_temp_dir() . '/km_up_src_' . bin2hex(random_bytes(4));
        mkdir($this->targetDir);
        mkdir($this->tmpDir);

        $this->uploader = new ImageUploader($this->targetDir, new AsciiSlugger(), new Filesystem());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->targetDir, $this->tmpDir]);
    }

    public function testUploadStoresUnderUserDirectoryWithSafeName(): void
    {
        $path = $this->makeJpeg('photo.jpg');
        $file = new UploadedFile($path, 'Mein Foto.jpg', 'image/jpeg', null, true);

        $url = $this->uploader->upload($file, 42);

        self::assertSame('/uploads/42/Mein-Foto.jpg', $url);
        self::assertFileExists($this->targetDir . '/42/Mein-Foto.jpg');
    }

    public function testUploadUsesGlobalDirectoryWithoutUserId(): void
    {
        $path = $this->makeJpeg('anon.jpg');
        $file = new UploadedFile($path, 'anon.jpg', 'image/jpeg', null, true);

        $url = $this->uploader->upload($file, null);

        self::assertSame('/uploads/global/anon.jpg', $url);
        self::assertFileExists($this->targetDir . '/global/anon.jpg');
    }

    public function testUploadRenamesOnCollision(): void
    {
        mkdir($this->targetDir . '/7');
        file_put_contents($this->targetDir . '/7/shot.jpg', 'existing');

        $path = $this->makeJpeg('shot.jpg');
        $file = new UploadedFile($path, 'shot.jpg', 'image/jpeg', null, true);

        $url = $this->uploader->upload($file, 7);

        self::assertSame('/uploads/7/shot_1.jpg', $url);
        self::assertFileExists($this->targetDir . '/7/shot_1.jpg');
        self::assertSame('existing', file_get_contents($this->targetDir . '/7/shot.jpg'));
    }

    public function testDeleteRemovesFileAndIgnoresEmptyPath(): void
    {
        $full = $this->targetDir . '/9/gone.jpg';
        mkdir(dirname($full), 0777, true);
        file_put_contents($full, 'x');

        $this->uploader->delete('/uploads/9/gone.jpg');
        self::assertFileDoesNotExist($full);

        $this->uploader->delete(null);
        $this->uploader->delete('');
    }

    public function testDeleteUserDirectoryRemovesTree(): void
    {
        $dir = $this->targetDir . '/15';
        mkdir($dir . '/nested', 0777, true);
        file_put_contents($dir . '/nested/a.jpg', 'x');

        $this->uploader->deleteUserDirectory(15);

        self::assertDirectoryDoesNotExist($dir);
    }

    private function makeJpeg(string $name): string
    {
        $path = $this->tmpDir . '/' . $name;
        $img = imagecreatetruecolor(20, 10);
        imagejpeg($img, $path, 90);

        return $path;
    }
}
