<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Service;

use Moselwal\FA4T3\Domain\Model\AggregationRequest;
use Moselwal\FA4T3\Domain\Model\AggregationResult;
use Moselwal\FA4T3\Domain\Model\CurrentVisitors;
use Moselwal\FA4T3\Domain\Model\DashboardData;
use Moselwal\FA4T3\Domain\Model\DateRange;
use Moselwal\FA4T3\Domain\Model\EventAggregationResult;
use Moselwal\FA4T3\Domain\Model\Fa4t3Event;
use Moselwal\FA4T3\Exception\Fa4t3ApiException;
use Moselwal\FA4T3\Service\AnalyticsService;
use Moselwal\FA4T3\Service\ConfigurationService;
use Moselwal\FA4T3\Service\Fa4t3ApiClientInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Die Wege, die der bestehende Test auslaesst: der erfolgreiche Abruf und der
 * veraltete Zwischenspeicher.
 *
 * AnalyticsServiceTest deckt die Fehlerfaelle ab. Was fehlte, war der
 * Normalbetrieb — und ausgerechnet dort sitzt die Logik, die man beim
 * Weiterentwickeln versehentlich aendert.
 */
class AnalyticsServiceHappyPathTest extends TestCase
{
    #[Test]
    public function theDashboardIsAssembledFromFourSeparateCalls(): void
    {
        $aggregation = $this->aggregation(visits: 100);
        $topPages = $this->aggregation(grouped: [['pathname' => '/', 'pageviews' => 50]]);
        $topReferrers = $this->aggregation(grouped: [['referrer_hostname' => 'example.com', 'visits' => 20]]);

        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->method('getAggregation')->willReturnOnConsecutiveCalls(
            $aggregation,
            $topPages,
            $topReferrers,
        );
        $apiClient->method('getCurrentVisitors')->willReturn(new CurrentVisitors(7));

        $result = $this->service($apiClient, $this->coldCache())
            ->getDashboardData('SITE123', DateRange::fromPreset('30d'), 'api-key');

        self::assertFalse($result->hasError());
        self::assertSame(100, $result->getAggregation()->getVisits());
        self::assertSame([['pathname' => '/', 'pageviews' => 50]], $result->getTopPages());
        self::assertSame([['referrer_hostname' => 'example.com', 'visits' => 20]], $result->getTopReferrers());
        self::assertSame(7, $result->getCurrentVisitors()->getTotal());
    }

    /**
     * Die Feldgruppierung entscheidet, wonach die API zusammenfasst — und die
     * beiden Listen des Dashboards unterscheiden sich NUR darin.
     *
     * Ein vertauschtes Feld liefert weiterhin plausible Zahlen, nur unter der
     * falschen Ueberschrift: die Top-Seiten zeigten dann Hostnamen. Deshalb
     * wird hier die Anfrage geprueft und nicht bloss die Antwort.
     */
    #[Test]
    public function topPagesAndTopReferrersAskForDifferentGroupings(): void
    {
        $seen = [];

        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->method('getAggregation')->willReturnCallback(
            function (string $siteId, AggregationRequest $request) use (&$seen): AggregationResult {
                $seen[] = $request->getFieldGrouping();

                return $this->aggregation();
            },
        );
        $apiClient->method('getCurrentVisitors')->willReturn(new CurrentVisitors(0));

        $this->service($apiClient, $this->coldCache())
            ->getDashboardData('SITE123', DateRange::fromPreset('30d'), 'api-key');

        self::assertSame([null, 'pathname', 'referrer_hostname'], $seen);
    }

    /**
     * Fehlt der API die Gruppierung, wird daraus eine leere Liste — nicht null.
     *
     * Das Fluid-Template iteriert darueber; ein null waere dort ein Fehler zur
     * Laufzeit statt einer leeren Tabelle.
     */
    #[Test]
    public function missingGroupedDataBecomesAnEmptyListNotNull(): void
    {
        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->method('getAggregation')->willReturn($this->aggregation(grouped: null));
        $apiClient->method('getCurrentVisitors')->willReturn(new CurrentVisitors(0));

        $result = $this->service($apiClient, $this->coldCache())
            ->getDashboardData('SITE123', DateRange::fromPreset('30d'), 'api-key');

        self::assertSame([], $result->getTopPages());
        self::assertSame([], $result->getTopReferrers());
    }

