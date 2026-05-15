<?php

namespace App\EventListener;

use App\Entity\Content;
use App\Entity\User;
use App\Service\ImageUploader;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: Content::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: User::class)]
readonly class ImageDeletionListener
{
    public function __construct(private ImageUploader $imageUploader)
    {
    }

    public function preRemove(object $entity): void
    {
        if ($entity instanceof Content) {
            // This handles individual content deletion by the user
            $this->imageUploader->delete($entity->getImageUrl());
        }

        if ($entity instanceof User) {
            // This handles the admin deleting a user
            $this->imageUploader->deleteUserDirectory($entity->getId());
        }
    }
}
