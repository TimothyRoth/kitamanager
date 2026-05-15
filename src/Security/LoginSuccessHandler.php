<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

readonly class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private RouterInterface               $router,
        private AuthorizationCheckerInterface $authorizationChecker
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return new RedirectResponse($this->router->generate('app_management_admin'));
        }

        if ($this->authorizationChecker->isGranted('ROLE_USER')) {
            return new RedirectResponse($this->router->generate('app_management_user'));
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }
}
