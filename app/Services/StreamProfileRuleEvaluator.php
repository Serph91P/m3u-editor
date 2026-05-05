<?php

namespace App\Services;

use App\Models\StreamProfile;

class StreamProfileRuleEvaluator
{
    /**
     * Resolve an adaptive profile to the id of the transcoding profile that
     * should run for the given channel based on probed metadata.
     *
     * @param  StreamProfile  $adaptive  Profile with backend === 'adaptive'.
     * @param  array|null  $streamStats  Decoded contents of channels.stream_stats.
     * @return int|null Resolved transcoding profile id, or null when no rule
     *                  matches and no else fallback is set.
     */
    public function resolve(StreamProfile $adaptive, ?array $streamStats): ?int
    {
        $context = $this->buildContext($streamStats);

        foreach ($adaptive->rules ?? [] as $rule) {
            if ($this->ruleMatches($rule, $context)) {
                return isset($rule['stream_profile_id'])
                    ? (int) $rule['stream_profile_id']
                    : null;
            }
        }

        return $adaptive->else_stream_profile_id;
    }

    /**
     * Pass-through helper: returns non-adaptive profiles unchanged, resolves
     * adaptive ones against the supplied probe data. Use this at any call
     * site that has already picked a candidate profile (e.g., after walking
     * a channel → playlist fallback chain) and wants the concrete profile
     * to actually run.
     */
    public function unwrap(?StreamProfile $profile, ?array $streamStats): ?StreamProfile
    {
        if (! $profile || ! $profile->isAdaptive()) {
            return $profile;
        }

        $resolvedId = $this->resolve($profile, $streamStats);
        if (! $resolvedId) {
            return null;
        }

        static $cache = [];

        return $cache[$resolvedId] ??= StreamProfile::find($resolvedId);
    }

    /**
     * Public flattening helper so other features (e.g. conditional
     * Find & Replace) can reuse the exact same probe context shape used
     * by adaptive stream profile rules.
     *
     * @return array<string, mixed>
     */
    public function buildProbeContext(?array $streamStats): array
    {
        return $this->buildContext($streamStats);
    }

    /**
     * Evaluate a list of {field, op, value} conditions against probed
     * stream stats. Supports both "all" (AND, default) and "any" (OR)
     * match modes. Returns false when the condition list is empty so
     * callers can treat "no conditions" as "do not gate".
     *
     * @param  array<int, array<string, mixed>>  $conditions
     */
    public function matches(array $conditions, ?array $streamStats, string $mode = 'all'): bool
    {
        if (empty($conditions)) {
            return false;
        }

        $context = $this->buildContext($streamStats);
        $mode = strtolower($mode) === 'any' ? 'any' : 'all';

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $matched = $this->conditionMatches($condition, $context);

            if ($mode === 'any' && $matched) {
                return true;
            }
            if ($mode === 'all' && ! $matched) {
                return false;
            }
        }

        return $mode === 'all';
    }

    /**
     * Flatten the probe payload into a single dot-keyed map so condition
     * lookups become a flat array access. Picks the first video stream,
     * the first audio stream, and the format object — that's what users
     * write rules against.
     *
     * @return array<string, mixed>
     */
    private function buildContext(?array $streamStats): array
    {
        $context = [];
        $videoSeen = false;
        $audioSeen = false;
        $formatSeen = false;

        foreach ($streamStats ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! $videoSeen && isset($entry['stream']) && ($entry['stream']['codec_type'] ?? null) === 'video') {
                $stream = $entry['stream'];
                $context['video.codec_name'] = $stream['codec_name'] ?? null;
                $context['video.profile'] = $stream['profile'] ?? null;
                $context['video.height'] = $this->numeric($stream['height'] ?? null);
                $context['video.width'] = $this->numeric($stream['width'] ?? null);
                $context['video.bit_rate'] = $this->numeric($stream['bit_rate'] ?? null);
                $context['video.frame_rate'] = $this->parseFrameRate($stream['avg_frame_rate'] ?? null);
                $context['video.display_aspect_ratio'] = $stream['display_aspect_ratio'] ?? null;
                // Synthetic "1920x1080" string built from width × height. Lets
                // rule authors match resolution as one value instead of having
                // to combine width AND height conditions.
                $context['video.resolution'] = ($context['video.width'] !== null && $context['video.height'] !== null)
                    ? ((int) $context['video.width']).'x'.((int) $context['video.height'])
                    : null;
                $videoSeen = true;

                continue;
            }

            if (! $audioSeen && isset($entry['stream']) && ($entry['stream']['codec_type'] ?? null) === 'audio') {
                $stream = $entry['stream'];
                $context['audio.codec_name'] = $stream['codec_name'] ?? null;
                $context['audio.channels'] = $this->numeric($stream['channels'] ?? null);
                $context['audio.sample_rate'] = $this->numeric($stream['sample_rate'] ?? null);
                $audioSeen = true;

                continue;
            }

            if (! $formatSeen && isset($entry['format'])) {
                $context['format.format_name'] = $entry['format']['format_name'] ?? null;
                $formatSeen = true;
            }
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $context
     */
    private function ruleMatches(array $rule, array $context): bool
    {
        $conditions = $rule['conditions'] ?? [];
        if (empty($conditions)) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (! $this->conditionMatches($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $context
     */
    private function conditionMatches(array $condition, array $context): bool
    {
        $field = $condition['field'] ?? null;
        $op = $condition['op'] ?? null;
        if ($field === null || $op === null) {
            return false;
        }

        $actual = $context[$field] ?? null;
        $expected = $condition['value'] ?? null;

        // Missing probe value can never satisfy a condition.
        if ($actual === null) {
            return false;
        }

        // Normalise for list operators: wrap stray strings defensively and
        // apply case-insensitive comparison to match the behaviour of scalarEquals.
        $normaliseList = function (mixed $list, mixed $value): array {
            $list = is_array($list) ? $list : (array) $list;
            if (is_string($value)) {
                return array_map('strtolower', $list);
            }

            return $list;
        };
        $normalisedActual = is_string($actual) ? strtolower($actual) : $actual;

        return match ($op) {
            '=' => $this->scalarEquals($actual, $expected),
            '!=' => ! $this->scalarEquals($actual, $expected),
            '>' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            '>=' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            '<' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            '<=' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'in' => in_array($normalisedActual, $normaliseList($expected, $actual), strict: false),
            'not_in' => ! in_array($normalisedActual, $normaliseList($expected, $actual), strict: false),
            default => false,
        };
    }

    /**
     * Loose equality so "1080" (int) matches "1080" (string from form input)
     * and codec strings compare case-insensitively.
     */
    private function scalarEquals(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        if (is_string($actual) && is_string($expected)) {
            return strcasecmp($actual, $expected) === 0;
        }

        return $actual == $expected;
    }

    private function numeric(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * ffprobe reports avg_frame_rate as a "num/den" string. "0/0" means
     * unknown, treated as missing.
     */
    private function parseFrameRate(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! str_contains($value, '/')) {
            return is_numeric($value) ? (float) $value : null;
        }
        [$num, $den] = array_pad(explode('/', $value, 2), 2, null);
        if (! is_numeric($num) || ! is_numeric($den) || (float) $den === 0.0) {
            return null;
        }

        return (float) $num / (float) $den;
    }
}
