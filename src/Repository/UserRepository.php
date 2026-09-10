<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Auth\UserGateway;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserGateway
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findOneByVisitorId(string $visitorId): ?User
    {
        return $this->findOneBy(['visitorId' => $visitorId]);
    }

    public function save(User $user): void
    {
        $em = $this->getEntityManager();
        $em->persist($user);
        $em->flush();
    }
}
