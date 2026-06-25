<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\SliderItem;
use App\Entity\User;
use App\Repository\SliderItemRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single source of truth for keeping the SliderItem delivery rows consistent.
 *
 * A SliderItem (content, consumer) means "this content is delivered to this
 * consumer's slider". Reordering and enabling/disabling happen per consumer on
 * the SliderItem; the creator only controls a content's existence and audience.
 */
class AudienceSynchronizer
{
    /**
     * Per-request cache of the next display order to assign per consumer id,
     * so several inserts in one request keep a unique, incrementing order.
     *
     * @var array<int, int>
     */
    private array $nextOrderCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly SliderItemRepository $sliderItemRepository,
    ) {
    }

    /**
     * Reconcile the SliderItems of a single content to match the desired
     * audience. The creator is always included if the creator is a consumer.
     * Items for consumers that fell out of the audience are removed
     * (= "delete the content for that consumer"). Reorder/enabled state of
     * kept consumers is preserved.
     *
     * @param int[] $selectedConsumerIds Chosen target user ids (ignored when $all is true)
     */
    public function syncContentAudience(Content $content, array $selectedConsumerIds, bool $all): void
    {
        $creator = $content->getCreator();
        if (!$creator instanceof User) {
            return;
        }

        $content->setAudienceAll($all);

        /** @var array<int, User> $desired */
        $desired = [];

        if ($all) {
            foreach ($this->allowedTargetUsers($creator) as $user) {
                $desired[$user->getId()] = $user;
            }
        } else {
            $allowedIds = $this->resolveAllowedTargetIds($creator);
            foreach ($selectedConsumerIds as $id) {
                $id = (int) $id;
                if (!in_array($id, $allowedIds, true)) {
                    continue;
                }
                $user = $this->userRepository->find($id);
                if ($user instanceof User) {
                    $desired[$id] = $user;
                }
            }
        }

        // A content is always delivered to its creator (unless the creator is
        // a pure manager, e.g. the admin, who has no slider).
        if ($this->isConsumer($creator)) {
            $desired[$creator->getId()] = $creator;
        }

        $existing = [];
        foreach ($this->sliderItemRepository->findByContent($content) as $item) {
            $existing[$item->getConsumer()->getId()] = $item;
        }

        foreach ($existing as $consumerId => $item) {
            if (!isset($desired[$consumerId])) {
                $this->entityManager->remove($item);
            }
        }

        foreach ($desired as $consumerId => $user) {
            if (!isset($existing[$consumerId])) {
                $this->createItem($content, $user);
            }
        }
    }

    /**
     * React to an admin changing a creator's allowed publish targets.
     * Targets that were removed get their delivered content retracted; for
     * newly allowed targets, dynamic ("all") content is re-materialized.
     *
     * @param int[] $oldAllowedIds Allowed target ids captured before the change
     */
    public function syncCreatorTargets(User $creator, array $oldAllowedIds): void
    {
        $newAllowedIds = $this->resolveAllowedTargetIds($creator);

        $removed = array_values(array_diff($oldAllowedIds, $newAllowedIds));
        $added = array_values(array_diff($newAllowedIds, $oldAllowedIds));

        if ($removed) {
            $this->entityManager->createQuery(
                'DELETE FROM App\Entity\SliderItem si
                 WHERE si.consumer IN (:removed)
                   AND si.content IN (
                       SELECT c.id FROM App\Entity\Content c WHERE c.creator = :creator
                   )'
            )
                ->setParameter('removed', $removed)
                ->setParameter('creator', $creator)
                ->execute();
        }

        if ($added) {
            $contents = $this->entityManager->getRepository(Content::class)
                ->findBy(['creator' => $creator, 'audienceAll' => true]);

            $addedUsers = $this->userRepository->findBy(['id' => $added]);

            foreach ($contents as $content) {
                foreach ($addedUsers as $user) {
                    $this->createItem($content, $user);
                }
            }
        }
    }

    /**
     * When a new user is created, deliver every dynamic ("all") content whose
     * creator publishes to all users.
     */
    public function onUserCreated(User $newUser): void
    {
        if (!$this->isConsumer($newUser)) {
            return;
        }

        $contents = $this->entityManager->createQuery(
            'SELECT c FROM App\Entity\Content c
             JOIN c.creator cr
             WHERE c.audienceAll = true AND cr.publishToAll = true'
        )->getResult();

        foreach ($contents as $content) {
            $this->createItem($content, $newUser);
        }
    }

    /**
     * Allowed target user ids for a creator (excluding self).
     *
     * @return int[]
     */
    public function resolveAllowedTargetIds(User $creator): array
    {
        return array_map(
            static fn (User $u) => $u->getId(),
            $this->allowedTargetUsers($creator)
        );
    }

    /**
     * Allowed target users for a creator (excluding self).
     *
     * @return User[]
     */
    public function allowedTargetUsers(User $creator): array
    {
        if ($creator->isPublishToAll()) {
            return array_values(array_filter(
                $this->userRepository->getUsersByRole('ROLE_USER'),
                static fn (User $u) => $u->getId() !== $creator->getId()
            ));
        }

        return array_values($creator->getPublishTargets()->toArray());
    }

    private function createItem(Content $content, User $consumer): void
    {
        if (null !== $this->sliderItemRepository->findOneBy(['content' => $content, 'consumer' => $consumer])) {
            return;
        }

        $item = new SliderItem();
        $item->setConsumer($consumer);
        $item->setIsEnabled(true);
        $item->setDisplayOrder($this->nextOrder($consumer));

        // Keep both inverse collections consistent so ORM cascade/orphanRemoval
        // reliably deletes these rows when the content or consumer is removed
        // (SQLite does not enforce the ON DELETE CASCADE foreign keys at runtime).
        $content->addSliderItem($item);
        $consumer->getSliderItems()->add($item);

        $this->entityManager->persist($item);
    }

    private function nextOrder(User $consumer): int
    {
        $id = $consumer->getId();

        if (!isset($this->nextOrderCache[$id])) {
            $this->nextOrderCache[$id] = $this->sliderItemRepository->maxDisplayOrderForConsumer($consumer) + 1;
        } else {
            $this->nextOrderCache[$id]++;
        }

        return $this->nextOrderCache[$id];
    }

    private function isConsumer(User $user): bool
    {
        return !in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
