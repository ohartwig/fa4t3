<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Domain\Model;

use Moselwal\FA4T3\Domain\Model\AggregationResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AggregationResultTest extends TestCase
{
    #[Test]
    public function theFiguresComeBackOutAsTheyWentIn(): void
    {
        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-01-31');

        $result = new AggregationResult(
            visits: 120,
            uniques: 95,
            pageviews: 340,
            avgDuration: 42.5,
            bounceRate: 0.31,
            dateFrom: $from,
            dateTo: $to,
        );

        self::assertSame(120, $result->getVisits());
        self::assertSame(95, $result->getUniques());
        self::assertSame(340, $result->getPageviews());
        self::assertSame(42.5, $result->getAvgDuration());
        self::assertSame(0.31, $result->getBounceRate());
        self::assertSame($from, $result->getDateFrom());
        self::assertSame($to, $result->getDateTo());
    }

    /**
     * Ein Fehlerergebnis muss NULLEN liefern, nicht irgendetwas.
     *
     * Das Dashboard rechnet mit den Zahlen weiter. Kaeme hier ein alter Wert
     * oder ein Zufallswert durch, sähe der Ausfall aus wie ein schlechter Tag —
     * und niemand wuerde nach der Ursache suchen.
     */
    #[Test]
    public function anErrorResultIsAllZeroesAndSaysSo(): void
    {
        $result = AggregationResult::createError('Fathom antwortet nicht');

        self::assertTrue($result->hasError());
        self::assertSame('Fathom antwortet nicht', $result->getErrorMessage());
        self::assertSame(0, $result->getVisits());
        self::assertSame(0, $result->getUniques());
        self::assertSame(0, $result->getPageviews());
        self::assertSame(0.0, $result->getAvgDuration());
        self::assertSame(0.0, $result->getBounceRate());
    }

    #[Test]
    public function aRegularResultCarriesNoError(): void
    {
        $result = new AggregationResult(
            visits: 1,
            uniques: 1,
            pageviews: 1,
            avgDuration: 0.0,
            bounceRate: 0.0,
            dateFrom: new \DateTimeImmutable(),
            dateTo: new \DateTimeImmutable(),
        );

        self::assertFalse($result->hasError());
        self::assertNull($result->getErrorMessage());
    }

    #[Test]
    public function groupedDataIsOptionalAndPassedThroughUnchanged(): void
    {
        $grouped = [['date' => '2026-01-01', 'visits' => 5]];

        $withGrouping = new AggregationResult(
            visits: 5,
            uniques: 5,
            pageviews: 5,
            avgDuration: 0.0,
            bounceRate: 0.0,
            dateFrom: new \DateTimeImmutable(),
            dateTo: new \DateTimeImmutable(),
            groupedData: $grouped,
        );

        self::assertSame($grouped, $withGrouping->getGroupedData());
    }
}
