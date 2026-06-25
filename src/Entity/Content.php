<?php

namespace App\Entity;

use App\Enum\ContentType;
use App\Repository\ContentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
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
    #[ORM\JoinColumn(name: 'User_Id', nullable: false, onDelete: 'CASCADE')]
    private ?User $creator = null;

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

    /**
     * When true the audience is resolved dynamically to all of the creator's
     * currently allowed publish targets (present and future users).
     */
    #[ORM\Column(name: 'audience_all', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $audienceAll = false;

    /**
     * @var Collection<int, SliderItem>
     */
    #[ORM\OneToMany(targetEntity: SliderItem::class, mappedBy: 'content', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $sliderItems;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUpdatedAt = new \DateTimeImmutable();
        $this->sliderItems = new ArrayCollection();
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

    public function getCreator(): ?User
    {
        return $this->creator;
    }

    public function setCreator(?User $creator): static
    {
        $this->creator = $creator;

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

    public function isAudienceAll(): bool
    {
        return $this->audienceAll;
    }

    public function setAudienceAll(bool $audienceAll): static
    {
        $this->audienceAll = $audienceAll;

        return $this;
    }

    /**
     * @return Collection<int, SliderItem>
     */
    public function getSliderItems(): Collection
    {
        return $this->sliderItems;
    }

    public function addSliderItem(SliderItem $sliderItem): static
    {
        if (!$this->sliderItems->contains($sliderItem)) {
            $this->sliderItems->add($sliderItem);
            $sliderItem->setContent($this);
        }

        return $this;
    }

    public function removeSliderItem(SliderItem $sliderItem): static
    {
        if ($this->sliderItems->removeElement($sliderItem)) {
            if ($sliderItem->getContent() === $this) {
                $sliderItem->setContent(null);
            }
        }

        return $this;
    }
}