    /**
     * Faellt die API aus, gewinnt der veraltete Zwischenspeicher gegen die
     * Fehlermeldung.
     *
     * Das ist die Entscheidung, die dieser Dienst trifft: lieber Zahlen von
     * gestern als gar keine. Sie darf nicht unbemerkt kippen — ein Dashboard,
     * das bei jedem API-Schluckauf auf "Fehler" springt, sieht aus wie ein
     * kaputter Dienst.
     */
    #[Test]
    public function staleDataBeatsAnErrorMessage(): void
    {
        $stale = new DashboardData(
            $this->aggregation(visits: 42),
            [],
            [],
            new CurrentVisitors(1),
        );

        $cache = $this->createMock(FrontendInterface::class);
        // Erster Zugriff: der normale Schluessel, leer. Zweiter: der
        // _stale-Schluessel, gefuellt.
        $cache->method('get')->willReturnCallback(
            static fn(string $key): mixed => \str_ends_with($key, '_stale') ? $stale : false,
        );

        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->method('getAggregation')->willThrowException(new Fa4t3ApiException('502'));

        $result = $this->service($apiClient, $cache)
            ->getDashboardData('SITE123', DateRange::fromPreset('30d'), 'api-key');

        self::assertFalse($result->hasError(), 'stale data must not be presented as an error');
        self::assertSame(42, $result->getAggregation()->getVisits());
    }

    #[Test]
    public function theEventOverviewAsksTheApiOncePerEvent(): void
    {
        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->method('getEvents')->willReturn([
            new Fa4t3Event('e1', 'signup', 'SITE123', new \DateTimeImmutable()),
            new Fa4t3Event('e2', 'purchase', 'SITE123', new \DateTimeImmutable()),
        ]);
        $apiClient->expects(self::exactly(2))
            ->method('getEventAggregation')
            ->willReturn(new EventAggregationResult('signup', 1, 1, 0));

        $results = $this->service($apiClient, $this->coldCache())
            ->getEventOverview('SITE123', DateRange::fromPreset('30d'), 'api-key');

        self::assertCount(2, $results);
    }

    /**
     * Faellt die API beim Ereignis-Ueberblick aus und es gibt keinen veralteten
     * Stand, kommt eine LEERE Liste zurueck — kein null.
     *
     * Der Rueckgabetyp sagt array; ein null waere ein TypeError beim Aufrufer,
     * also ein Ausfall statt einer leeren Tabelle.
     */
    #[Test]
    public function theEventOverviewFallsBackToAnEmptyList(): void
    {
        $apiClient = $this->createMock(Fa4t3ApiClientInterface::class);
        $apiClient->method('getEvents')->willThrowException(new Fa4t3ApiException('down'));

        $results = $this->service($apiClient, $this->coldCache())
            ->getEventOverview('SITE123', DateRange::fromPreset('30d'), 'api-key');

        self::assertSame([], $results);
    }

    /**
     * @param array<int, array<string, mixed>>|null $grouped
     */
    private function aggregation(int $visits = 0, ?array $grouped = null): AggregationResult
    {
        return new AggregationResult(
            visits: $visits,
            uniques: $visits,
            pageviews: $visits,
            avgDuration: 0.0,
            bounceRate: 0.0,
            dateFrom: new \DateTimeImmutable('2026-01-01'),
            dateTo: new \DateTimeImmutable('2026-01-31'),
            groupedData: $grouped,
        );
    }

    private function coldCache(): FrontendInterface
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);

        return $cache;
    }

    private function service(Fa4t3ApiClientInterface $apiClient, FrontendInterface $cache): AnalyticsService
    {
        $extConfig = $this->createMock(ExtensionConfiguration::class);
        $extConfig->method('get')->willReturnCallback(
            static fn(string $ext, string $key): mixed => 'cacheDuration' === $key ? 300 : null,
        );

        return new AnalyticsService($apiClient, $cache, new ConfigurationService($extConfig));
    }
}
