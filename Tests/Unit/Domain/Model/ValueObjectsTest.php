<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Domain\Model;

use Moselwal\FA4T3\Domain\Model\AggregationResult;
use Moselwal\FA4T3\Domain\Model\ConnectionResult;
use Moselwal\FA4T3\Domain\Model\CurrentVisitors;
use Moselwal\FA4T3\Domain\Model\DashboardData;
use Moselwal\FA4T3\Domain\Model\EventAggregationResult;
use Moselwal\FA4T3\Domain\Model\Fa4t3Event;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Die kleinen Wertobjekte des Dashboards.
 *
 * Zusammen in einer Datei, weil sie zusammen gehoeren und einzeln je drei
 * Zeilen ergaeben. Was hier geprueft wird, ist nicht "gibt der Getter zurueck,
 * was der Konstruktor bekam" — das ist bei readonly-Klassen ohnehin so —,
 * sondern die Stellen, an denen gerechnet oder entschieden wird.
 */
class ValueObjectsTest extends TestCase
{
    /**
     * getFormattedValue() rechnet Cent in Euro und rundet auf zwei Stellen.
     *
     * Der Wert wandert unformatiert ins Dashboard, wenn hier jemand die
     * Division entfernt — aus 1234 Cent werden dann "1,234.00" statt "12.34".
     * Ein Faktor 100 in einer Geldanzeige faellt niemandem sofort auf, wenn er
     * die richtige Groessenordnung erwartet.
     */
    #[Test]
    public function theEventValueIsCentsAndComesOutAsCurrency(): void
    {
        $event = new EventAggregationResult('signup', 12, 10, 1234);

        self::assertSame('12.34', $event->getFormattedValue());
        self::assertSame(1234, $event->getValue(), 'the raw value stays in cents');
    }

    #[Test]
    public function largeEventValuesGetThousandSeparators(): void
    {
        $event = new EventAggregationResult('purchase', 1, 1, 123456789);

        self::assertSame('1,234,567.89', $event->getFormattedValue());
    }

    #[Test]
    public function aZeroValueIsStillFormattedAsCurrency(): void
    {
        $event = new EventAggregationResult('view', 5, 5, 0);

        self::assertSame('0.00', $event->getFormattedValue());
    }

    #[Test]
    public function theEventCountsAreKeptApart(): void
    {
        $event = new EventAggregationResult('signup', 12, 10, 0);

        self::assertSame('signup', $event->getEventName());
        self::assertSame(12, $event->getConversions());
        self::assertSame(10, $event->getUniqueConversions(), 'unique conversions are not the same as conversions');
    }

    #[Test]
    public function currentVisitorsMayComeWithoutBreakdowns(): void
    {
        $visitors = new CurrentVisitors(7);

        self::assertSame(7, $visitors->getTotal());
        self::assertNull($visitors->getTopPages());
        self::assertNull($visitors->getTopReferrers());
    }

    #[Test]
    public function currentVisitorsPassBreakdownsThrough(): void
    {
        $pages = [['pathname' => '/', 'total' => 3]];
        $referrers = [['referrer' => 'https://example.com', 'total' => 2]];

        $visitors = new CurrentVisitors(7, $pages, $referrers);

        self::assertSame($pages, $visitors->getTopPages());
        self::assertSame($referrers, $visitors->getTopReferrers());
    }

    #[Test]
    public function aConnectionResultSaysWhetherItWorkedAndWhy(): void
    {
        $ok = new ConnectionResult(true, 'Verbindung steht');
        $bad = new ConnectionResult(false, 'API-Schluessel abgelehnt');

        self::assertTrue($ok->isSuccess());
        self::assertSame('Verbindung steht', $ok->getMessage());
        self::assertFalse($bad->isSuccess());
        self::assertSame('API-Schluessel abgelehnt', $bad->getMessage());
    }

    #[Test]
    public function anEventCarriesItsIdentityAndSite(): void
    {
        $created = new \DateTimeImmutable('2026-01-15 10:00:00');
        $event = new Fa4t3Event('evt_1', 'Newsletter', 'SITE1', $created);

        self::assertSame('evt_1', $event->getId());
        self::assertSame('Newsletter', $event->getName());
        self::assertSame('SITE1', $event->getSiteId());
        self::assertSame($created, $event->getCreatedAt());
    }

    /**
     * Ein Fehler-Dashboard muss durchweg leer sein, nicht halb gefuellt.
     *
     * createError() baut alle Bestandteile neu — Aggregation, Listen,
     * Besucherzahl. Bliebe eine davon aus einem frueheren Aufruf stehen, zeigte
     * das Dashboard im Fehlerfall alte Zahlen neben einer Fehlermeldung, und
     * das ist schlimmer als gar keine Zahl.
     */
    #[Test]
    public function anErrorDashboardIsEmptyThroughout(): void
    {
        $data = DashboardData::createError('Fathom nicht erreichbar');

        self::assertTrue($data->hasError());
        self::assertSame('Fathom nicht erreichbar', $data->getErrorMessage());
        self::assertSame([], $data->getTopPages());
        self::assertSame([], $data->getTopReferrers());
        self::assertSame([], $data->getEvents());
        self::assertSame(0, $data->getCurrentVisitors()->getTotal());
        self::assertTrue($data->getAggregation()->hasError(), 'the aggregation must carry the error too');
    }

    #[Test]
    public function aRegularDashboardReportsNoError(): void
    {
        $data = new DashboardData(
            aggregation: new AggregationResult(
                visits: 10,
                uniques: 8,
                pageviews: 25,
                avgDuration: 30.0,
                bounceRate: 0.4,
                dateFrom: new \DateTimeImmutable('2026-01-01'),
                dateTo: new \DateTimeImmutable('2026-01-31'),
            ),
            topPages: [['pathname' => '/']],
            topReferrers: [],
            currentVisitors: new CurrentVisitors(3),
        );

        self::assertFalse($data->hasError());
        self::assertNull($data->getErrorMessage());
        self::assertSame(10, $data->getAggregation()->getVisits());
        self::assertSame(3, $data->getCurrentVisitors()->getTotal());
        self::assertSame([], $data->getEvents(), 'events default to empty, not null');
    }
}
