<?php

namespace App\Service;

use App\Entity\Content;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores a new content with its image and delivers it to its audience.
 */
final readonly class ContentPublisher
{
    public function __construct(
        private ImageUploader $imageUploader,
        private EntityManagerInterface $entityManager,
        private AudienceSynchronizer $audienceSynchronizer,
    ) {
    }

    /**
     * @param int[] $audienceIds ids the creator may not publish to are ignored
     */
    public function publish(Content $content, UploadedFile $imageFile, array $audienceIds, bool $audienceAll): void
    {
        $content->setImageUrl($this->imageUploader->upload($imageFile, $content->getCreator()->getId()));
        $this->entityManager->persist($content);
        $this->entityManager->flush();

        // Slider entries can only point at a content that has its id.
        $this->audienceSynchronizer->syncContentAudience($content, $audienceIds, $audienceAll);
        $this->entityManager->flush();
    }
}
