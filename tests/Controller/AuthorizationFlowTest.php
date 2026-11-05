<?php

namespace App\Tests\Controller;

use App\Entity\Content;
use App\Entity\SliderItem;
use App\Entity\User;
use App\Enum\ContentType;
use App\Service\AudienceSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use App\Tests\AppWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Function tests mapped to docs/autorisierung-und-benutzerverwaltung.md §5.
 */
final class AuthorizationFlowTest extends AppWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;
    private AudienceSynchronizer $audience;

    private User $admin;
    private User $kitaA;
    private User $kitaB;
    private User $kitaC;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->em = $container->get('doctrine')->getManager();
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->audience = $container->get(AudienceSynchronizer::class);

        $this->resetSchema();
        $this->seedUsers();
    }

    private function resetSchema(): void
    {
        $meta = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
    }

    private function seedUsers(): void
    {
        $this->admin = $this->makeUser('admin', 'admin-secret', ['ROLE_ADMIN']);
        $this->kitaA = $this->makeUser('kita-a', 'kita-a-secret', ['ROLE_USER'], '1111');
        $this->kitaB = $this->makeUser('kita-b', 'kita-b-secret', ['ROLE_USER'], '2222');
        $this->kitaC = $this->makeUser('kita-c', 'kita-c-secret', ['ROLE_USER']);

        // Admin allows kita-a to publish to kita-b
        $this->kitaA->addPublishTarget($this->kitaB);
        $this->em->flush();
    }

    private function makeUser(string $username, string $plainPassword, array $roles, ?string $pin = null): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setRoles($roles);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        if (null !== $pin) {
            $user->setDevicePin($pin);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createArticle(User $creator, string $title, array $audienceIds = [], bool $audienceAll = false): Content
    {
        // Kernel requests detach entities; always re-load the creator.
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

        $this->audience->syncContentAudience($content, $audienceIds, $audienceAll);
        $this->em->flush();

        return $content;
    }

    private function refreshEm(): void
    {
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->audience = static::getContainer()->get(AudienceSynchronizer::class);
    }

    private function sliderItemFor(Content $content, User $consumer): ?SliderItem
    {
        return $this->em->getRepository(SliderItem::class)->findOneBy([
            'content' => $content,
            'consumer' => $consumer,
        ]);
    }

    private function submitNewKitaForm(string $username): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/management/admin');
        $this->client->submit($crawler->selectButton('Benutzer hinzufügen')->form([
            'user[username]' => $username,
            'user[plainPassword]' => 'neu-secret',
        ]));
    }

    private function deleteKitaAsAdmin(User $kita): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/management/admin');
        $form = $crawler->filter(sprintf('form[action$="/management/admin/delete-user/%d"]', $kita->getId()))->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/management/admin');
    }

    private function publishAssignmentCount(int $userId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_publish_target WHERE source_user_id = :id OR target_user_id = :id',
            ['id' => $userId]
        );
    }

    private function loginForm(string $username, string $password): void
    {
        $crawler = $this->client->request('GET', '/admin');
        $form = $crawler->selectButton('Anmelden')->form([
            '_username' => $username,
            '_password' => $password,
        ]);
        $this->client->submit($form);
    }

    // --- Login & Rollen ----------------------------------------------------

    public function testWrongCredentialsShowError(): void
    {
        $this->loginForm('admin', 'wrong-password');

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
        self::assertSelectorTextContains('body', 'Anmelden');
    }

    public function testAdminLandsOnAdminDashboard(): void
    {
        $this->loginForm('admin', 'admin-secret');

        self::assertResponseRedirects('/management/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'kita-a');
    }

    public function testKitaLandsOnUserDashboard(): void
    {
        $this->loginForm('kita-a', 'kita-a-secret');

        self::assertResponseRedirects('/management/user');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testKitaCannotAccessAdminDashboard(): void
    {
        $this->client->loginUser($this->kitaA);
        $this->client->request('GET', '/management/admin');

        // Doc says "Umleitung", but access_control requires ROLE_ADMIN and
        // yields 403 before ManagementRedirectSubscriber can redirect.
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminIsRedirectedAwayFromUserDashboard(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/management/user');

        self::assertResponseRedirects('/management/admin');
    }

    public function testLoginPageSendsLoggedInUsersToTheirDashboard(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/management/admin');

        $this->client->loginUser($this->kitaA);
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/management/user');
    }

    public function testLogoutEndsSession(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/logout');
        $this->client->followRedirect();

        $this->client->request('GET', '/management/admin');
        self::assertResponseRedirects();
        self::assertStringContainsString('/admin', $this->client->getResponse()->headers->get('Location') ?? '');
    }

    public function testStayLoggedInSurvivesTheEndOfTheSession(): void
    {
        $crawler = $this->client->request('GET', '/admin');
        $form = $crawler->selectButton('Anmelden')->form(['_username' => 'kita-a', '_password' => 'kita-a-secret']);
        $form['_remember_me']->tick();
        $this->client->submit($form);
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));

        $this->client->getCookieJar()->expire('MOCKSESSID');
        $this->client->request('GET', '/management/user');

        self::assertResponseIsSuccessful();
    }

    public function testWithoutStayLoggedInTheLoginEndsWithTheSession(): void
    {
        $this->loginForm('kita-a', 'kita-a-secret');
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'));

        $this->client->getCookieJar()->expire('MOCKSESSID');
        $this->client->request('GET', '/management/user');

        self::assertResponseRedirects();
    }

    public function testPasswordFieldsOfferAShowPasswordButton(): void
    {
        $crawler = $this->client->request('GET', '/admin');
        self::assertCount(1, $crawler->filter('.password-field input[type="password"] + [data-password-toggle]'));

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/management/admin');
        self::assertCount(3, $crawler->filter('.password-field input[type="password"] + [data-password-toggle]'));
    }

    // --- Benutzerverwaltung (Admin) ----------------------------------------

    public function testAdminCanCreateKita(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/management/admin');
        $form = $crawler->selectButton('Benutzer hinzufügen')->form([
            'user[username]' => 'kita-neu',
            'user[plainPassword]' => 'neu-secret',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/management/admin');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'kita-neu');

        $created = $this->em->getRepository(User::class)->findOneBy(['username' => 'kita-neu']);
        self::assertNotNull($created);
        self::assertSame(['ROLE_USER'], $created->getRoles());
    }

    public function testAdminCanChangeKitaPassword(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/management/admin/edit-user/' . $this->kitaC->getId());
        $form = $crawler->filter('form')->reduce(function ($node) {
            return $node->filter('input[name="user[plainPassword]"]')->count() > 0;
        })->first()->form([
            'user[username]' => 'kita-c',
            'user[plainPassword]' => 'changed-secret',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/admin');

        $this->client->restart();
        $this->loginForm('kita-c', 'changed-secret');
        self::assertResponseRedirects('/management/user');
    }

    public function testAdminCanAssignUniqueDevicePin(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/management/admin/edit-user/' . $this->kitaC->getId());

        $pinForm = $crawler->filter('form')->reduce(function ($node) {
            return $node->filter('input[name="user_device_pin[devicePin]"]')->count() > 0;
        })->first()->form([
            'user_device_pin[devicePin]' => '3333',
        ]);
        $this->client->submit($pinForm);
        self::assertResponseRedirects('/management/admin/edit-user/' . $this->kitaC->getId());

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($this->kitaC->getId());
        self::assertSame('3333', $reloaded->getDevicePin());

        // Duplicate PIN must be rejected
        $crawler = $this->client->request('GET', '/management/admin/edit-user/' . $this->kitaB->getId());
        $pinForm = $crawler->filter('form')->reduce(function ($node) {
            return $node->filter('input[name="user_device_pin[devicePin]"]')->count() > 0;
        })->first()->form([
            'user_device_pin[devicePin]' => '3333',
        ]);
        $this->client->submit($pinForm);
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
    }

    public function testAdminCanAssignPublishTargetsAndPublishToAll(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/management/admin/edit-user/' . $this->kitaC->getId());
        $form = $crawler->filter('form')->reduce(function ($node) {
            return $node->filter('input[name="user[plainPassword]"]')->count() > 0;
        })->first()->form([
            'user[username]' => 'kita-c',
            'user[publishToAll]' => '1',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/admin');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($this->kitaC->getId());
        self::assertTrue($reloaded->isPublishToAll());
    }

    public function testAdminCanDeleteKitaAndContentDisappears(): void
    {
        $content = $this->createArticle($this->kitaC, 'Wird gelöscht', [], false);
        $itemId = $this->sliderItemFor($content, $this->kitaC)?->getId();
        self::assertNotNull($itemId);

        $this->deleteKitaAsAdmin($this->kitaC);

        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->find($this->kitaC->getId()));
        self::assertNull($this->em->getRepository(Content::class)->find($content->getId()));
        self::assertNull($this->em->getRepository(SliderItem::class)->find($itemId));
    }

    public function testDeletingKitaRemovesItsPublishAssignments(): void
    {
        $kitaBId = $this->kitaB->getId();
        $this->kitaB->addPublishTarget($this->kitaC);
        $this->em->flush();
        self::assertSame(2, $this->publishAssignmentCount($kitaBId), 'kita-b is a target of kita-a and publishes to kita-c');

        $this->deleteKitaAsAdmin($this->kitaB);

        self::assertSame(0, $this->publishAssignmentCount($kitaBId));
    }

    public function testUsernameIsRequiredAndMustNotContainASlash(): void
    {
        $this->submitNewKitaForm('Kita/Nord');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Schrägstrich');

        $this->submitNewKitaForm('   ');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Bitte geben Sie einen Benutzernamen ein.');

        self::assertCount(4, $this->em->getRepository(User::class)->findAll());
    }

    public function testUsernameWithTheSameSliderAddressIsRejected(): void
    {
        $this->submitNewKitaForm('Kita A');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'sehr ähnlichen Namen');
    }

    public function testAdminCanChangeOwnPassword(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/management/admin');
        $this->client->submit($crawler->selectButton('Passwort speichern')->form([
            'change_password[currentPassword]' => 'admin-secret',
            'change_password[newPassword]' => 'new-admin-secret',
        ]));
        self::assertResponseRedirects('/management/admin');

        $this->client->restart();
        $this->loginForm('admin', 'new-admin-secret');
        self::assertResponseRedirects('/management/admin');
    }

    public function testAdminPasswordChangeNeedsTheCurrentPassword(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/management/admin');
        $this->client->submit($crawler->selectButton('Passwort speichern')->form([
            'change_password[currentPassword]' => 'wrong-password',
            'change_password[newPassword]' => 'new-admin-secret',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Das aktuelle Passwort ist nicht korrekt.');
    }

    public function testAdminAccountsCannotBeDeleted(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/management/admin/delete-user/' . $this->admin->getId(), ['_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(User::class)->find($this->admin->getId()));
    }

    // --- Inhalte & Zuweisung -----------------------------------------------

    public function testKitaAudienceChoicesLimitedToAssignedUsers(): void
    {
        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user/create-article');

        $labels = $crawler->filter('[data-audience-list] label')->each(static fn ($n) => trim($n->text()));
        self::assertContains('kita-b', $labels);
        self::assertNotContains('kita-c', $labels);
        self::assertNotContains('admin', $labels);
    }

    public function testAdminContentAppearsOnAllowedKitaSliders(): void
    {
        $this->admin->setPublishToAll(true);
        $this->em->flush();

        $content = $this->createArticle($this->admin, 'Zentral', [], true);

        self::assertNotNull($this->sliderItemFor($content, $this->kitaA));
        self::assertNotNull($this->sliderItemFor($content, $this->kitaB));
        self::assertNotNull($this->sliderItemFor($content, $this->kitaC));
        // Admin itself is not a consumer
        self::assertNull($this->sliderItemFor($content, $this->admin));
    }

    public function testRemovingPublishTargetRetractsExistingContent(): void
    {
        $content = $this->createArticle($this->kitaA, 'An B', [$this->kitaB->getId()], false);
        self::assertNotNull($this->sliderItemFor($content, $this->kitaB));

        $oldAllowed = $this->audience->resolveAllowedTargetIds($this->kitaA);
        $this->kitaA->removePublishTarget($this->kitaB);
        $this->em->flush();
        $this->audience->syncCreatorTargets($this->kitaA, $oldAllowed);
        $this->em->flush();

        self::assertNull($this->sliderItemFor($content, $this->kitaB));
        // Creator still keeps their own slide
        self::assertNotNull($this->sliderItemFor($content, $this->kitaA));
    }

    public function testOnlyCreatorOrAdminCanEditOrDeleteContent(): void
    {
        $content = $this->createArticle($this->kitaA, 'Privat', [], false);

        $this->client->loginUser($this->kitaB);
        $this->client->request('GET', '/management/user/edit-content/' . $content->getId());
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->kitaA);
        $this->client->request('GET', '/management/user/edit-content/' . $content->getId());
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/management/user/edit-content/' . $content->getId());
        // Admin is redirected from /management/user/*? No — create-article is under /user/
        // Admin can access edit because access_control only requires ROLE_USER and ROLE_ADMIN includes it,
        // but ManagementRedirectSubscriber only redirects the dashboard routes.
        self::assertResponseIsSuccessful();
    }

    // --- Slider ------------------------------------------------------------

    public function testKitaCanReorderOwnSlides(): void
    {
        $first = $this->sliderItemFor($this->createArticle($this->kitaA, 'Slide 1'), $this->kitaA);
        $second = $this->sliderItemFor($this->createArticle($this->kitaA, 'Slide 2'), $this->kitaA);
        $third = $this->sliderItemFor($this->createArticle($this->kitaA, 'Slide 3'), $this->kitaA);

        $this->client->loginUser($this->kitaA);
        $this->arrangeSlider([$third->getId(), $first->getId(), $second->getId()]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([$third->getId(), $first->getId(), $second->getId()], $this->sliderOrderOf($this->kitaA));
    }

    public function testArrangingTheSliderGivesEverySlideItsOwnOrderNumber(): void
    {
        $first = $this->sliderItemFor($this->createArticle($this->kitaA, 'Slide 1'), $this->kitaA);
        $second = $this->sliderItemFor($this->createArticle($this->kitaA, 'Slide 2'), $this->kitaA);
        $unmentioned = $this->sliderItemFor($this->createArticle($this->kitaA, 'Slide 3'), $this->kitaA);
        foreach ([$first, $second, $unmentioned] as $item) {
            $item->setDisplayOrder(5);
        }
        $this->em->flush();

        $this->client->loginUser($this->kitaA);
        $this->arrangeSlider([$second->getId(), $first->getId()]);

        self::assertSame([$second->getId(), $first->getId(), $unmentioned->getId()], $this->sliderOrderOf($this->kitaA));
        $this->em->clear();
        $orderNumbers = array_map(
            static fn (SliderItem $item) => $item->getDisplayOrder(),
            $this->em->getRepository(SliderItem::class)->findAllForConsumer($this->em->find(User::class, $this->kitaA->getId()))
        );
        self::assertSame([1, 2, 3], $orderNumbers);
    }

    public function testArrangingIgnoresSlidesOfOtherKitas(): void
    {
        $foreignFirst = $this->sliderItemFor($this->createArticle($this->kitaB, 'Fremd 1'), $this->kitaB);
        $foreignSecond = $this->sliderItemFor($this->createArticle($this->kitaB, 'Fremd 2'), $this->kitaB);
        $this->createArticle($this->kitaA, 'Eigener Slide');

        $this->client->loginUser($this->kitaA);
        $this->arrangeSlider([$foreignSecond->getId(), $foreignFirst->getId()]);

        self::assertSame([$foreignFirst->getId(), $foreignSecond->getId()], $this->sliderOrderOf($this->kitaB));
    }

    public function testArrangingTheSliderNeedsAValidToken(): void
    {
        $this->client->loginUser($this->kitaA);
        $this->client->request('GET', '/management/user');
        $this->client->request('POST', '/management/slider-item/reorder', ['_token' => 'invalid', 'ids' => []]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param int[] $itemIdsInNewOrder
     */
    private function arrangeSlider(array $itemIdsInNewOrder): void
    {
        $crawler = $this->client->request('GET', '/management/user');
        $this->client->request('POST', '/management/slider-item/reorder', [
            '_token' => $crawler->filter('[data-reorder-token]')->attr('data-reorder-token'),
            'ids' => $itemIdsInNewOrder,
        ]);
    }

    /**
     * @return int[]
     */
    private function sliderOrderOf(User $consumer): array
    {
        $this->em->clear();

        return array_map(
            static fn (SliderItem $item) => $item->getId(),
            $this->em->getRepository(SliderItem::class)->findAllForConsumer($this->em->find(User::class, $consumer->getId()))
        );
    }

    public function testDisablingSlideOnlyAffectsOwnSlider(): void
    {
        $content = $this->createArticle($this->kitaA, 'Geteilt', [$this->kitaB->getId()], false);
        $itemA = $this->sliderItemFor($content, $this->kitaA);
        $itemB = $this->sliderItemFor($content, $this->kitaB);
        self::assertTrue($itemA->isEnabled());
        self::assertTrue($itemB->isEnabled());

        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user');
        $form = $crawler->filter(sprintf('form[action$="/management/slider-item/%d/toggle-status"]', $itemA->getId()))->form();
        $this->client->submit($form);

        $this->em->clear();
        $itemA = $this->em->getRepository(SliderItem::class)->find($itemA->getId());
        $itemB = $this->em->getRepository(SliderItem::class)->find($itemB->getId());
        self::assertFalse($itemA->isEnabled());
        self::assertTrue($itemB->isEnabled());
    }

    public function testAssignedForeignContentCannotBeToggled(): void
    {
        $content = $this->createArticle($this->kitaA, 'Von A', [$this->kitaB->getId()], false);
        $itemB = $this->sliderItemFor($content, $this->kitaB);
        self::assertNotNull($itemB);

        $this->client->loginUser($this->kitaB);
        $crawler = $this->client->request('GET', '/management/user');
        self::assertCount(
            0,
            $crawler->filter(sprintf('form[action$="/management/slider-item/%d/toggle-status"]', $itemB->getId()))
        );
        self::assertSelectorTextContains('#slider-item-' . $itemB->getId(), 'Zugewiesen');

        $this->client->request('POST', '/management/slider-item/' . $itemB->getId() . '/toggle-status', [
            '_token' => 'whatever',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testKitaCanSetSlideDuration(): void
    {
        $this->client->loginUser($this->kitaA);
        $crawler = $this->client->request('GET', '/management/user');
        $form = $crawler->filter('form')->reduce(function ($node) {
            return $node->filter('input[name="user_duration[durationBetweenSlides]"]')->count() > 0;
        })->first()->form([
            'user_duration[durationBetweenSlides]' => 25,
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/user');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($this->kitaA->getId());
        self::assertSame(25, $reloaded->getDurationBetweenSlides());
    }

    public function testKitaCannotManageOtherKitasSlides(): void
    {
        $content = $this->createArticle($this->kitaB, 'Fremd', [], false);
        $itemB = $this->sliderItemFor($content, $this->kitaB);

        $this->client->loginUser($this->kitaA);
        $this->client->request('POST', '/management/slider-item/' . $itemB->getId() . '/toggle-status', [
            '_token' => 'whatever',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    // --- TV-Anzeige --------------------------------------------------------

    public function testRootServesPinPageWithoutRedirect(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="pin"]');
        self::assertSelectorTextContains('body', 'PIN');
    }

    public function testCorrectPinLinksTvToSlider(): void
    {
        $crawler = $this->client->request('GET', '/slider/display');
        $form = $crawler->selectButton('Slider starten')->form(['pin' => '1111']);
        $this->client->submit($form);

        self::assertResponseRedirects('/slider/kita-a');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.tv-pin-watermark');
        self::assertSelectorTextContains('.tv-pin-watermark', '1111');
    }

    public function testAdminSeesPinReachabilityOnEditUser(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/management/admin/edit-user/' . $this->kitaA->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.pin-reachability');
        self::assertSelectorTextContains('.pin-reachability', '1111');

        $this->client->request('GET', '/management/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '1111');
        self::assertSelectorTextContains('body', 'nicht erreichbar'); // kita-c has no PIN
    }

    public function testCorrectPinLinksWithoutSessionCookie(): void
    {
        // Edge/Chromium block third-party cookies in a cross-site signage iframe.
        // The PIN POST must succeed without any prior session.
        $this->client->request('POST', '/slider/display', ['pin' => '1111']);

        self::assertResponseRedirects('/slider/kita-a');
    }

    public function testWrongPinIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/slider/display');
        $form = $crawler->selectButton('Slider starten')->form(['pin' => '9999']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.alert-danger');
        self::assertSelectorTextContains('body', 'PIN');
    }

    public function testChangedPinUnlinksTvOnNextPoll(): void
    {
        $crawler = $this->client->request('GET', '/slider/display');
        $form = $crawler->selectButton('Slider starten')->form(['pin' => '1111']);
        $this->client->submit($form);
        self::assertResponseRedirects('/slider/kita-a');
        $this->client->followRedirect();

        self::assertNotNull(
            $this->client->getCookieJar()->get('kitamanager_display_pin'),
            'TV PIN cookie must be set after linking'
        );

        $this->refreshEm();
        $kitaA = $this->em->find(User::class, $this->kitaA->getId());
        $kitaA->setDevicePin('4444');
        $this->em->flush();

        $this->client->request('GET', '/slider/kita-a/content');
        $body = json_decode($this->client->getResponse()->getContent(), true);

        self::assertTrue($body['unlinked'] ?? false, 'Expected unlinked after PIN change; got: ' . json_encode($body));
    }

    public function testDisplayPinHeaderKeepsSliderWithoutCookie(): void
    {
        $this->client->request('GET', '/slider/kita-a/content', [], [], [
            'HTTP_X_DISPLAY_PIN' => '1111',
        ]);
        $body = json_decode($this->client->getResponse()->getContent(), true);

        self::assertArrayHasKey('html', $body);
        self::assertArrayNotHasKey('unlinked', $body);
    }

    public function testStaleDisplayPinHeaderUnlinksWithoutCookie(): void
    {
        $this->client->request('GET', '/slider/kita-a/content', [], [], [
            'HTTP_X_DISPLAY_PIN' => '9999',
        ]);
        $body = json_decode($this->client->getResponse()->getContent(), true);

        self::assertTrue($body['unlinked'] ?? false);
    }

    public function testNewContentAppearsOnTvWithoutReload(): void
    {
        $crawler = $this->client->request('GET', '/slider/display');
        $form = $crawler->selectButton('Slider starten')->form(['pin' => '1111']);
        $this->client->submit($form);
        $this->client->followRedirect();

        $this->client->request('GET', '/slider/kita-a/content');
        $before = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('signature', $before);

        $this->createArticle($this->kitaA, 'Frisch auf dem TV', [], false);

        $this->client->request('GET', '/slider/kita-a/content');
        $after = json_decode($this->client->getResponse()->getContent(), true);

        self::assertNotSame($before['signature'], $after['signature']);
        self::assertStringContainsString('Frisch auf dem TV', $after['html']);
    }

    public function testSliderPageLoadsOnlyTheFirstSlideImageUpFront(): void
    {
        foreach (['Eins', 'Zwei', 'Drei'] as $title) {
            $this->createArticle($this->kitaA, $title);
        }

        $crawler = $this->client->request('GET', '/slider/kita-a');

        self::assertCount(3, $crawler->filter('.tv-slide'));
        self::assertCount(1, $crawler->filter('.tv-slide img[src]'));
        self::assertCount(1, $crawler->filter('.tv-slide.is-active img[src]'));
        self::assertCount(2, $crawler->filter('.tv-slide img[data-src]:not([src])'));
    }

    public function testPollServesWaitingImagesOnlyToTheScriptThatAsksForThem(): void
    {
        foreach (['Eins', 'Zwei', 'Drei'] as $title) {
            $this->createArticle($this->kitaA, $title);
        }

        $this->client->request('GET', '/slider/kita-a/content');
        $forTvsRunningTheOldScript = json_decode($this->client->getResponse()->getContent(), true)['html'];
        self::assertSame(3, substr_count($forTvsRunningTheOldScript, '<img src='));
        self::assertStringNotContainsString('data-src', $forTvsRunningTheOldScript);

        $this->client->request('GET', '/slider/kita-a/content?lazy=1');
        $forTheCurrentScript = json_decode($this->client->getResponse()->getContent(), true)['html'];
        self::assertSame(1, substr_count($forTheCurrentScript, '<img src='));
        self::assertSame(2, substr_count($forTheCurrentScript, '<img data-src='));
    }

    public function testSliderPageAndItsPollAgreeOnTheContentSignature(): void
    {
        $this->createArticle($this->kitaA, 'Eins');
        $this->createArticle($this->kitaA, 'Zwei');

        $crawler = $this->client->request('GET', '/slider/kita-a');
        $signatureInPage = $crawler->filter('#tv-slider')->attr('data-content-signature');
        $this->client->request('GET', '/slider/kita-a/content?lazy=1');

        self::assertSame($signatureInPage, json_decode($this->client->getResponse()->getContent(), true)['signature']);
    }

    public function testRenamedKitaKeepsFeedingItsLinkedTv(): void
    {
        $this->createArticle($this->kitaA, 'Vor der Umbenennung');
        $this->kitaA->setUsername('Kita Regenbogen');
        $this->em->flush();

        $this->client->request('GET', '/slider/kita-a/content', [], [], ['HTTP_X_DISPLAY_PIN' => '1111']);

        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('Vor der Umbenennung', $body['html'] ?? '');
    }

    public function testDeletedKitaSendsItsLinkedTvBackToPinEntry(): void
    {
        $this->em->remove($this->kitaA);
        $this->em->flush();

        $this->client->request('GET', '/slider/kita-a/content', [], [], ['HTTP_X_DISPLAY_PIN' => '1111']);

        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($body['unlinked'] ?? false);
    }

    public function testUnknownSliderWithoutPinIsNotFound(): void
    {
        $this->client->request('GET', '/slider/gibt-es-nicht/content');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLoggedInPreviewIsNotBoundToAPinEnteredEarlier(): void
    {
        $this->client->request('POST', '/slider/display', ['pin' => '1111']);
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/slider/kita-b/content');

        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('html', $body);
        self::assertArrayNotHasKey('unlinked', $body);
    }
}
