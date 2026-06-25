<?php

namespace App\Repository;

use App\Entity\Content;
use App\Entity\SliderItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SliderItem>
 */
class SliderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SliderItem::class);
    }

    /**
     * Active slides for a consumer's slider, in display order.
     *
     * @return SliderItem[]
     */
    public function findEnabledForConsumer(User $consumer): array
    {
        return $this->createQueryBuilder('si')
            ->innerJoin('si.content', 'c')->addSelect('c')
            ->andWhere('si.consumer = :consumer')
            ->andWhere('si.isEnabled = :enabled')
            ->setParameter('consumer', $consumer)
            ->setParameter('enabled', true)
            ->orderBy('si.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every slider entry of a consumer (enabled or not), in display order.
     *
     * @return SliderItem[]
     */
    public function findAllForConsumer(User $consumer): array
    {
        return $this->createQueryBuilder('si')
            ->innerJoin('si.content', 'c')->addSelect('c')
            ->leftJoin('c.creator', 'creator')->addSelect('creator')
            ->andWhere('si.consumer = :consumer')
            ->setParameter('consumer', $consumer)
            ->orderBy('si.displayOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return SliderItem[]
     */
    public function findByContent(Content $content): array
    {
        return $this->createQueryBuilder('si')
            ->andWhere('si.content = :content')
            ->setParameter('content', $content)
            ->getQuery()
            ->getResult();
    }

    /**
     * Highest display order currently used in a consumer's slider, or 0.
     */
    public function maxDisplayOrderForConsumer(User $consumer): int
    {
        return (int) $this->createQueryBuilder('si')
            ->select('MAX(si.displayOrder)')
            ->andWhere('si.consumer = :consumer')
            ->setParameter('consumer', $consumer)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
