<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Metadata\Exception;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AccessDeniedException extends AccessDeniedHttpException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 403;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
