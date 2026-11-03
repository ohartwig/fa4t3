<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AUD-078-F01: the page exclusion reads the page id from the `routing`
 * attribute, which is PageArguments only after page-resolver has run.
 */
final class RequestMiddlewareOrderTest extends TestCase
{
    #[Test]
    public function theTrackingMiddlewareRunsAfterThePageResolver(): void
    {
        $config = require __DIR__ . '/../../../Configuration/RequestMiddlewares.php';
        $entry = $config['frontend']['moselwal/fa4t3/tracking-script'];

        self::assertContains('typo3/cms-frontend/page-resolver', $entry['after']);
        self::assertNotContains('typo3/cms-frontend/page-resolver', $entry['before'] ?? []);
    }
}
