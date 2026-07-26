<?php

namespace App\Tests\Security;

use App\Entity\SliderItem;
use App\Entity\User;
use App\Security\SliderItemVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class SliderItemVoterTest extends TestCase
{
    public function testAbstainsForUnsupportedAttribute(): void
    {
        $voter = $this->voter(false);
        $item = $this->itemOwnedBy($this->user(1));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token($this->user(1)), $item, ['EDIT']));
    }

    public function testDeniesAnonymous(): void
    {
        $voter = $this->voter(false);
        $item = $this->itemOwnedBy($this->user(1));

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $item, [SliderItemVoter::MANAGE]));
    }

    public function testGrantsOwningConsumer(): void
    {
        $consumer = $this->user(3);
        $voter = $this->voter(false);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($consumer), $this->itemOwnedBy($consumer), [SliderItemVoter::MANAGE])
        );
    }

    public function testDeniesOtherConsumer(): void
    {
        $voter = $this->voter(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($this->user(1)), $this->itemOwnedBy($this->user(2)), [SliderItemVoter::MANAGE])
        );
    }

    public function testGrantsAdminForAnyItem(): void
    {
        $voter = $this->voter(true);
        $admin = $this->user(99, ['ROLE_ADMIN', 'ROLE_USER']);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($admin), $this->itemOwnedBy($this->user(1)), [SliderItemVoter::MANAGE])
        );
    }

    private function voter(bool $isAdmin): SliderItemVoter
    {
        $security = $this->createStub(AuthorizationCheckerInterface::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => 'ROLE_ADMIN' === $attribute && $isAdmin
        );

        return new SliderItemVoter($security);
    }

    private function user(int $id, array $roles = ['ROLE_USER']): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);

        return $user;
    }

    private function itemOwnedBy(User $consumer): SliderItem
    {
        $item = $this->createStub(SliderItem::class);
        $item->method('getConsumer')->willReturn($consumer);

        return $item;
    }

    private function token(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
