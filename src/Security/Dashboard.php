<?php

namespace App\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final readonly class Dashboard
{
    public function __construct(private AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    public function routeOfCurrentUser(): string
    {
        return $this->authorizationChecker->isGranted('ROLE_ADMIN')
            ? 'app_management_admin'
            : 'app_management_user';
    }
}
