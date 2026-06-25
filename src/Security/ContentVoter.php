<?php

namespace App\Security;

use App\Entity\Content;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ContentVoter extends Voter
{
    const EDIT = 'EDIT';
    const DELETE = 'DELETE';

    private AuthorizationCheckerInterface $security;

    public function __construct(AuthorizationCheckerInterface $security)
    {
        $this->security = $security;
    }

    protected function supports(string $attribute, $subject): bool
    {
        if (!in_array($attribute, [self::EDIT, self::DELETE])) {
            return false;
        }

        if (!$subject instanceof Content) {
            return false;
        }

        return true;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }

        /** @var Content $content */
        $content = $subject;

        // Admins can do anything
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Only the creator may edit/delete the content itself (this removes it
        // for every consumer). Consumers only control their own SliderItem.
        return null !== $content->getCreator()
            && $content->getCreator()->getId() === $user->getId();
    }
}
