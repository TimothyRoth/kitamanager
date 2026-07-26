<?php

namespace App\Tests\Controller;

use App\Entity\Content;
use App\Entity\SliderItem;
use App\Entity\User;
use App\Enum\ContentType;
use App\Service\AudienceSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Content CRUD, validation, bulk delete, and TV cookie linking.
 */
final class ContentLifecycleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AudienceSynchronizer $audience;
    private string $tmpDir;

    private User $kitaA;
    private User $kitaB;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->audience = $container->get(AudienceSynchronizer::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($meta);
        $tool->createSchema($meta);

        $this->kitaA = $this->makeUser($hasher, 'kita-a', 'kita-a-secret', '1111');
        $this->kitaB = $this->makeUser($hasher, 'kita-b', 'kita-b-secret', '2222');
        $this->kitaA->addPublishTarget($this->kitaB);
        $this->em->flush();

        $this->tmpDir = sys_get_temp_dir() . '/km_lifecycle_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    private function makeUser(UserPasswordHasherInterface $hasher, string $username, string $password, ?string $pin = null): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, $password));
        if (null !== $pin) {
            $user->setDevicePin($pin);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function makeJpeg(string $name): string
    {
        $path = $this->tmpDir . '/' . $name;
        $img = imagecreatetruecolor(120, 80);
        $bg = imagecolorallocate($img, 40, 120, 200);
        imagefill($img, 0, 0, $bg);
        imagejpeg($img, $path, 90);

        return $path;
    }

    private function seedArticle(User $creator, string $title, array $audienceIds = []): Content
    {
        $creator = $this->em->find(User::class, $creator->getId());
        self::assertNotNull($creator);

        $content = new Content();
        $content->setType(ContentType::ARTICLE);
        $content->setTitle($title);
        $content->setContent('Body of ' . $title);
        $content->setImageUrl('/uploads/test.jpg');
        $content->setCreator($creator);
        $this->em->persist($content);
        $this->em->flush();

        $this->audience->syncContentAudience($content, $audienceIds, false);
        $this->em->flush();

        return $content;
    }

    public function testCreateArticleViaFormCreatesSliderItems(): void
    {
        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user/create-article');

        $source = $this->makeJpeg('article.jpg');
        $uploadPath = $this->tmpDir . '/article_upload.jpg';
        copy($source, $uploadPath);

        $form = $crawler->selectButton('Speichern')->form([
            'content[title]' => 'Hallo Kita',
            'content[content]' => 'Artikeltext',
            'content[audienceAll]' => '1',
        ]);
        $form['content[imageFile]']->upload($uploadPath);

        $this->client->submit($form);
        self::assertResponseRedirects('/management/user');

        $this->em->clear();
        $created = $this->em->getRepository(Content::class)->findOneBy(['title' => 'Hallo Kita']);
        self::assertNotNull($created);
        self::assertSame(ContentType::ARTICLE, $created->getType());
        self::assertTrue($created->isAudienceAll());

        $itemA = $this->em->getRepository(SliderItem::class)->findOneBy([
            'content' => $created,
            'consumer' => $this->em->find(User::class, $this->kitaA->getId()),
        ]);
        $itemB = $this->em->getRepository(SliderItem::class)->findOneBy([
            'content' => $created,
            'consumer' => $this->em->find(User::class, $this->kitaB->getId()),
        ]);
        self::assertNotNull($itemA);
        self::assertNotNull($itemB);
        self::assertNotEmpty($created->getImageUrl());
        self::assertFileExists(
            static::getContainer()->getParameter('uploads_directory') . str_replace('/uploads', '', $created->getImageUrl())
        );
    }

    public function testCreateArticleRequiresTitleAndBody(): void
    {
        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user/create-article');

        $source = $this->makeJpeg('empty-fields.jpg');
        $uploadPath = $this->tmpDir . '/empty_upload.jpg';
        copy($source, $uploadPath);

        $form = $crawler->selectButton('Speichern')->form([
            'content[title]' => '',
            'content[content]' => '',
        ]);
        $form['content[imageFile]']->upload($uploadPath);

        $this->client->submit($form);
        // createArticle re-renders without an explicit 422; errors must still show.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Titel');
        self::assertNull($this->em->getRepository(Content::class)->findOneBy(['title' => '']));
    }

    public function testDeleteContentRemovesRowAndSliderItems(): void
    {
        $content = $this->seedArticle($this->kitaA, 'Zum Löschen', [$this->kitaB->getId()]);
        $contentId = $content->getId();
        $itemIds = array_map(
            static fn (SliderItem $i) => $i->getId(),
            $this->em->getRepository(SliderItem::class)->findBy(['content' => $content])
        );
        self::assertNotEmpty($itemIds);

        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user');
        $form = $crawler->filter(sprintf('form[action$="/management/user/delete-content/%d"]', $contentId))->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/management/user');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Content::class)->find($contentId));
        foreach ($itemIds as $id) {
            self::assertNull($this->em->getRepository(SliderItem::class)->find($id));
        }
    }

    public function testBulkDeleteSkipsUnauthorizedIds(): void
    {
        $own = $this->seedArticle($this->kitaA, 'Eigen');
        $foreign = $this->seedArticle($this->kitaB, 'Fremd');

        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user');
        $token = $crawler->filter('#bulkDeleteForm input[name="_token"]')->attr('value');
        self::assertNotEmpty($token);

        $this->client->request('POST', '/management/content/bulk-delete', [
            '_token' => $token,
            '_redirect' => 'app_management_user',
            'ids' => [$own->getId(), $foreign->getId()],
        ]);
        self::assertResponseRedirects('/management/user');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Content::class)->find($own->getId()));
        self::assertNotNull($this->em->getRepository(Content::class)->find($foreign->getId()));
    }

    public function testDurationOutOfRangeIsRejected(): void
    {
        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user');
        $form = $crawler->selectButton('Dauer speichern')->form([
            'user_duration[durationBetweenSlides]' => 99,
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($this->kitaA->getId());
        self::assertSame(10, $reloaded->getDurationBetweenSlides());
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user');
        $form = $crawler->selectButton('Passwort speichern')->form([
            'change_password[currentPassword]' => 'wrong-password',
            'change_password[newPassword]' => 'new-secret',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'aktuelle Passwort');
    }

    public function testChangePasswordSucceedsWithCorrectCurrent(): void
    {
        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user');
        $form = $crawler->selectButton('Passwort speichern')->form([
            'change_password[currentPassword]' => 'kita-a-secret',
            'change_password[newPassword]' => 'brand-new',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/user');

        $this->client->restart();
        $crawler = $this->client->request('GET', '/login');
        $login = $crawler->selectButton('Anmelden')->form([
            '_username' => 'kita-a',
            '_password' => 'brand-new',
        ]);
        $this->client->submit($login);
        self::assertResponseRedirects('/management/user');
    }

    public function testValidPinCookieAutoRedirectsOnDisplay(): void
    {
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('kitamanager_display_pin', '1111'));
        $this->client->request('GET', '/slider/display');

        self::assertResponseRedirects('/slider/kita-a');
    }

    public function testStalePinCookieShowsErrorOnDisplay(): void
    {
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('kitamanager_display_pin', '9999'));
        $this->client->request('GET', '/slider/display');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-danger');
        self::assertSelectorTextContains('body', 'PIN');
    }

    public function testMoveDownReordersSlides(): void
    {
        $c1 = $this->seedArticle($this->kitaA, 'Slide 1');
        $c2 = $this->seedArticle($this->kitaA, 'Slide 2');
        $item1 = $this->em->getRepository(SliderItem::class)->findOneBy(['content' => $c1, 'consumer' => $this->kitaA]);
        $item2 = $this->em->getRepository(SliderItem::class)->findOneBy(['content' => $c2, 'consumer' => $this->kitaA]);
        self::assertLessThan($item2->getDisplayOrder(), $item1->getDisplayOrder());

        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user');
        $form = $crawler->filter(sprintf('form[action$="/management/slider-item/%d/move-down"]', $item1->getId()))->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/management/user');

        $this->em->clear();
        $item1 = $this->em->getRepository(SliderItem::class)->find($item1->getId());
        $item2 = $this->em->getRepository(SliderItem::class)->find($item2->getId());
        self::assertGreaterThan($item2->getDisplayOrder(), $item1->getDisplayOrder());
    }
}
