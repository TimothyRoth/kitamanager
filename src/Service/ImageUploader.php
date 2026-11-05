<?php

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class ImageUploader
{
    private const PUBLIC_PREFIX = '/uploads';

    private string $targetDirectory;
    private SluggerInterface $slugger;
    private Filesystem $filesystem;

    public function __construct(string $targetDirectory, SluggerInterface $slugger, Filesystem $filesystem)
    {
        $this->targetDirectory = $targetDirectory;
        $this->slugger = $slugger;
        $this->filesystem = $filesystem;
    }

    public function upload(UploadedFile $file, int $userId): string
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename);
        $extension = $file->guessExtension();
        $fileName = $safeFilename . '.' . $extension;

        $uploadDirectory = $this->targetDirectory . '/' . $userId;

        $counter = 1;
        while (file_exists($uploadDirectory . '/' . $fileName)) {
            $fileName = $safeFilename . '_' . $counter . '.' . $extension;
            $counter++;
        }

        try {
            $file->move($uploadDirectory, $fileName);
        } catch (FileException $e) {
            throw new \RuntimeException('Could not move the uploaded file: ' . $e->getMessage(), 0, $e);
        }

        return self::PUBLIC_PREFIX . '/' . $userId . '/' . $fileName;
    }

    public function delete(?string $filePath): void
    {
        if (empty($filePath)) {
            return;
        }

        $fullPath = $this->targetDirectory . substr($filePath, strlen(self::PUBLIC_PREFIX));

        if ($this->filesystem->exists($fullPath)) {
            $this->filesystem->remove($fullPath);
        }
    }

    public function deleteUserDirectory(int $userId): void
    {
        $userDirectory = $this->targetDirectory . '/' . $userId;
        if ($this->filesystem->exists($userDirectory)) {
            $this->filesystem->remove($userDirectory);
        }
    }

    public function getTargetDirectory(): string
    {
        return $this->targetDirectory;
    }
}
