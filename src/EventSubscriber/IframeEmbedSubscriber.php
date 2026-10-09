<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Intranet / Smart-TV: allow embedding from any parent, including local
 * HTML gadgets opened as file:// (Philips CMND, desktop test files, …).
 *
 * Important: do NOT send "Content-Security-Policy: frame-ancestors *".
 * In CSP3, "*" only matches http/https/ws/wss parents — not file: — so that
 * header blocks the exact TV/test setup we need. Omitting both CSP
 * frame-ancestors and X-Frame-Options is what actually permits all parents.
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
        // Run late so a proxy-copied SAMEORIGIN / frame-ancestors header is cleared.
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }

    private function allowEmbedding(Response $response): void
    {
        $headers = $response->headers;
        $headers->remove('X-Frame-Options');
        $this->stripFrameAncestorsCsp($response);
        $headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        $headers->set('Access-Control-Allow-Origin', '*');
    }

    /**
     * Drop frame-ancestors from CSP (or the whole header if that was the only
     * directive). Leaving "frame-ancestors *" in place would block file: parents.
     */
    private function stripFrameAncestorsCsp(Response $response): void
    {
        $headers = $response->headers;
        $csp = $headers->all('Content-Security-Policy');
        if ([] === $csp) {
            return;
        }

        $kept = [];
        foreach ($csp as $policy) {
            $directives = array_filter(array_map('trim', explode(';', (string) $policy)));
            $directives = array_values(array_filter(
                $directives,
                static fn (string $directive): bool => !str_starts_with(strtolower($directive), 'frame-ancestors')
            ));
            if ([] !== $directives) {
                $kept[] = implode('; ', $directives);
            }
        }

        $headers->remove('Content-Security-Policy');
        foreach ($kept as $policy) {
            $headers->set('Content-Security-Policy', $policy, false);
        }
    }
}
