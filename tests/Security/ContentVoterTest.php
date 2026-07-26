<?php

namespace App\Tests\Security;

use App\Entity\Content;
use App\Entity\User;
use App\Security\ContentVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class ContentVoterTest extends TestCase
{
    public function testAbstainsForUnsupportedAttribute(): void
    {
        $voter = $this->voter(false);
        $content = $this->contentOwnedBy($this->user(1));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token($this->user(1)), $content, ['VIEW']));
    }

    public function testDeniesAnonymous(): void
    {
        $voter = $this->voter(false);
        $content = $this->contentOwnedBy($this->user(1));

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $content, [ContentVoter::EDIT]));
    }

    public function testGrantsCreatorEditAndDelete(): void
    {
        $creator = $this->user(5);
        $voter = $this->voter(false);
        $content = $this->contentOwnedBy($creator);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($creator), $content, [ContentVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($creator), $content, [ContentVoter::DELETE]));
    }

    public function testDeniesOtherUser(): void
    {
        $voter = $this->voter(false);
        $content = $this->contentOwnedBy($this->user(1));

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($this->user(2)), $content, [ContentVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($this->user(2)), $content, [ContentVoter::DELETE]));
    }

    public function testGrantsAdminEvenWhenNotCreator(): void
    {
        $voter = $this->voter(true);
        $content = $this->contentOwnedBy($this->user(1));
        $admin = $this->user(99, ['ROLE_ADMIN', 'ROLE_USER']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($admin), $content, [ContentVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($admin), $content, [ContentVoter::DELETE]));
    }

    private function voter(bool $isAdmin): ContentVoter
    {
        $security = $this->createStub(AuthorizationCheckerInterface::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => 'ROLE_ADMIN' === $attribute && $isAdmin
        );

        return new ContentVoter($security);
    }

    private function user(int $id, array $roles = ['ROLE_USER']): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);

        return $user;
    }

    private function contentOwnedBy(User $creator): Content
    {
        $content = $this->createStub(Content::class);
        $content->method('getCreator')->willReturn($creator);

        return $content;
    }

    private function token(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
