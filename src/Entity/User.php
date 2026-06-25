<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $username = null;

    #[ORM\Column]
    private array $roles;

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    #[ORM\Column]
    private int $durationBetweenSlides = 10;

    /**
     * Content created (owned) by this user.
     *
     * @var Collection<int, Content>
     */
    #[ORM\OneToMany(targetEntity: Content::class, mappedBy: 'creator', orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $content;

    /**
     * Users this user is allowed to publish content to (assigned by an admin).
     * Self is always implicitly included and is not stored here.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'user_publish_target')]
    #[ORM\JoinColumn(name: 'source_user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'target_user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $publishTargets;

    /**
     * When true this user may publish to every user (present and future).
     */
    #[ORM\Column(name: 'publish_to_all', type: 'boolean', options: ['default' => false])]
    private bool $publishToAll = false;

    /**
     * Slider entries delivered to this user (as a consumer).
     *
     * @var Collection<int, SliderItem>
     */
    #[ORM\OneToMany(targetEntity: SliderItem::class, mappedBy: 'consumer', orphanRemoval: true)]
    private Collection $sliderItems;

    public function __construct()
    {
        $this->roles = ['ROLE_USER'];
        $this->content = new ArrayCollection();
        $this->publishTargets = new ArrayCollection();
        $this->sliderItems = new ArrayCollection();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateSlug(): void
    {
        if ($this->username) {
            $this->slug = trim(strtolower(str_replace(' ', '-', $this->username)));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string)$this->username;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getDurationBetweenSlides(): int
    {
        return $this->durationBetweenSlides;
    }

    public function setDurationBetweenSlides(int $durationBetweenSlides): static
    {
        $this->durationBetweenSlides = $durationBetweenSlides;

        return $this;
    }

    /**
     * @return Collection<int, Content>
     */
    public function getContent(): Collection
    {
        return $this->content;
    }

    public function addContent(Content $content): static
    {
        if (!$this->content->contains($content)) {
            $this->content->add($content);
            $content->setCreator($this);
        }

        return $this;
    }

    public function removeContent(Content $content): static
    {
        if ($this->content->removeElement($content)) {
            // set the owning side to null (unless already changed)
            if ($content->getCreator() === $this) {
                $content->setCreator(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getPublishTargets(): Collection
    {
        return $this->publishTargets;
    }

    public function addPublishTarget(User $user): static
    {
        if ($user !== $this && !$this->publishTargets->contains($user)) {
            $this->publishTargets->add($user);
        }

        return $this;
    }

    public function removePublishTarget(User $user): static
    {
        $this->publishTargets->removeElement($user);

        return $this;
    }

    public function isPublishToAll(): bool
    {
        return $this->publishToAll;
    }

    public function setPublishToAll(bool $publishToAll): static
    {
        $this->publishToAll = $publishToAll;

        return $this;
    }

    /**
     * @return Collection<int, SliderItem>
     */
    public function getSliderItems(): Collection
    {
        return $this->sliderItems;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array)$this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password ?? '');

        return $data;
    }

    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }
}
