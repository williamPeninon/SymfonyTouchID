<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Contract;

use Symfony\Component\Security\Core\User\UserInterface;

interface TouchIdUserInterface extends UserInterface
{
    public function getUserId(): mixed;

    public function getUserName(): ?string;

    public function getUserDisplayName(): string;
}
