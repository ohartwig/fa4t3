<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Functional;

use Moselwal\FA4T3\Service\BackendPageAccessChecker;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * AUD-078-F02: the page-data route answered for any page uid. The editor's
 * group mounts site A only, and page 3 lacks the show permission for the group.
 */
final class BackendPageAccessCheckerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['moselwal/fa4t3'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/page_access.csv');
    }

    #[Test]
    public function aMountRestrictedEditorMaySeeOnlyShowablePagesOfTheirOwnSite(): void
    {
        $this->setUpBackendUser(2);
        $checker = new BackendPageAccessChecker();

        self::assertTrue($checker->maySeePage(2));
        self::assertFalse($checker->maySeePage(11), 'site B is outside the mount');
        self::assertFalse($checker->maySeePage(3), 'no show permission for the group');
        self::assertFalse($checker->maySeePage(999), 'a page that does not exist');
        self::assertFalse($checker->maySeePage(0));
    }

    #[Test]
    public function anAdministratorMaySeeEveryExistingPage(): void
    {
        $this->setUpBackendUser(1);
        $checker = new BackendPageAccessChecker();

        self::assertTrue($checker->maySeePage(2));
        self::assertTrue($checker->maySeePage(11));
    }

    #[Test]
    public function withoutABackendUserNothingIsVisible(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertFalse((new BackendPageAccessChecker())->maySeePage(2));
    }
}
