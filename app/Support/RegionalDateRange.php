<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\Carbon;
use DateTimeZone;

class RegionalDateRange
{
    public static function timezone(): string
    {
        $timezone = (string) Setting::get('regional_timezone', 'America/Panama');

        return in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : 'America/Panama';
    }

    /**
     * Convert user-selected local calendar dates into UTC timestamp bounds.
     *
     * Database timestamps stay unchanged; this only prevents local report days
     * from being clipped by the server's UTC timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function utcBounds(string|Carbon $dateFrom, string|Carbon $dateTo): array
    {
        $timezone = self::timezone();
        $from = $dateFrom instanceof Carbon ? $dateFrom->toDateString() : $dateFrom;
        $to = $dateTo instanceof Carbon ? $dateTo->toDateString() : $dateTo;

        return [
            Carbon::parse($from, $timezone)->startOfDay()->utc(),
            Carbon::parse($to, $timezone)->endOfDay()->utc(),
        ];
    }
}
