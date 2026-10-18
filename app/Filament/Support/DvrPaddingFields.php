<?php

namespace App\Filament\Support;

use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Amount + sec/min unit + direction inputs for DVR start/end padding.
 *
 * Padding is stored as one signed seconds value: positive extends the recording
 * (start early / end late), negative trims it (start late / finish early).
 */
final class DvrPaddingFields
{
    public const UNIT_SECONDS = 'sec';

    public const UNIT_MINUTES = 'min';

    public const DIRECTION_EXTEND = 'extend';

    public const DIRECTION_TRIM = 'trim';

    /** Largest padding either way: 24 hours, matching the longest schedulable recording. */
    public const MAX_SECONDS = 86400;

    /**
     * Combine the helper inputs into signed seconds. Null when the amount is blank.
     */
    public static function toSeconds(mixed $amount, ?string $unit, ?string $direction): ?int
    {
        if (! is_numeric($amount)) {
            return null;
        }

        $seconds = (int) $amount * ($unit === self::UNIT_MINUTES ? 60 : 1);

        return $direction === self::DIRECTION_TRIM ? -$seconds : $seconds;
    }

    /**
     * Bound signed padding seconds to ±MAX_SECONDS.
     */
    public static function clampSeconds(int $seconds): int
    {
        return max(-self::MAX_SECONDS, min(self::MAX_SECONDS, $seconds));
    }

    /**
     * Split stored signed seconds into the helper inputs. Whole minutes show as minutes.
     *
     * @return array{amount: int|null, unit: string, direction: string}
     */
    public static function fromSeconds(mixed $seconds): array
    {
        if (! is_numeric($seconds)) {
            return ['amount' => null, 'unit' => self::UNIT_SECONDS, 'direction' => self::DIRECTION_EXTEND];
        }

        $seconds = (int) $seconds;
        $absolute = abs($seconds);
        $isMinutes = $absolute !== 0 && $absolute % 60 === 0;

        return [
            'amount' => $isMinutes ? intdiv($absolute, 60) : $absolute,
            'unit' => $isMinutes ? self::UNIT_MINUTES : self::UNIT_SECONDS,
            'direction' => $seconds < 0 ? self::DIRECTION_TRIM : self::DIRECTION_EXTEND,
        ];
    }

    /**
     * Compact signed label for summaries, e.g. "+30s" or "-5m".
     */
    public static function formatSeconds(int $seconds): string
    {
        $parts = self::fromSeconds($seconds);

        return ($seconds < 0 ? '-' : '+').$parts['amount'].($parts['unit'] === self::UNIT_MINUTES ? 'm' : 's');
    }

    /**
     * Padding inputs bound to a model attribute (`$field`, signed seconds).
     *
     * @param  int|null  $maxSeconds  Upper bound on the entered amount, in seconds.
     * @param  int|null  $defaultSeconds  Value for new records; null leaves it blank (inherit).
     */
    public static function make(
        string $field,
        string $label,
        bool $isStart,
        bool $allowNegative = true,
        ?int $maxSeconds = self::MAX_SECONDS,
        ?int $defaultSeconds = null,
        ?string $placeholder = null,
    ): Fieldset {
        $amountField = "{$field}_amount";
        $unitField = "{$field}_unit";
        $directionField = "{$field}_direction";

        $hydrate = fn (string $key): Closure => function (Component $component, Get $get) use ($field, $key): void {
            $component->state(self::fromSeconds($get($field))[$key]);
        };

        $inputs = self::inputs($amountField, $unitField, $directionField, $isStart, $allowNegative, $maxSeconds, $placeholder);

        // Keep the stored seconds in sync as the helper inputs change. Some pages save
        // from the form's raw state, which dehydrate hooks never touch.
        $sync = function (Get $get, Set $set) use ($field, $amountField, $unitField, $directionField, $allowNegative): void {
            $set($field, self::toSeconds(
                $get($amountField),
                $get($unitField),
                $allowNegative ? $get($directionField) : self::DIRECTION_EXTEND,
            ));
        };

        foreach ($inputs as $input) {
            $key = match ($input->getName()) {
                $amountField => 'amount',
                $unitField => 'unit',
                default => 'direction',
            };

            $input
                ->afterStateHydrated($hydrate($key))
                ->afterStateUpdated($sync);

            $input instanceof TextInput ? $input->live(onBlur: true) : $input->live();
        }

        return Fieldset::make($label)
            ->columns(['default' => 1, 'sm' => 2])
            ->schema([
                Hidden::make($field)->default($defaultSeconds),
                ...$inputs,
            ]);
    }

    /**
     * Plain direction/unit/amount inputs, for forms bound to component properties.
     * The direction input is omitted when negative padding isn't allowed. Lay them out
     * in a two-column container: the toggles share a row and the amount sits below.
     *
     * @return array<int, TextInput|ToggleButtons>
     */
    public static function inputs(
        string $amountField,
        string $unitField,
        string $directionField,
        bool $isStart,
        bool $allowNegative = true,
        ?int $maxSeconds = self::MAX_SECONDS,
        ?string $placeholder = null,
    ): array {
        $amount = TextInput::make($amountField)
            ->label(__('Amount'))
            ->numeric()
            ->integer()
            ->minValue(0)
            ->placeholder($placeholder)
            ->saved(false);

        if ($maxSeconds !== null) {
            $amount->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $unitField, $maxSeconds): void {
                $seconds = self::toSeconds($value, $get($unitField), self::DIRECTION_EXTEND);

                if ($seconds !== null && $seconds > $maxSeconds) {
                    $fail(__('The padding may not be greater than :max seconds.', ['max' => $maxSeconds]));
                }
            });
        }

        $unit = ToggleButtons::make($unitField)
            ->label(__('Unit'))
            ->options([
                self::UNIT_SECONDS => __('sec'),
                self::UNIT_MINUTES => __('min'),
            ])
            ->default(self::UNIT_SECONDS)
            ->inline()
            ->grouped()
            ->saved(false);

        $amount->columnSpanFull();

        if (! $allowNegative) {
            return [$unit->columnSpanFull(), $amount];
        }

        $direction = ToggleButtons::make($directionField)
            ->label(__('Direction'))
            ->options([
                self::DIRECTION_EXTEND => $isStart ? __('Early') : __('Late'),
                self::DIRECTION_TRIM => $isStart ? __('Late') : __('Early'),
            ])
            ->default(self::DIRECTION_EXTEND)
            ->inline()
            ->grouped()
            ->saved(false);

        // Toggles share the first row; the amount spans the row below.
        return [$direction, $unit, $amount];
    }
}
