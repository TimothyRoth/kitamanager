<?php

namespace App\Tests\Service;

use App\Entity\Content;
use App\Entity\SliderItem;
use App\Entity\User;
use App\Tests\AppKernelTestCase;

/**
 * Domain tests for audience → SliderItem reconciliation.
 */
final class AudienceSynchronizerTest extends AppKernelTestCase
{
    private User $admin;
    private User $kitaA;
    private User $kitaB;
    private User $kitaC;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->openFreshDatabase();
        $this->admin = $this->makeUser('admin', ['ROLE_ADMIN']);
        $this->kitaA = $this->makeUser('kita-a', ['ROLE_USER']);
        $this->kitaB = $this->makeUser('kita-b', ['ROLE_USER']);
        $this->kitaC = $this->makeUser('kita-c', ['ROLE_USER']);

        $this->kitaA->addPublishTarget($this->kitaB);
        $this->em->flush();
    }

    public function testIgnoresForbiddenAudienceIds(): void
    {
        $content = $this->makeArticle($this->kitaA, 'Nur erlaubte');

        $this->audience->syncContentAudience($content, [$this->kitaB->getId(), $this->kitaC->getId()], false);
        $this->em->flush();

        self::assertNotNull($this->sliderItemFor($content, $this->kitaA));
        self::assertNotNull($this->sliderItemFor($content, $this->kitaB));
        self::assertNull($this->sliderItemFor($content, $this->kitaC));
    }

    public function testAdminCreatorNeverGetsOwnSliderItem(): void
    {
        $this->admin->setPublishToAll(true);
        $this->em->flush();

        $content = $this->makeArticle($this->admin, 'Zentral');
        $this->audience->syncContentAudience($content, [], true);
        $this->em->flush();

        self::assertNull($this->sliderItemFor($content, $this->admin));
        self::assertNotNull($this->sliderItemFor($content, $this->kitaA));
        self::assertNotNull($this->sliderItemFor($content, $this->kitaB));
        self::assertNotNull($this->sliderItemFor($content, $this->kitaC));
    }

    public function testOnUserCreatedDeliversExistingPublishToAllContent(): void
    {
        $this->admin->setPublishToAll(true);
        $this->em->flush();

        $content = $this->makeArticle($this->admin, 'Für alle');
        $this->audience->syncContentAudience($content, [], true);
        $this->em->flush();

        $kitaNeu = $this->makeUser('kita-neu', ['ROLE_USER']);
        $this->audience->onUserCreated($kitaNeu);
        $this->em->flush();

        self::assertNotNull($this->sliderItemFor($content, $kitaNeu));
    }

    public function testOnUserCreatedSkipsAdminUsers(): void
    {
        $this->admin->setPublishToAll(true);
        $this->em->flush();

        $content = $this->makeArticle($this->admin, 'Für alle');
        $this->audience->syncContentAudience($content, [], true);
        $this->em->flush();

        $admin2 = $this->makeUser('admin-2', ['ROLE_ADMIN']);
        $this->audience->onUserCreated($admin2);
        $this->em->flush();

        self::assertNull($this->sliderItemFor($content, $admin2));
    }

    public function testAddingPublishTargetMaterializesAudienceAllContent(): void
    {
        $content = $this->makeArticle($this->kitaA, 'An alle Ziele');
        $this->audience->syncContentAudience($content, [], true);
        $this->em->flush();

        self::assertNotNull($this->sliderItemFor($content, $this->kitaB));
        self::assertNull($this->sliderItemFor($content, $this->kitaC));

        $oldAllowed = $this->audience->resolveAllowedTargetIds($this->kitaA);
        $this->kitaA->addPublishTarget($this->kitaC);
        $this->em->flush();
        $this->audience->syncCreatorTargets($this->kitaA, $oldAllowed);
        $this->em->flush();

        self::assertNotNull($this->sliderItemFor($content, $this->kitaC));
    }

    public function testMultiInsertAssignsUniqueDisplayOrders(): void
    {
        $c1 = $this->makeArticle($this->kitaA, 'Eins');
        $c2 = $this->makeArticle($this->kitaA, 'Zwei');
        $c3 = $this->makeArticle($this->kitaA, 'Drei');

        $this->audience->syncContentAudience($c1, [], false);
        $this->audience->syncContentAudience($c2, [], false);
        $this->audience->syncContentAudience($c3, [], false);
        $this->em->flush();

        $orders = array_map(
            fn (Content $c) => $this->sliderItemFor($c, $this->kitaA)?->getDisplayOrder(),
            [$c1, $c2, $c3]
        );

        self::assertCount(3, array_unique($orders));
        self::assertSame($orders, array_values(array_unique($orders)));
        self::assertSame([1, 2, 3], $orders);
    }

    public function testSyncPreservesEnabledStateForKeptConsumers(): void
    {
        $content = $this->makeArticle($this->kitaA, 'Geteilt');
        $this->audience->syncContentAudience($content, [$this->kitaB->getId()], false);
        $this->em->flush();

        $itemB = $this->sliderItemFor($content, $this->kitaB);
        self::assertNotNull($itemB);
        $itemB->setIsEnabled(false);
        $this->em->flush();

        $this->audience->syncContentAudience($content, [$this->kitaB->getId()], false);
        $this->em->flush();

        $itemB = $this->sliderItemFor($content, $this->kitaB);
        self::assertNotNull($itemB);
        self::assertFalse($itemB->isEnabled());
    }
}
