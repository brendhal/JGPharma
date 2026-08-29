<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The stretch of time a printable report covers.
 *
 * Every report is asked for the same way — a period and any date inside it —
 * so the resolving lives here rather than being written out again in each
 * controller. A date that cannot be parsed falls back to today rather than
 * erroring: a mistyped URL should still print something.
 */
final class ReportPeriod
{
    /** @var array<int, string> */
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    private function __construct(
        public readonly string $period,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $heading,
    ) {}

    /**
     * @param  array<int, string>|null  $allowed  the periods this report offers
     */
    public static function fromRequest(Request $request, ?array $allowed = null, string $fallback = 'daily'): self
    {
        return self::make(
            $request->string('period')->lower()->toString(),
            $request->string('date')->toString(),
            $allowed,
            $fallback,
        );
    }

    /**
     * Not every report offers every period — a collections sheet is printed
     * to withdraw against, which is a weekly-or-longer errand. A period the
     * report does not offer falls back rather than erroring, so an old
     * bookmark still prints something sensible.
     *
     * @param  array<int, string>|null  $allowed
     */
    public static function make(
        string $period,
        ?string $date = null,
        ?array $allowed = null,
        string $fallback = 'daily',
    ): self {
        $allowed = array_values(array_intersect($allowed ?? self::PERIODS, self::PERIODS));

        if ($allowed === []) {
            $allowed = self::PERIODS;
        }

        if (! in_array($period, $allowed, true)) {
            $period = in_array($fallback, $allowed, true) ? $fallback : $allowed[0];
        }

        $anchor = rescue(
            fn (): CarbonImmutable => CarbonImmutable::parse((string) $date),
            CarbonImmutable::now(),
            report: false,
        );

        [$from, $to, $heading] = match ($period) {
            'weekly' => [
                $anchor->startOfWeek(),
                $anchor->endOfWeek(),
                'Week of '.$anchor->startOfWeek()->format('d M Y'),
            ],
            'monthly' => [
                $anchor->startOfMonth(),
                $anchor->endOfMonth(),
                $anchor->format('F Y'),
            ],
            'yearly' => [
                $anchor->startOfYear(),
                $anchor->endOfYear(),
                $anchor->format('Y'),
            ],
            default => [
                $anchor->startOfDay(),
                $anchor->endOfDay(),
                $anchor->format('l, d F Y'),
            ],
        };

        return new self($period, $from, $to, $heading);
    }

    /** A year reads as twelve months; anything shorter reads as days. */
    public function bucketsByMonth(): bool
    {
        return $this->period === 'yearly';
    }

    /** "Daily", "Weekly" — the period as it heads a report. */
    public function label(): string
    {
        return ucfirst($this->period);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(): array
    {
        return [$this->from, $this->to];
    }
}
