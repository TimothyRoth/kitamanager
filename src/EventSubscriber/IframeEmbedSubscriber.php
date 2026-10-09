<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Intranet: the site may be shown in any iframe. Reachability of the
 * internal DNS name (typically via VPN) is the access control; browser
 * clickjacking headers must not add a second origin check on top.
 */
final class IframeEmbedSubscriber implements EventSubscriberInterface
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->allowEmbedding($event->getResponse());
    }

    public static function getSubscribedEvents(): array
    {
        // Run late so a proxy-copied SAMEORIGIN header set earlier is cleared.
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }

    private function allowEmbedding(Response $response): void
    {
        $headers = $response->headers;
        $headers->remove('X-Frame-Options');
        $headers->set('Content-Security-Policy', 'frame-ancestors *');
        $headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        $headers->set('Access-Control-Allow-Origin', '*');
    }
}
