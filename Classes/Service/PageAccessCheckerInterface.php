<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Service;

/**
 * Decides whether the current backend user may see a page.
 */
interface PageAccessCheckerInterface
{
    public function maySeePage(int $pageUid): bool;
}
