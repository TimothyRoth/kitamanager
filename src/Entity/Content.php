<?php

namespace App\Entity;

use App\Enum\ContentType;
use App\Repository\ContentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Content
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'content')]
    #[ORM\JoinColumn(name: 'User_Id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'Type', type: Types::STRING, enumType: ContentType::class)]
    private ?ContentType $type = null;

    #[ORM\Column(name: 'Image_Url', length: 255, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(name: 'Title', length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(name: 'Content', type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(name: 'Created_At')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'Last_Updated_At')]
    private ?\DateTimeImmutable $lastUpdatedAt = null;

    #[ORM\Column(name: 'display_order', type: Types::INTEGER)]
    private int $displayOrder = 0;

    #[ORM\Column(name: 'is_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEnabled = true;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUpdatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $repository = $entityManager->getRepository(Content::class);

        $qb = $repository->createQueryBuilder('c')
            ->select('MAX(c.displayOrder)');

        if ($this->getUser()) {
            $qb->where('c.user = :user')->setParameter('user', $this->getUser());
        } else {
            $qb->where('c.user IS NULL');
        }

        $highestOrder = (int) ($qb->getQuery()->getSingleScalarResult() ?? 0);

        // During a bulk insert (e.g. multi-image upload) none of the sibling rows are
        // flushed yet, so the MAX query above would return the same value for all of them.
        // Count the siblings already scheduled in this UnitOfWork within the same scope
        // (same owning user, or all global) so each gets a unique, incrementing order.
        $pendingSiblings = 0;
        foreach ($entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
            if ($scheduled === $this || !$scheduled instanceof self) {
                continue;
            }

            $scheduledUserId = $scheduled->getUser()?->getId();
            $thisUserId = $this->getUser()?->getId();

            if ($scheduledUserId === $thisUserId) {
                $pendingSiblings++;
            }
        }

        $this->displayOrder = $highestOrder + $pendingSiblings + 1;
    }

    #[ORM\PreUpdate]
    public function setLastUpdatedAtValue(): void
    {
        $this->lastUpdatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getType(): ?ContentType
    {
        return $this->type;
    }

    public function setType(ContentType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getLastUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->lastUpdatedAt;
    }

    public function setLastUpdatedAt(\DateTimeImmutable $lastUpdatedAt): static
    {
        $this->lastUpdatedAt = $lastUpdatedAt;

        return $this;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): static
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }
}
