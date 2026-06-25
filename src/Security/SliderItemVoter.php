<?php

namespace App\Security;

use App\Entity\SliderItem;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A consumer may reorder/enable their own slider entries; an admin may manage any.
 */
class SliderItemVoter extends Voter
{
    const MANAGE = 'MANAGE';

    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    protected function supports(string $attribute, $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof SliderItem;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        /** @var SliderItem $item */
        $item = $subject;

        return null !== $item->getConsumer()
            && $item->getConsumer()->getId() === $user->getId();
    }
}
