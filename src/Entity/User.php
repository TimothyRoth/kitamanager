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

    #[ORM\OneToMany(targetEntity: Content::class, mappedBy: 'user', orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC'])]
    private Collection $content;

    public function __construct()
    {
        $this->roles = ['ROLE_USER'];
        $this->content = new ArrayCollection();
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
            $content->setUser($this);
        }

        return $this;
    }

    public function removeContent(Content $content): static
    {
        if ($this->content->removeElement($content)) {
            // set the owning side to null (unless already changed)
            if ($content->getUser() === $this) {
                $content->setUser(null);
            }
        }

        return $this;
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
