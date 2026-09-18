<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\HostSeparationSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HostSeparationSubscriberTest extends TestCase
{
    public function testDisabledWhenHostsEmpty(): void
    {
        $subscriber = new HostSeparationSubscriber('', '');
        $event = $this->event('displays.drk.local', '/login');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testDisplayHostBlocksLogin(): void
    {
        $subscriber = new HostSeparationSubscriber('displays.drk.local', 'dpm.drk.local');
        $event = $this->event('displays.drk.local', '/login');
        $subscriber->onKernelRequest($event);

        self::assertInstanceOf(Response::class, $event->getResponse());
        self::assertSame(404, $event->getResponse()->getStatusCode());
    }

    public function testDisplayHostAllowsSlider(): void
    {
        $subscriber = new HostSeparationSubscriber('displays.drk.local', 'dpm.drk.local');
        $event = $this->event('displays.drk.local', '/slider/display');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testManagementHostRedirectsPinEntryToDisplayHost(): void
    {
        $subscriber = new HostSeparationSubscriber('displays.drk.local', 'dpm.drk.local');
        $event = $this->event('dpm.drk.local', '/slider/display');
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('http://displays.drk.local/slider/display', $response->getTargetUrl());
    }

    public function testManagementHostAllowsManagementAndSliderPreview(): void
    {
        $subscriber = new HostSeparationSubscriber('displays.drk.local', 'dpm.drk.local');

        foreach (['/login', '/management/user', '/slider', '/slider/kita-a'] as $path) {
            $event = $this->event('dpm.drk.local', $path);
            $subscriber->onKernelRequest($event);
            self::assertNull($event->getResponse(), $path);
        }
    }

    public function testUnknownHostIsUnrestricted(): void
    {
        $subscriber = new HostSeparationSubscriber('displays.drk.local', 'dpm.drk.local');
        $event = $this->event('kita-manager.timothy-roth.de', '/login');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    private function event(string $host, string $path): RequestEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
        $request = Request::create('http://'.$host.$path);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
