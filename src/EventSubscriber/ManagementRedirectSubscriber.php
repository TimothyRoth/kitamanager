<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

readonly class ManagementRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface               $router,
        private AuthorizationCheckerInterface $authorizationChecker,
        private TokenStorageInterface         $tokenStorage
    )
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $routeName = $request->attributes->get('_route');

        if (!$this->tokenStorage->getToken()) {
            return;
        }

        if ('app_management_user' === $routeName && $this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $event->setResponse(new RedirectResponse($this->router->generate('app_management_admin')));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}
