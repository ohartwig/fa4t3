<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The check the Page module itself applies: the page must lie inside one of the
 * user's web mounts and the user must hold the "show" permission on it
 * (AUD-078-F02).
 */
final class BackendPageAccessChecker implements PageAccessCheckerInterface
{
    public function maySeePage(int $pageUid): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || $pageUid <= 0) {
            return false;
        }

        if (!$backendUser->isInWebMount($pageUid)) {
            return false;
        }

        return BackendUtility::readPageAccess(
            $pageUid,
            $backendUser->getPagePermsClause(Permission::PAGE_SHOW),
        ) !== false;
    }
}
