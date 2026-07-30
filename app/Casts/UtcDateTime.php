<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Always stores a datetime attribute as an explicit-offset UTC instant
 * ('Y-m-d H:i:sP', e.g. "2026-07-30 16:30:00+00:00"), regardless of what
 * timezone the assigned value carried. Returned as a Carbon in the app's
 * display timezone, matching prior display behavior.
 *
 * Guarantees a single canonical stored representation — no more "was this
 * value written from a local-offset Carbon or a UTC one?" ambiguity — so any
 * ad-hoc query comparison against a column using this cast only needs to
 * format its own side the same way (see self::forQuery()), never guess the
 * offset the stored side used.
 */
class UtcDateTime implements CastsAttributes
{
    public const STORAGE_FORMAT = 'Y-m-d H:i:sP';

    /**
     * Disables Eloquent's "assigned object == get() result" caching shortcut
     * (Illuminate\Database\Eloquent\Concerns\HasAttributes::setClassCastableAttribute()).
     * That optimization caches whatever object was assigned via set() and
     * returns it verbatim from get() without calling this class again — which
     * is wrong here, since set() accepts any DateTimeInterface (including a
     * bare, non-UTC \DateTime) while get() must always return the normalized
     * UTC Carbon. Without this flag, reading an attribute right after
     * assigning a plain \DateTime/Carbon to it would silently skip
     * normalization and hand back the original, un-normalized object.
     */
    public bool $withoutObjectCaching = true;

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->setTimezone(config('app.timezone', 'UTC'));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->utc()->format(self::STORAGE_FORMAT);
    }

    /**
     * Format an arbitrary datetime identically to how this cast stores its
     * column, for use as the comparison-side value in a query builder
     * where() against that column. Necessary because the query grammar
     * formats bound Carbon/DateTime parameters using its own fixed format,
     * not this cast's — so a bare Carbon passed straight into where() would
     * not textually match the stored value even when it represents the same
     * instant.
     */
    public static function forQuery(\DateTimeInterface $value): string
    {
        return Carbon::instance($value)->utc()->format(self::STORAGE_FORMAT);
    }
}
