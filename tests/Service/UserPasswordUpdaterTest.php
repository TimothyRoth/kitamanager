<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\UserPasswordUpdater;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserPasswordUpdaterTest extends TestCase
{
    public function testUpdateHashesWhenPlainPasswordProvided(): void
    {
        $user = new User();
        $user->setUsername('u');
        $user->setPassword('old');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with($user, 'fresh')
            ->willReturn('hashed-fresh');

        (new UserPasswordUpdater($hasher))->update($user, 'fresh');

        self::assertSame('hashed-fresh', $user->getPassword());
    }

    public function testUpdateIgnoresEmptyPassword(): void
    {
        $user = new User();
        $user->setUsername('u');
        $user->setPassword('old');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');

        (new UserPasswordUpdater($hasher))->update($user, null);
        (new UserPasswordUpdater($hasher))->update($user, '');

        self::assertSame('old', $user->getPassword());
    }
}
