<?php

namespace App\Security;

use App\Entity\SliderItem;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * MANAGE  – reorder entries in one's own slider (consumer or admin).
 * TOGGLE  – enable/disable only for content the current user created
 *           (assigned/foreign content stays always-on for the consumer).
 */
class SliderItemVoter extends Voter
{
    const MANAGE = 'MANAGE';
    const TOGGLE = 'TOGGLE';

    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return in_array($attribute, [self::MANAGE, self::TOGGLE], true)
            && $subject instanceof SliderItem;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var SliderItem $item */
        $item = $subject;

        if (!$this->isConsumerOrAdmin($user, $item)) {
            return false;
        }

        if (self::MANAGE === $attribute) {
            return true;
        }

        // TOGGLE: own creations only (admins may still toggle anything they manage).
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        $creator = $item->getContent()?->getCreator();

        return null !== $creator && $creator->getId() === $user->getId();
    }

    private function isConsumerOrAdmin(User $user, SliderItem $item): bool
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return null !== $item->getConsumer()
            && $item->getConsumer()->getId() === $user->getId();
    }
}
