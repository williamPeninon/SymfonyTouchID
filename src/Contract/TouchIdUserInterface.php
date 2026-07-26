<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Contract;

use Symfony\Component\Security\Core\User\UserInterface;

interface TouchIdUserInterface extends UserInterface
{
    public function getId(): ?int;

    public function getEmail(): ?string;

    public function getTouchIdDisplayName(): string;
}
