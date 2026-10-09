<?php

namespace App\Tests\EventSubscriber;

use App\Tests\AppWebTestCase;

final class IframeEmbedSubscriberTest extends AppWebTestCase
{
    public function testLoginPageAllowsEmbeddingFromAnyOrigin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $headers = $client->getResponse()->headers;

        self::assertNull($headers->get('X-Frame-Options'));
        self::assertSame('frame-ancestors *', $headers->get('Content-Security-Policy'));
        self::assertSame('cross-origin', $headers->get('Cross-Origin-Resource-Policy'));
        self::assertSame('*', $headers->get('Access-Control-Allow-Origin'));
    }
}
