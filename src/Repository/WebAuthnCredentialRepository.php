<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;
use WpConsulting\TouchIdBundle\Entity\WebAuthnCredential;

/**
 * @extends ServiceEntityRepository<WebAuthnCredential>
 */
class WebAuthnCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebAuthnCredential::class);
    }

    public function findOneByCredentialId(string $credentialId): ?WebAuthnCredential
    {
        return $this->findOneBy(['credentialId' => $credentialId]);
    }

    /**
     * @return list<WebAuthnCredential>
     */
    public function findByUser(TouchIdUserInterface $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
