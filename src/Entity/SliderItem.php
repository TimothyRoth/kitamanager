<?php

namespace App\Entity;

use App\Repository\SliderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SliderItemRepository::class)]
#[ORM\Table(name: 'slider_item')]
#[ORM\UniqueConstraint(name: 'UNIQ_SLIDER_ITEM_CONTENT_CONSUMER', columns: ['content_id', 'consumer_id'])]
class SliderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sliderItems')]
    #[ORM\JoinColumn(name: 'content_id', nullable: false, onDelete: 'CASCADE')]
    private ?Content $content = null;

    #[ORM\ManyToOne(inversedBy: 'sliderItems')]
    #[ORM\JoinColumn(name: 'consumer_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $consumer = null;

    #[ORM\Column(name: 'display_order', type: Types::INTEGER)]
    private int $displayOrder = 0;

    #[ORM\Column(name: 'is_enabled', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEnabled = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): ?Content
    {
        return $this->content;
    }

    public function setContent(?Content $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getConsumer(): ?User
    {
        return $this->consumer;
    }

    public function setConsumer(?User $consumer): static
    {
        $this->consumer = $consumer;

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
