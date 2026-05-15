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
     * @return Content[]
     */
    public function findEnabledByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.isEnabled = :isEnabled')
            ->setParameter('user', $user)
            ->setParameter('isEnabled', true)
            ->orderBy('c.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Content[]
     */
    public function findAvailableForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('CASE WHEN c.user IS NULL THEN 0 ELSE 1 END AS HIDDEN sort_group')
            ->where('c.isEnabled = :isEnabled')
            ->andWhere('c.user = :user OR c.user IS NULL')
            ->setParameter('isEnabled', true)
            ->setParameter('user', $user)
            ->orderBy('sort_group', 'ASC')
            ->addOrderBy('c.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
