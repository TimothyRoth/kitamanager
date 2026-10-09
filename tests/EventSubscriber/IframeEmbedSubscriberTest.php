<?php

namespace App\Tests\EventSubscriber;

use App\Tests\AppWebTestCase;

final class IframeEmbedSubscriberTest extends AppWebTestCase
{
    public function testLoginPageAllowsEmbeddingIncludingFileParents(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $headers = $client->getResponse()->headers;

        self::assertNull($headers->get('X-Frame-Options'));
        // No frame-ancestors at all: "*" would block file:// TV/test parents.
        self::assertNull($headers->get('Content-Security-Policy'));
        self::assertSame('cross-origin', $headers->get('Cross-Origin-Resource-Policy'));
        self::assertSame('*', $headers->get('Access-Control-Allow-Origin'));
    }
}
