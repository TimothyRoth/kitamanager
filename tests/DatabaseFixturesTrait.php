<?php

namespace App\Tests;

use App\Entity\Content;
use App\Entity\SliderItem;
use App\Entity\User;
use App\Enum\ContentType;
use App\Service\AudienceSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The empty schema and the entities most database tests start from.
 */
trait DatabaseFixturesTrait
{
    protected EntityManagerInterface $em;
    protected AudienceSynchronizer $audience;

    /**
     * Call once the kernel or the client is booted.
     */
    protected function openFreshDatabase(): void
    {
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->audience = static::getContainer()->get(AudienceSynchronizer::class);

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    /**
     * The password is "<username>-secret".
     *
     * @param list<string> $roles
     */
    protected function makeUser(string $username, array $roles = ['ROLE_USER'], ?string $pin = null): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername($username);
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, $username . '-secret'));
        $user->setDevicePin($pin);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * An article that is stored but delivered to nobody yet.
     */
    protected function makeArticle(User $creator, string $title): Content
    {
        $content = new Content();
        $content->setType(ContentType::ARTICLE);
        $content->setTitle($title);
        $content->setContent('Body of ' . $title);
        $content->setImageUrl('/uploads/test.jpg');
        $content->setCreator($creator);
        $this->em->persist($content);
        $this->em->flush();

        return $content;
    }

    /**
     * An article delivered to its creator and the given audience.
     *
     * @param int[] $audienceIds
     */
    protected function publishArticle(User $creator, string $title, array $audienceIds = [], bool $audienceAll = false): Content
    {
        // A kernel request detaches the entities a test holds.
        $creator = $this->em->find(User::class, $creator->getId());
        self::assertNotNull($creator);

        $content = $this->makeArticle($creator, $title);
        $this->audience->syncContentAudience($content, $audienceIds, $audienceAll);
        $this->em->flush();

        return $content;
    }

    protected function sliderItemFor(Content $content, User $consumer): ?SliderItem
    {
        return $this->em->getRepository(SliderItem::class)->findOneBy([
            'content' => $content,
            'consumer' => $consumer,
        ]);
    }
}
