<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A script that posts a form with XMLHttpRequest cannot see a redirect: the
 * request follows it unseen, drops the #fragment and uses up the one-time
 * messages of the page behind it. Such a request is told the target in a
 * header instead and goes there itself (submitFormWithProgress in script.js).
 */
final class ScriptRedirectSubscriber implements EventSubscriberInterface
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        if (!$event->isMainRequest() || !$event->getRequest()->isXmlHttpRequest() || !$response->isRedirect()) {
            return;
        }

        $response->headers->set('X-Redirect-To', $response->headers->get('Location'));
        $response->headers->remove('Location');
        $response->setStatusCode(Response::HTTP_NO_CONTENT);
        $response->setContent('');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }
}
