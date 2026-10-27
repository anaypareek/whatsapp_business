<?php
namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Instagram module timestamps are stored in UTC in the database (the app's display timezone is Asia/Kolkata).
 * Reading returns a Carbon in the app timezone for the same instant; JSON serialization is ISO-8601 "Z" (UTC).
 */
class UtcDateTime implements CastsAttributes
{
    public const FORMAT = 'Y-m-d H:i:s';

    public function get($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : Carbon::createFromFormat(self::FORMAT, $value, 'UTC')->setTimezone(config('app.timezone'));
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : Carbon::parse($value)->utc()->format(self::FORMAT);
    }

    /** "Now (+ seconds)" as a UTC string for query comparisons against UtcDateTime columns. */
    public static function now(int $plusSeconds = 0): string
    {
        return Carbon::now('UTC')->addSeconds($plusSeconds)->format(self::FORMAT);
    }
}
