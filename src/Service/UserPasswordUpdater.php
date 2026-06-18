<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class UserPasswordUpdater
{
    public function __construct(private UserPasswordHasherInterface $hasher)
    {
    }

    public function update(User $user, ?string $plainPassword): void
    {
        if (!$plainPassword) {
            return;
        }

        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
    }
}
