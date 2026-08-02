<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Service;

use GuzzleHttp\Psr7\Response;
use Moselwal\FA4T3\Domain\Model\AggregationRequest;
use Moselwal\FA4T3\Service\Fa4t3ApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Das Zusammenrechnen der API-Antwort und die Ereignis-Aggregation.
 *
 * Fa4t3ApiClientTest deckt Verbindung, Fehlercodes und das einfache Parsen ab.
 * Hier geht es um die Arithmetik dazwischen — Summen und Mittelwerte ueber die
 * Zeilen, die Fathom zurueckgibt. Sie ist der Teil, der falsch sein kann, ohne
 * dass etwas abbricht: eine falsche Summe sieht aus wie ein ruhiger Tag.
 */
class Fa4t3ApiClientAggregationTest extends TestCase
{
    /**
     * Mehrere Zeilen werden aufsummiert, nicht die erste genommen.
     *
     * Bei gruppierten Abfragen liefert Fathom eine Zeile je Gruppe. Wer hier
     * $rows[0] liest statt zu summieren, bekommt plausible, aber viel zu
     * kleine Gesamtzahlen — und niemand rechnet nach.
     */
    #[Test]
    public function theTotalsAreSummedAcrossAllRows(): void
    {
        $client = $this->clientReturning([
            ['visits' => 10, 'uniques' => 8, 'pageviews' => 30, 'avg_duration' => 20.0, 'bounce_rate' => 0.5],
            ['visits' => 5, 'uniques' => 4, 'pageviews' => 12, 'avg_duration' => 40.0, 'bounce_rate' => 0.1],
        ]);

        $result = $client->getAggregation('SITE1', $this->grouped(), 'key');

        self::assertSame(15, $result->getVisits());
        self::assertSame(12, $result->getUniques());
        self::assertSame(42, $result->getPageviews());
    }

    /**
     * Dauer und Absprungrate werden gemittelt, nicht summiert.
     *
     * Der Unterschied faellt bei einer Zeile nicht auf und wird ab zwei Zeilen
     * absurd: aus 20 und 40 Sekunden wuerden 60 Sekunden mittlere Verweildauer.
     */
    #[Test]
    public function durationAndBounceRateAreAveragedNotAdded(): void
    {
        $client = $this->clientReturning([
            ['visits' => 1, 'uniques' => 1, 'pageviews' => 1, 'avg_duration' => 20.0, 'bounce_rate' => 0.5],
            ['visits' => 1, 'uniques' => 1, 'pageviews' => 1, 'avg_duration' => 40.0, 'bounce_rate' => 0.1],
        ]);

        $result = $client->getAggregation('SITE1', $this->grouped(), 'key');

        self::assertSame(30.0, $result->getAvgDuration());
        self::assertEqualsWithDelta(0.3, $result->getBounceRate(), 0.0001);
    }

    /**
     * Eine leere Antwort ergibt Nullen — und keine Division durch null.
     *
     * avgField() teilt durch die Zeilenzahl. Ohne die Abfrage auf ein leeres
     * Feld waere eine Site ohne Zugriffe kein leeres Dashboard, sondern ein
     * Fehler.
     */
    #[Test]
    public function anEmptyResponseYieldsZeroesWithoutDividingByZero(): void
    {
        $result = $this->clientReturning([])->getAggregation('SITE1', $this->grouped(), 'key');

        self::assertSame(0, $result->getVisits());
        self::assertSame(0.0, $result->getAvgDuration());
        self::assertSame(0.0, $result->getBounceRate());
    }

    /**
     * Fehlende Felder zaehlen als null, nicht als Fehler.
     *
     * Fathom laesst Felder weg, nach denen nicht gefragt wurde. Ein
     * undefined-index waere hier ein Ausfall, obwohl die Antwort in Ordnung
     * ist.
     */
    #[Test]
    public function missingFieldsCountAsZero(): void
    {
        $result = $this->clientReturning([['visits' => 7]])
            ->getAggregation('SITE1', $this->grouped(), 'key');

        self::assertSame(7, $result->getVisits());
        self::assertSame(0, $result->getPageviews());
        self::assertSame(0.0, $result->getAvgDuration());
    }

    #[Test]
    public function aGroupedRequestKeepsTheRowsForTheCaller(): void
    {
        $rows = [
            ['pathname' => '/', 'pageviews' => 30],
            ['pathname' => '/kontakt', 'pageviews' => 12],
        ];

        $result = $this->clientReturning($rows)
            ->getAggregation('SITE1', $this->request()->withFieldGrouping('pathname'), 'key');

        self::assertSame($rows, $result->getGroupedData());
    }

    #[Test]
    public function theEventAggregationReadsTheFirstRow(): void
    {
        $client = $this->clientReturning([
            ['conversions' => 12, 'unique_conversions' => 10, 'value' => 4999],
        ]);

        $result = $client->getEventAggregation('SITE1', 'signup', $this->request(), 'key');

        self::assertSame('signup', $result->getEventName());
        self::assertSame(12, $result->getConversions());
        self::assertSame(10, $result->getUniqueConversions());
        self::assertSame(4999, $result->getValue());
        self::assertSame('49.99', $result->getFormattedValue());
    }

    /**
     * Ein Ereignis ohne Konversionen ist kein Fehler, sondern die haeufigste
     * Antwort — Fathom liefert dann eine leere Liste.
     */
    #[Test]
    public function anEventWithoutConversionsComesBackAsZeroes(): void
    {
        $result = $this->clientReturning([])
            ->getEventAggregation('SITE1', 'signup', $this->request(), 'key');

        self::assertSame('signup', $result->getEventName());
        self::assertSame(0, $result->getConversions());
        self::assertSame(0, $result->getValue());
    }

    /**
     * Eine Anfrage MIT Gruppierung.
     *
     * Nur dann summiert der Client ueber die Zeilen — ohne Gruppierung liest er
     * die erste Zeile und die Rechenhelfer laufen gar nicht. Das ist der
     * Unterschied, an dem ein Test sonst unbemerkt am Ziel vorbeigeht: er
     * bestuende, ohne die Arithmetik je beruehrt zu haben.
     */
    private function grouped(): AggregationRequest
    {
        return new AggregationRequest(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            dateGrouping: 'day',
        );
    }

    private function request(): AggregationRequest
    {
        return new AggregationRequest(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function clientReturning(array $rows): Fa4t3ApiClient
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn(
            new Response(200, [], (string) \json_encode($rows)),
        );

        return new Fa4t3ApiClient($requestFactory);
    }
}
