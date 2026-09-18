<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Splits the app across two hostnames when DISPLAY_HOST and MANAGEMENT_HOST
 * are both configured. Other hosts (e.g. public prod URL, localhost) stay
 * unrestricted so single-domain deployments keep working.
 *
 *   displays…  → /slider* (TV PIN + slideshow)
 *   dpm…       → /management*, /login, /logout; /slider preview OK;
 *                /slider/display redirects to the display host
 */
final class HostSeparationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%app.display_host%')]
        private readonly string $displayHost,
        #[Autowire('%app.management_host%')]
        private readonly string $managementHost,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the router has matched, before the controller runs.
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Symfony / tooling internals.
        if (str_starts_with($path, '/_')) {
            return;
        }

        $host = $request->getHost();

        if ($host === $this->displayHost) {
            if ($this->isManagementOnlyPath($path)) {
                $event->setResponse(new Response('Not Found', Response::HTTP_NOT_FOUND));
            }

            return;
        }

        if ($host === $this->managementHost) {
            if ($this->isDisplayPinPath($path)) {
                $target = $request->getScheme().'://'.$this->displayHost.'/slider/display';
                $event->setResponse(new RedirectResponse($target, Response::HTTP_FOUND));
            }

            return;
        }
    }

    public function isEnabled(): bool
    {
        return $this->displayHost !== '' && $this->managementHost !== '';
    }

    private function isManagementOnlyPath(string $path): bool
    {
        return str_starts_with($path, '/management')
            || $path === '/login'
            || str_starts_with($path, '/login/')
            || $path === '/logout'
            || str_starts_with($path, '/logout/');
    }

    private function isDisplayPinPath(string $path): bool
    {
        return $path === '/slider/display' || str_starts_with($path, '/slider/display/');
    }
}
