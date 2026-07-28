<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Service;

use Moselwal\FA4T3\Domain\Model\AggregationRequest;
use Moselwal\FA4T3\Domain\Model\AggregationResult;
use Moselwal\FA4T3\Domain\Model\CurrentVisitors;
use Moselwal\FA4T3\Domain\Model\DashboardData;
use Moselwal\FA4T3\Domain\Model\DateRange;
use Moselwal\FA4T3\Domain\Model\EventAggregationResult;
use Moselwal\FA4T3\Exception\Fa4t3ApiException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final readonly class AnalyticsService
{
    public function __construct(
        private Fa4t3ApiClientInterface $apiClient,
        private FrontendInterface $cache,
        private ConfigurationService $configurationService,
    ) {}

    public function getDashboardData(string $siteId, DateRange $range, string $apiKey): DashboardData
    {
        $cacheKey = $this->buildCacheKey('dashboard', $siteId, $range);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        try {
            $request = AggregationRequest::fromDateRange($range);

            $aggregation = $this->apiClient->getAggregation($siteId, $request, $apiKey);

            $topPagesRequest = $request->withFieldGrouping('pathname')
                ->withSortBy('pageviews:desc')
                ->withLimit(10);
            $topPagesData = $this->apiClient->getAggregation($siteId, $topPagesRequest, $apiKey);

            $topReferrersRequest = $request->withFieldGrouping('referrer_hostname')
                ->withSortBy('visits:desc')
                ->withLimit(10);
            $topReferrersData = $this->apiClient->getAggregation($siteId, $topReferrersRequest, $apiKey);

            $currentVisitors = $this->apiClient->getCurrentVisitors($siteId, $apiKey, true);

            $dashboardData = new DashboardData(
                $aggregation,
                $topPagesData->getGroupedData() ?? [],
                $topReferrersData->getGroupedData() ?? [],
                $currentVisitors
            );

            $this->cache->set(
                $cacheKey,
                $dashboardData,
                ['fa4t3_site_' . $siteId],
                $this->configurationService->getCacheDuration()
            );
            $this->setStaleCache($cacheKey, $dashboardData, $siteId);

            return $dashboardData;
        } catch (Fa4t3ApiException $e) {
            // Try stale cache
            $stale = $this->getStaleCache($cacheKey);
            if ($stale !== null) {
                return $stale;
            }

            return DashboardData::createError($e->getMessage());
        }
    }

    /**
     * @return EventAggregationResult[]
     */
    public function getEventOverview(string $siteId, DateRange $range, string $apiKey): array
    {
        $cacheKey = $this->buildCacheKey('events', $siteId, $range);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        try {
            $events = $this->apiClient->getEvents($siteId, $apiKey);
            $request = AggregationRequest::fromDateRange($range);
            $results = [];

            foreach ($events as $event) {
                $results[] = $this->apiClient->getEventAggregation(
                    $siteId,
                    $event->getName(),
                    $request,
                    $apiKey
                );
            }

            $this->cache->set(
                $cacheKey,
                $results,
                ['fa4t3_site_' . $siteId],
                $this->configurationService->getCacheDuration()
            );
            $this->setStaleCache($cacheKey, $results, $siteId);

            return $results;
        } catch (Fa4t3ApiException $e) {
            $stale = $this->getStaleCache($cacheKey);
            return $stale ?? [];
        }
    }

    public function getPageAnalytics(
        string $siteId,
        string $pathname,
        DateRange $range,
        string $apiKey,
        ?string $hostname = null,
    ): AggregationResult {
        $cacheKey = $this->buildCacheKey('page', $siteId, $range, $pathname . '|' . ($hostname ?? ''));
        $cached = $this->cache->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        try {
            $filters = [
                ['property' => 'pathname', 'operator' => 'is', 'value' => $pathname],
            ];
            if ($hostname !== null && $hostname !== '') {
                $filters[] = ['property' => 'hostname', 'operator' => 'is', 'value' => $hostname];
            }

            $request = AggregationRequest::fromDateRange($range)
                ->withoutDateGrouping()
                ->withFilters($filters);

            $result = $this->apiClient->getAggregation($siteId, $request, $apiKey);

            $this->cache->set(
                $cacheKey,
                $result,
                ['fa4t3_site_' . $siteId],
                $this->configurationService->getCacheDuration()
            );
            $this->setStaleCache($cacheKey, $result, $siteId);

            return $result;
        } catch (Fa4t3ApiException $e) {
            $stale = $this->getStaleCache($cacheKey);
            if ($stale !== null) {
                return $stale;
            }

            return AggregationResult::createError($e->getMessage());
        }
    }

    public function getCurrentVisitorCount(string $siteId, string $apiKey): int
    {
        $cacheKey = 'fa4t3_current_' . md5($siteId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== false) {
            return (int)$cached;
        }

        try {
            $visitors = $this->apiClient->getCurrentVisitors($siteId, $apiKey);
            $count = $visitors->getTotal();

            $this->cache->set($cacheKey, $count, ['fa4t3_site_' . $siteId], 60);

            return $count;
        } catch (Fa4t3ApiException) {
            return 0;
        }
    }

    public function flushCacheForSite(string $siteId): void
    {
        $this->cache->flushByTag('fa4t3_site_' . $siteId);
    }

    private function buildCacheKey(string $prefix, string $siteId, DateRange $range, string $extra = ''): string
    {
        $parts = [
            $prefix,
            $siteId,
            $range->getPreset(),
            $range->getFrom()->format('Ymd'),
            $range->getTo()->format('Ymd'),
        ];

        if ($extra !== '') {
            $parts[] = $extra;
        }

        return 'fa4t3_' . md5(implode('_', $parts));
    }

    /**
     * Store a stale copy with a longer TTL for graceful degradation.
     */
    private function setStaleCache(string $cacheKey, mixed $data, string $siteId): void
    {
        $staleKey = $cacheKey . '_stale';
        // Stale cache lives 10x longer than normal cache
        $staleTtl = $this->configurationService->getCacheDuration() * 10;
        $this->cache->set($staleKey, $data, ['fa4t3_site_' . $siteId], $staleTtl);
    }

    private function getStaleCache(string $cacheKey): mixed
    {
        $staleKey = $cacheKey . '_stale';
        $stale = $this->cache->get($staleKey);
        return $stale !== false ? $stale : null;
    }
}
