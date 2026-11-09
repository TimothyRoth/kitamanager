<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\ImageDownscaler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use App\Tests\AppWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UploadImageTest extends AppWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $tmpDir;
    private User $user;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->resetSchema();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setUsername('upload-tester');
        $this->user->setRoles(['ROLE_USER']);
        $this->user->setPassword($hasher->hashPassword($this->user, 'secret'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->tmpDir = sys_get_temp_dir() . '/km_upload_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    private function resetSchema(): void
    {
        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
    }

    private function makeJpeg(string $name, int $width, int $height, int $quality = 90, int $noise = 0): string
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

    private function postImage(string $path, string $originalName, string $mime, array $extra = []): void
    {
        $this->postImageWithToken($this->uploadToken(), $path, $originalName, $mime, $extra);
    }

    private function uploadToken(): string
    {
        // Hitting a management page first starts the session so CSRF tokens work.
        $crawler = $this->client->request('GET', '/management/user/create-image');
        $token = $crawler->filter('form[data-upload-token]')->attr('data-upload-token');
        self::assertNotEmpty($token);

        return $token;
    }

    private function postImageWithToken(string $token, string $path, string $originalName, string $mime, array $extra = []): void
    {
        // Copy the fixture so UploadedFile can move it without consuming the original.
        $uploadPath = $this->tmpDir . '/upload_' . $originalName;
        copy($path, $uploadPath);

        $this->client->request(
            'POST',
            '/management/user/upload-image',
            array_merge(['_token' => $token, 'audienceAll' => '1'], $extra),
            ['image' => new UploadedFile($uploadPath, $originalName, $mime, null, true)]
        );
    }

    public function testSmallImageUploadsWithoutDownscale(): void
    {
        $path = $this->makeJpeg('small.jpg', 120, 80);
        $this->postImage($path, 'small.jpg', 'image/jpeg');

        $response = $this->client->getResponse();
        $body = json_decode($response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['ok']);
        self::assertFalse($body['downscaled']);
    }

    public function testDashboardConfirmsHowManyPhotosWereUploaded(): void
    {
        $token = $this->uploadToken();
        $this->postImageWithToken($token, $this->makeJpeg('eins.jpg', 120, 80), 'eins.jpg', 'image/jpeg');
        $this->postImageWithToken($token, $this->makeJpeg('zwei.jpg', 120, 80), 'zwei.jpg', 'image/jpeg');

        $this->client->request('GET', '/management/user');

        self::assertSelectorTextContains('.alert-success', '2 Bild(er) erfolgreich hochgeladen! Prüfen Sie die Reihenfolge in „Mein Slider“.');
    }

    public function testAdminSeesTheUploadConfirmationOnTheAdminDashboard(): void
    {
        $admin = new User();
        $admin->setUsername('admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword('not used: the test logs in directly');
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->postImage($this->makeJpeg('zentral.jpg', 120, 80), 'zentral.jpg', 'image/jpeg');
        $this->client->request('GET', '/management/user');
        self::assertResponseRedirects('/management/admin');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', '1 Bild(er) erfolgreich hochgeladen!');
        self::assertSelectorTextNotContains('.alert-success', 'Mein Slider');
    }

    public function testTheDashboardAddressAloneConfirmsNoUpload(): void
    {
        $this->client->request('GET', '/management/user?uploaded=5');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.alert-success');
    }

    public function testLargeImageIsDownscaled(): void
    {
        // HttpKernelBrowser maps files above PHP's upload_max_filesize to
        // UPLOAD_ERR_INI_SIZE before the controller runs. Skip the HTTP path
        // when the local CLI limit is too low; ImageDownscalerTest covers the
        // actual resize logic independently of PHP's upload limit.
        if (UploadedFile::getMaxFilesize() < 6_000_000) {
            self::markTestSkipped(sprintf(
                'PHP upload_max_filesize is %s; raise it to >= 6M to exercise HTTP downscaling.',
                ini_get('upload_max_filesize')
            ));
        }

        ini_set('memory_limit', '512M');
        $path = $this->makeJpeg('large.jpg', 4500, 3500, 95, 150000);
        self::assertGreaterThan(ImageDownscaler::MAX_BYTES, filesize($path));

        $this->postImage($path, 'large.jpg', 'image/jpeg');

        $response = $this->client->getResponse();
        $body = json_decode($response->getContent(), true);

        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertTrue($body['ok']);
        self::assertTrue($body['downscaled']);
    }

    public function testNonImageIsRejected(): void
    {
        $path = $this->tmpDir . '/fake.txt';
        file_put_contents($path, 'not-an-image');
        $this->postImage($path, 'fake.txt', 'text/plain');

        $response = $this->client->getResponse();
        $body = json_decode($response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['error']);
    }

    public function testInvalidCsrfIsRejected(): void
    {
        $this->client->request('GET', '/management/user/create-image');
        $path = $this->makeJpeg('csrf.jpg', 80, 60);
        $uploadPath = $this->tmpDir . '/upload_csrf.jpg';
        copy($path, $uploadPath);

        $this->client->request(
            'POST',
            '/management/user/upload-image',
            ['_token' => 'invalid'],
            ['image' => new UploadedFile($uploadPath, 'csrf.jpg', 'image/jpeg', null, true)]
        );

        $response = $this->client->getResponse();
        $body = json_decode($response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($body['ok']);
    }

    public function testStorageFailureIsReportedAndLogged(): void
    {
        $uploadFolder = static::getContainer()->getParameter('uploads_directory') . '/' . $this->user->getId();
        $filesystem = new Filesystem();
        $filesystem->remove($uploadFolder);
        $filesystem->dumpFile($uploadFolder, 'a file where the upload folder should be');
        $logFile = static::getContainer()->getParameter('kernel.logs_dir') . '/test.log';
        $logSizeBefore = is_file($logFile) ? filesize($logFile) : 0;

        try {
            $this->postImage($this->makeJpeg('unstorable.jpg', 80, 60), 'unstorable.jpg', 'image/jpeg');
        } finally {
            $filesystem->remove($uploadFolder);
        }

        self::assertSame(500, $this->client->getResponse()->getStatusCode());
        self::assertFalse(json_decode($this->client->getResponse()->getContent(), true)['ok']);
        self::assertStringContainsString(
            'Storing an uploaded image failed',
            (string) file_get_contents($logFile, false, null, $logSizeBefore)
        );
    }

    public function testOriginalAboveHardCapIsRejected(): void
    {
        $path = $this->tmpDir . '/huge.jpg';
        $fp = fopen($path, 'wb');
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img, null, 90);
        fwrite($fp, ob_get_clean());
        $chunk = str_repeat('X', 1024 * 1024);
        for ($i = 0; $i < 26; $i++) {
            fwrite($fp, $chunk);
        }
        fclose($fp);

        self::assertGreaterThan(ImageDownscaler::MAX_ORIGINAL_BYTES, filesize($path));

        $this->postImage($path, 'huge.jpg', 'image/jpeg');

        $response = $this->client->getResponse();
        $body = json_decode($response->getContent(), true);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($body['ok']);
        self::assertStringContainsString('25', $body['error']);
    }
}
