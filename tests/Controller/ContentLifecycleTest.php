<?php

namespace App\Tests\Controller;

use App\Entity\Content;
use App\Entity\SliderItem;
use App\Entity\User;
use App\Enum\ContentType;
use App\Service\ImageUploader;
use App\Tests\AppWebTestCase;
use App\Tests\ImageFixturesTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Content CRUD, validation, bulk delete, and TV cookie linking.
 */
final class ContentLifecycleTest extends AppWebTestCase
{
    use ImageFixturesTrait;

    private KernelBrowser $client;

    private User $kitaA;
    private User $kitaB;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->openFreshDatabase();
        $this->kitaA = $this->makeUser('kita-a', ['ROLE_USER'], '1111');
        $this->kitaB = $this->makeUser('kita-b', ['ROLE_USER'], '2222');
        $this->kitaA->addPublishTarget($this->kitaB);
        $this->em->flush();

        $this->createTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();

        parent::tearDown();
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
        self::assertResponseRedirects('/management/user#mein-slider');

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
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Titel');
        self::assertNull($this->em->getRepository(Content::class)->findOneBy(['title' => '']));
    }

    public function testPhotoFormRejectsAFileThatIsNoImage(): void
    {
        $notAnImage = $this->tmpDir . '/notes.txt';
        file_put_contents($notAnImage, 'not an image');

        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user/create-image');
        $form = $crawler->selectButton('Fotos hochladen')->form();
        $form['content[imageFile][0]']->upload($notAnImage);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'Bitte laden Sie ein gültiges Bild hoch');
        self::assertSame(0, $this->em->getRepository(Content::class)->count([]));
    }

    public function testPhotoFormStoresEveryPhotoWithItsSliderEntries(): void
    {
        $this->client->loginUser($this->kitaA);
        $this->submitPhotoForm([$this->makeJpeg('eins.jpg'), $this->makeJpeg('zwei.jpg')]);

        self::assertResponseRedirects('/management/user#mein-slider');
        $this->em->clear();
        $photos = $this->em->getRepository(Content::class)->findAll();
        self::assertCount(2, $photos);
        foreach ($photos as $photo) {
            self::assertSame(ContentType::IMAGE, $photo->getType());
            self::assertCount(2, $this->em->getRepository(SliderItem::class)->findBy(['content' => $photo]));
        }
    }

    public function testPhotoFormConfirmsHowManyPhotosWereStored(): void
    {
        $this->client->loginUser($this->kitaA);
        $this->submitPhotoForm([$this->makeJpeg('eins.jpg'), $this->makeJpeg('zwei.jpg')]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', '2 Bild(er) erfolgreich hochgeladen! Prüfen Sie die Reihenfolge in „Mein Slider“.');
    }

    public function testPhotoFormReportsAPhotoThatCannotBeStored(): void
    {
        $this->client->loginUser($this->kitaA);
        $logged = $this->whileTheUploadFolderIsBlocked($this->kitaA, fn () => $this->submitPhotoForm([$this->makeJpeg('unstorable.jpg')])
        );

        self::assertResponseRedirects('/management/user/create-image');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', self::IMAGE_NOT_STORED);
        self::assertStringContainsString('Storing an uploaded image failed', $logged);
        self::assertSame(0, $this->em->getRepository(Content::class)->count([]));
    }

    public function testPhotoFormKeepsThePhotosStoredBeforeAFailure(): void
    {
        $container = static::getContainer();
        $container->set(ImageUploader::class, new class($container->getParameter('uploads_directory'), $container->get(SluggerInterface::class), new Filesystem()) extends ImageUploader {
            private int $uploads = 0;

            public function upload(UploadedFile $file, int $userId): string
            {
                if (2 === ++$this->uploads) {
                    throw new \RuntimeException('The disk is full.');
                }

                return parent::upload($file, $userId);
            }
        });
        $this->client->disableReboot();

        $this->client->loginUser($this->kitaA);
        $this->submitPhotoForm([$this->makeJpeg('eins.jpg'), $this->makeJpeg('zwei.jpg'), $this->makeJpeg('drei.jpg')]);

        self::assertResponseRedirects('/management/user#mein-slider');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', self::IMAGE_NOT_STORED);
        $this->em->clear();
        $photos = $this->em->getRepository(Content::class)->findAll();
        self::assertCount(1, $photos);
        self::assertCount(2, $this->em->getRepository(SliderItem::class)->findBy(['content' => $photos[0]]));
    }

    public function testArticleFormReportsAnImageThatCannotBeStored(): void
    {
        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user/create-article');
        $form = $crawler->selectButton('Speichern')->form([
            'content[title]' => 'Hallo Kita',
            'content[content]' => 'Artikeltext',
        ]);
        $form['content[imageFile]']->upload($this->makeJpeg('unstorable.jpg'));

        $logged = $this->whileTheUploadFolderIsBlocked($this->kitaA, fn () => $this->client->submit($form));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', self::IMAGE_NOT_STORED);
        self::assertStringContainsString('Storing an uploaded image failed', $logged);
        self::assertSame(0, $this->em->getRepository(Content::class)->count([]));
    }

    public function testEditFormReportsAnImageThatCannotBeStored(): void
    {
        $article = $this->publishArticle($this->kitaA, 'Bleibt');

        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user/edit-content/' . $article->getId());
        $form = $crawler->selectButton('Änderungen speichern')->form(['content[title]' => 'Neu']);
        $form['content[imageFile]']->upload($this->makeJpeg('unstorable.jpg'));

        $logged = $this->whileTheUploadFolderIsBlocked($this->kitaA, fn () => $this->client->submit($form));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', self::IMAGE_NOT_STORED);
        self::assertStringContainsString('Storing an uploaded image failed', $logged);
        $this->em->clear();
        $unchanged = $this->em->getRepository(Content::class)->find($article->getId());
        self::assertSame('Bleibt', $unchanged->getTitle());
        self::assertSame('/uploads/test.jpg', $unchanged->getImageUrl());
    }

    private const IMAGE_NOT_STORED = 'Beim Speichern des Bildes gab es einen unerwarteten Fehler.';

    /**
     * @param list<string> $photoPaths
     */
    private function submitPhotoForm(array $photoPaths): void
    {
        $crawler = $this->client->request('GET', '/management/user/create-image');
        $form = $crawler->selectButton('Fotos hochladen')->form();
        $values = $form->getPhpValues();
        $files = ['content' => ['imageFile' => []]];
        foreach ($photoPaths as $photoPath) {
            $files['content']['imageFile'][] = new UploadedFile($photoPath, basename($photoPath), 'image/jpeg', null, true);
        }

        $this->client->request('POST', $form->getUri(), $values, $files);
    }

    public function testEditingAnArticleRequiresATitle(): void
    {
        $article = $this->publishArticle($this->kitaA, 'Bleibt');

        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user/edit-content/' . $article->getId());
        $form = $crawler->selectButton('Änderungen speichern')->form(['content[title]' => '']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame('Bleibt', $this->em->getRepository(Content::class)->find($article->getId())->getTitle());
    }

    private const UNSAFE_ARTICLE_TEXT = '<h2>Sommerfest</h2><p>Am <strong>Freitag</strong> ab <em>15 Uhr</em></p>'
        . '<ul><li>Kuchen</li></ul><span style="color:red">Rot markiert</span>'
        . '<script>window.pwned=1</script><img src="x" onerror="window.pwned=2">'
        . '<a href="javascript:window.pwned=3">Anmeldung</a>';

    public function testArticleTextKeepsEditorFormattingButNoActiveContent(): void
    {
        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user/create-article');
        $form = $crawler->selectButton('Speichern')->form([
            'content[title]' => 'Sommerfest',
            'content[content]' => self::UNSAFE_ARTICLE_TEXT,
        ]);
        $form['content[imageFile]']->upload($this->makeJpeg('sommerfest.jpg'));
        $this->client->submit($form);
        self::assertResponseRedirects('/management/user#mein-slider');

        $this->em->clear();
        $stored = $this->em->getRepository(Content::class)->findOneBy(['title' => 'Sommerfest'])->getContent();
        self::assertStringContainsString('<h2>Sommerfest</h2>', $stored);
        self::assertStringContainsString('<strong>Freitag</strong>', $stored);
        self::assertStringContainsString('<em>15 Uhr</em>', $stored);
        self::assertStringContainsString('<ul><li>Kuchen</li></ul>', $stored);
        self::assertStringContainsString('Rot markiert', $stored);
        self::assertStringContainsString('Anmeldung', $stored);
        $this->assertNoActiveContent($stored);
    }

    public function testUnsafeArticleTextAlreadyStoredIsCleanedOnTheSlider(): void
    {
        $this->publishArticle($this->kitaA, 'Altbestand')->setContent(self::UNSAFE_ARTICLE_TEXT);
        $this->em->flush();

        $this->client->request('GET', '/slider/kita-a');
        $sliderPage = $this->client->getResponse()->getContent();
        $this->client->request('GET', '/slider/kita-a/content');
        $polledSlides = json_decode($this->client->getResponse()->getContent(), true)['html'];

        foreach ([$sliderPage, $polledSlides] as $html) {
            self::assertStringContainsString('<strong>Freitag</strong>', $html);
            $this->assertNoActiveContent($html);
        }
    }

    public function testUnsafeArticleTextAlreadyStoredIsCleanedInTheEditor(): void
    {
        $article = $this->publishArticle($this->kitaA, 'Altbestand');
        $article->setContent(self::UNSAFE_ARTICLE_TEXT);
        $this->em->flush();

        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user/edit-content/' . $article->getId());
        $editorText = $crawler->filter('textarea[name="content[content]"]')->text(null, false);

        self::assertStringContainsString('<strong>Freitag</strong>', $editorText);
        $this->assertNoActiveContent($editorText);
    }

    private function assertNoActiveContent(string $html): void
    {
        self::assertStringNotContainsString('window.pwned', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('style=', $html);
        self::assertStringNotContainsString('<img src="x"', $html);
    }

    public function testEditingAnArticleReplacesItsTextAndImage(): void
    {
        $article = $this->publishArticle($this->kitaA, 'Alter Titel');
        $uploads = static::getContainer()->getParameter('uploads_directory');
        $oldImage = $uploads . '/' . $this->kitaA->getId() . '/alt.jpg';
        @mkdir(dirname($oldImage), 0777, true);
        copy($this->makeJpeg('alt.jpg'), $oldImage);
        $article->setImageUrl('/uploads/' . $this->kitaA->getId() . '/alt.jpg');
        $this->em->flush();

        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user/edit-content/' . $article->getId());
        $form = $crawler->selectButton('Änderungen speichern')->form([
            'content[title]' => 'Neuer Titel',
            'content[content]' => 'Neuer Text',
        ]);
        $form['content[imageFile]']->upload($this->makeJpeg('neu.jpg'));
        $this->client->submit($form);
        self::assertResponseRedirects('/management/user#mein-slider');

        $this->em->clear();
        $edited = $this->em->getRepository(Content::class)->find($article->getId());
        self::assertSame('Neuer Titel', $edited->getTitle());
        self::assertSame('Neuer Text', $edited->getContent());
        self::assertStringContainsString('/neu', $edited->getImageUrl());
        self::assertFileExists($uploads . str_replace('/uploads', '', $edited->getImageUrl()));
        self::assertFileDoesNotExist($oldImage);
    }

    public function testDeleteContentRemovesRowAndSliderItems(): void
    {
        $content = $this->publishArticle($this->kitaA, 'Zum Löschen', [$this->kitaB->getId()]);
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
        $own = $this->publishArticle($this->kitaA, 'Eigen');
        $foreign = $this->publishArticle($this->kitaB, 'Fremd');

        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user');
        $token = $crawler->filter('#bulkDeleteForm input[name="_token"]')->attr('value');
        self::assertNotEmpty($token);

        $this->client->request('POST', '/management/content/bulk-delete', [
            '_token' => $token,
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

    public function testEmptyDurationIsRejected(): void
    {
        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user');
        $form = $crawler->selectButton('Dauer speichern')->form([
            'user_duration[durationBetweenSlides]' => '',
        ]);
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Die Dauer muss zwischen 1 und 60 Sekunden liegen.', $crawler->filter('.form-errors')->text());

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
        $crawler = $this->client->request('GET', '/admin');
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
}
