<?php

declare(strict_types=1);

namespace WpConsulting\TouchIdBundle\Contract;

interface TouchIdUserRepositoryInterface
{
    public function findOneForTouchIdByEmail(string $email): ?TouchIdUserInterface;
}
