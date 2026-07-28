<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Service;

use Moselwal\FA4T3\Domain\Model\AggregationResult;
use Moselwal\FA4T3\Domain\Model\CurrentVisitors;
use Moselwal\FA4T3\Domain\Model\DashboardData;
use Moselwal\FA4T3\Domain\Model\DateRange;
use Moselwal\FA4T3\Exception\Fa4t3ApiException;
use Moselwal\FA4T3\Service\AnalyticsService;
use Moselwal\FA4T3\Service\ConfigurationService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Moselwal\FA4T3\Service\Fa4t3ApiClientInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

class AnalyticsServiceTest extends TestCase
{
    /**
     * The real ConfigurationService over a mocked extension configuration.
     *
     * It is final and cannot be doubled, but it also does nothing but read
     * settings, so a double would only restate the defaults it already applies
     * — including the 300-second fallback these tests rely on.
     */
    private function configService(?int $cacheDuration = null): ConfigurationService
    {
        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturnCallback(
            static fn (string $ext, string $key) => 'cacheDuration' === $key ? $cacheDuration : null,
        );

        return new ConfigurationService($extConfig);
    }

    #[Test]
    public function getDashboardDataReturnsCachedDataOnCacheHit(): void
    {
        $cachedData = new DashboardData(
            new AggregationResult(100, 75, 200, 45.0, 0.35, new \DateTimeImmutable(), new \DateTimeImmutable()),
            [],
            [],
            new CurrentVisitors(5)
        );

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn($cachedData);

        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->expects(self::never())->method('getAggregation');

        $configService = $this->configService(cacheDuration: 300);

        $service = new AnalyticsService($apiClient, $cache, $configService);
        $result = $service->getDashboardData('SITE123', DateRange::fromPreset('30d'), 'api-key');

        self::assertSame(100, $result->getAggregation()->getVisits());
        self::assertFalse($result->hasError());
    }

    #[Test]
    public function getDashboardDataReturnsErrorOnApiFailureWithoutCache(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->method('getAggregation')->willThrowException(new Fa4t3ApiException('API down'));

        $configService = $this->configService(cacheDuration: 300);

        $service = new AnalyticsService($apiClient, $cache, $configService);
        $result = $service->getDashboardData('SITE123', DateRange::fromPreset('30d'), 'api-key');

        self::assertTrue($result->hasError());
        self::assertSame('API down', $result->getErrorMessage());
    }

    #[Test]
    public function getCurrentVisitorCountReturnsCachedValue(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(42);

        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->expects(self::never())->method('getCurrentVisitors');

        $configService = $this->configService();

        $service = new AnalyticsService($apiClient, $cache, $configService);

        self::assertSame(42, $service->getCurrentVisitorCount('SITE123', 'api-key'));
    }

    #[Test]
    public function getCurrentVisitorCountReturnsZeroOnApiFailure(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->method('getCurrentVisitors')->willThrowException(new Fa4t3ApiException('API down'));

        $configService = $this->configService();

        $service = new AnalyticsService($apiClient, $cache, $configService);

        self::assertSame(0, $service->getCurrentVisitorCount('SITE123', 'api-key'));
    }

    #[Test]
    public function getPageAnalyticsReturnsErrorResultOnFailure(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->method('getAggregation')->willThrowException(new Fa4t3ApiException('Timeout'));

        $configService = $this->configService();

        $service = new AnalyticsService($apiClient, $cache, $configService);
        $result = $service->getPageAnalytics('SITE123', '/about', DateRange::fromPreset('30d'), 'api-key');

        self::assertTrue($result->hasError());
    }

    #[Test]
    public function flushCacheForSiteFlushesCorrectTag(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())
            ->method('flushByTag')
            ->with('fa4t3_site_SITE123');

        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $configService = $this->configService();

        $service = new AnalyticsService($apiClient, $cache, $configService);
        $service->flushCacheForSite('SITE123');
    }
}
