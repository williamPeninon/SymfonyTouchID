<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;
use WpConsulting\TouchIdBundle\Repository\WebAuthnCredentialRepository;

#[ORM\Entity(repositoryClass: WebAuthnCredentialRepository::class)]
#[ORM\Table(name: 'web_authn_credential')]
#[ORM\UniqueConstraint(name: 'UNIQ_WEBAUTHN_CREDENTIAL_ID', fields: ['credentialId'])]
class WebAuthnCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TouchIdUserInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TouchIdUserInterface $user = null;

    /** Base64url-encoded credential ID */
    #[ORM\Column(length: 512)]
    private string $credentialId = '';

    /** PEM public key */
    #[ORM\Column(type: 'text')]
    private string $publicKey = '';

    #[ORM\Column]
    private int $signCount = 0;

    #[ORM\Column(length: 100)]
    private string $name = 'Touch ID';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $aaguid = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?TouchIdUserInterface
    {
        return $this->user;
    }

    public function setUser(?TouchIdUserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function setCredentialId(string $credentialId): static
    {
        $this->credentialId = $credentialId;

        return $this;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function setPublicKey(string $publicKey): static
    {
        $this->publicKey = $publicKey;

        return $this;
    }

    public function getSignCount(): int
    {
        return $this->signCount;
    }

    public function setSignCount(int $signCount): static
    {
        $this->signCount = $signCount;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getAaguid(): ?string
    {
        return $this->aaguid;
    }

    public function setAaguid(?string $aaguid): static
    {
        $this->aaguid = $aaguid;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }
}
