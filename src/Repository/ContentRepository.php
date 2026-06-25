<?php

namespace App\Repository;

use App\Entity\Content;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Content>
 */
class ContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Content::class);
    }

    /**
     * Content created (owned) by a user.
     *
     * @return Content[]
     */
    public function findByCreator(User $creator): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.creator = :creator')
            ->setParameter('creator', $creator)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
