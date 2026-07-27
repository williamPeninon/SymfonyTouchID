<?php

declare(strict_types=1);

namespace WpConsulting\PasskeyBundle\Contract;

use Symfony\Component\Security\Core\User\UserInterface;

interface PasskeyUserInterface
{
    public function getUserId(): mixed;

    public function getUserName(): ?string;

    public function getUserDisplayName(): string;
}
