<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Tests\Unit\Domain\Model;

use Moselwal\FA4T3\Domain\Model\AggregationRequest;
use Moselwal\FA4T3\Domain\Model\DateRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AggregationRequestTest extends TestCase
{
    #[Test]
    public function fromDateRangeTakesOverBoundsAndGrouping(): void
    {
        $range = DateRange::fromPreset('7d');

        $request = AggregationRequest::fromDateRange($range, 'Europe/Berlin');

        self::assertSame($range->getFrom(), $request->getDateFrom());
        self::assertSame($range->getTo(), $request->getDateTo());
        self::assertSame('day', $request->getDateGrouping());
        self::assertSame('Europe/Berlin', $request->getTimezone());
    }

    /**
     * Die Zeitzone entscheidet, in welchem Tag ein Ereignis landet. Fiele sie
     * still auf UTC zurueck, waeren die Tagesgrenzen im Dashboard um bis zu
     * zwei Stunden verschoben — sichtbar erst als "gestern hatte mehr
     * Zugriffe", nicht als Fehler.
     */
    #[Test]
    public function theTimezoneDefaultsToUtc(): void
    {
        $request = new AggregationRequest(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );

        self::assertSame('UTC', $request->getTimezone());
    }

    #[Test]
    public function everythingOptionalStartsEmpty(): void
    {
        $request = new AggregationRequest(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );

        self::assertNull($request->getDateGrouping());
        self::assertNull($request->getFieldGrouping());
        self::assertNull($request->getFilters());
        self::assertNull($request->getSortBy());
        self::assertNull($request->getLimit());
    }

    /**
     * Das Objekt ist unveraenderlich, und die with*-Methoden muessen das auch
     * sein. Eine, die stattdessen das Original anfasst, faellt erst dort auf,
     * wo dieselbe Anfrage zweimal verwendet wird — und dann als falsche Zahl,
     * nicht als Fehler.
     */
    #[Test]
    public function theWithMethodsLeaveTheOriginalAlone(): void
    {
        $original = new AggregationRequest(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            dateGrouping: 'day',
        );

        $original->withLimit(10);
        $original->withSortBy('pageviews');
        $original->withFilters([['field' => 'path', 'value' => '/']]);

        self::assertNull($original->getLimit());
        self::assertNull($original->getSortBy());
        self::assertNull($original->getFilters());
        self::assertSame('day', $original->getDateGrouping());
    }

    #[Test]
    public function withLimitAndSortByCarryEverythingElseAlong(): void
    {
        $request = (new AggregationRequest(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            timezone: 'Europe/Berlin',
            dateGrouping: 'day',
        ))->withLimit(25)->withSortBy('pageviews');

        self::assertSame(25, $request->getLimit());
        self::assertSame('pageviews', $request->getSortBy());
        self::assertSame('Europe/Berlin', $request->getTimezone());
        self::assertSame('day', $request->getDateGrouping());
    }

    #[Test]
    public function withFiltersReplacesRatherThanMerges(): void
    {
        $request = (new AggregationRequest(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        ))
            ->withFilters([['field' => 'path', 'value' => '/a']])
            ->withFilters([['field' => 'path', 'value' => '/b']]);

        self::assertSame([['field' => 'path', 'value' => '/b']], $request->getFilters());
    }

    #[Test]
    public function withoutDateGroupingDropsTheGrouping(): void
    {
        $request = (new AggregationRequest(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            dateGrouping: 'day',
        ))->withoutDateGrouping();

        self::assertNull($request->getDateGrouping());
    }

    /**
     * Der Punkt, an dem man sich verrechnet: withFieldGrouping() setzt die
     * Datumsgruppierung MIT zurueck.
     *
     * Das ist Absicht und keine Nachlaessigkeit — die Fathom-API gruppiert
     * entweder nach Datum oder nach Feld, nicht nach beidem. Wer die Zeile
     * spaeter "vervollstaendigt", indem er dateGrouping durchreicht, bekommt
     * eine Antwort, die zwei Dimensionen kreuzt, und Summen, die nicht mehr
     * zur Gesamtzahl passen. Deshalb steht es als eigener Test da und nicht
     * nur als Kommentar.
     */
    #[Test]
    public function withFieldGroupingAlsoClearsTheDateGrouping(): void
    {
        $request = (new AggregationRequest(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            dateGrouping: 'day',
        ))->withFieldGrouping('pathname');

        self::assertSame('pathname', $request->getFieldGrouping());
        self::assertNull($request->getDateGrouping(), 'date and field grouping are mutually exclusive');
    }
}
