<?php

namespace App\Tests\EventListener;

use App\Entity\Content;
use App\Entity\User;
use App\Enum\ContentType;
use App\Service\ImageUploader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Ensures Doctrine preRemove hooks delete uploaded files from disk.
 */
final class ImageDeletionListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ImageUploader $uploader;
    private string $uploadsDir;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->uploader = $container->get(ImageUploader::class);
        $this->uploadsDir = $this->uploader->getTargetDirectory();

        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($meta);
        $tool->createSchema($meta);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setUsername('listener-user');
        $this->user->setRoles(['ROLE_USER']);
        $this->user->setPassword($hasher->hashPassword($this->user, 'secret'));
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userDir = $this->uploadsDir . '/' . $this->user->getId();
        if (is_dir($userDir)) {
            foreach (glob($userDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($userDir);
        }

        parent::tearDown();
    }

    public function testRemovingContentDeletesImageFile(): void
    {
        $userDir = $this->uploadsDir . '/' . $this->user->getId();
        mkdir($userDir, 0777, true);
        $absolute = $userDir . '/to-delete.jpg';
        file_put_contents($absolute, 'jpeg-bytes');
        $url = '/uploads/' . $this->user->getId() . '/to-delete.jpg';

        $content = new Content();
        $content->setType(ContentType::IMAGE);
        $content->setImageUrl($url);
        $content->setCreator($this->user);
        $this->em->persist($content);
        $this->em->flush();

        $this->em->remove($content);
        $this->em->flush();

        self::assertFileDoesNotExist($absolute);
    }

    public function testRemovingUserDeletesUploadDirectory(): void
    {
        $userDir = $this->uploadsDir . '/' . $this->user->getId();
        mkdir($userDir, 0777, true);
        file_put_contents($userDir . '/keep-me-not.jpg', 'x');

        $this->em->remove($this->user);
        $this->em->flush();

        self::assertDirectoryDoesNotExist($userDir);
    }
}
