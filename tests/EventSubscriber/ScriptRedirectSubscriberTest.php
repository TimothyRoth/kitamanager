<?php

namespace App\Tests\EventSubscriber;

use App\Tests\AppWebTestCase;

final class ScriptRedirectSubscriberTest extends AppWebTestCase
{
    public function testARequestFromAScriptIsToldWhereTheRedirectLeads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/management/user', server: ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('X-Redirect-To', 'http://localhost/admin');
        self::assertResponseNotHasHeader('Location');
    }

    public function testEveryOtherRequestIsRedirectedAsUsual(): void
    {
        $client = static::createClient();
        $client->request('GET', '/management/user');

        self::assertResponseRedirects('http://localhost/admin');
        self::assertResponseNotHasHeader('X-Redirect-To');
    }
}
