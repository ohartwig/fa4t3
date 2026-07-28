<?php

declare(strict_types=1);

namespace Moselwal\FA4T3\Domain\Model;

final readonly class DateRange
{
    private function __construct(
        private string $preset,
        private \DateTimeImmutable $from,
        private \DateTimeImmutable $to,
        private string $dateGrouping,
    ) {}

    /**
     * @param string $preset One of: today, 7d, 30d, month, 90d, year, custom
     */
    public static function fromPreset(string $preset): self
    {
        $now = new \DateTimeImmutable('now');
        $today = $now->setTime(0, 0, 0);

        return match ($preset) {
            'today' => new self(preset: $preset, from: $today, to: $now, dateGrouping: 'hour'),
            '7d' => new self(preset: $preset, from: $today->modify('-7 days'), to: $now, dateGrouping: 'day'),
            '30d' => new self(preset: $preset, from: $today->modify('-30 days'), to: $now, dateGrouping: 'day'),
            'month' => new self(
                preset: $preset,
                from: $today->modify('first day of last month'),
                to: $today->modify('last day of last month')->setTime(23, 59, 59),
                dateGrouping: 'day',
            ),
            '90d' => new self(preset: $preset, from: $today->modify('-90 days'), to: $now, dateGrouping: 'month'),
            'year' => new self(preset: $preset, from: $today->modify('-1 year'), to: $now, dateGrouping: 'month'),
            default => new self(preset: '30d', from: $today->modify('-30 days'), to: $now, dateGrouping: 'day'),
        };
    }

    public static function fromCustom(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        $diffDays = $from->diff($to)->days;

        $grouping = match (true) {
            $diffDays <= 1 => 'hour',
            $diffDays <= 90 => 'day',
            default => 'month',
        };

        return new self(preset: 'custom', from: $from, to: $to, dateGrouping: $grouping);
    }

    public function getPreset(): string
    {
        return $this->preset;
    }

    public function getFrom(): \DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): \DateTimeImmutable
    {
        return $this->to;
    }

    public function getDateGrouping(): string
    {
        return $this->dateGrouping;
    }
}
