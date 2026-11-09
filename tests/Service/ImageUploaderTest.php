<?php

namespace App\Tests\Service;

use App\Service\ImageUploader;
use App\Tests\ImageFixturesTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ImageUploaderTest extends TestCase
{
    use ImageFixturesTrait;

    private string $targetDir;
    private ImageUploader $uploader;

    protected function setUp(): void
    {
        $this->targetDir = sys_get_temp_dir() . '/km_uploads_' . bin2hex(random_bytes(4));
        mkdir($this->targetDir);
        $this->createTempDir();

        $this->uploader = new ImageUploader($this->targetDir, new AsciiSlugger(), new Filesystem());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->targetDir);
        $this->removeTempDir();
    }

    public function testUploadStoresUnderUserDirectoryWithSafeName(): void
    {
        $path = $this->makeJpeg('photo.jpg');
        $file = new UploadedFile($path, 'Mein Foto.jpg', 'image/jpeg', null, true);

        $url = $this->uploader->upload($file, 42);

        self::assertSame('/uploads/42/Mein-Foto.jpg', $url);
        self::assertFileExists($this->targetDir . '/42/Mein-Foto.jpg');
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

    public function testDeleteRemovesFileWhoseNameStartsWithUploads(): void
    {
        $full = $this->targetDir . '/9/uploads-sommerfest.jpg';
        mkdir(dirname($full), 0777, true);
        file_put_contents($full, 'x');

        $this->uploader->delete('/uploads/9/uploads-sommerfest.jpg');

        self::assertFileDoesNotExist($full);
    }

    public function testDeleteUserDirectoryRemovesTree(): void
    {
        $dir = $this->targetDir . '/15';
        mkdir($dir . '/nested', 0777, true);
        file_put_contents($dir . '/nested/a.jpg', 'x');

        $this->uploader->deleteUserDirectory(15);

        self::assertDirectoryDoesNotExist($dir);
    }
}
