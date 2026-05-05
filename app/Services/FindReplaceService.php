<?php

namespace App\Services;

use App\Models\Playlist;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Service to build shared Find & Replace form schemas and load saved patterns.
 */
class FindReplaceService
{
    /**
     * Load saved find/replace patterns from playlists for the given target.
     *
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>}
     */
    public static function getSavedPatterns(string $target): array
    {
        $patterns = [];
        $rules = [];
        $counter = 0;

        foreach (Playlist::where('user_id', auth()->id())->get() as $playlist) {
            foreach ($playlist->find_replace_rules ?? [] as $rule) {
                if (is_array($rule) && ($rule['target'] ?? 'channels') === $target) {
                    $patterns[$counter] = "{$playlist->name} - ".($rule['name'] ?? 'Unnamed');
                    $rules[$counter] = $rule;
                    $counter++;
                }
            }
        }

        return [$patterns, $rules];
    }

    /**
     * Build the find/replace schema used by bulk actions (no playlist selector).
     *
     * @return array<int, mixed>
     */
    public static function getBulkActionSchema(string $target): array
    {
        [$savedPatterns, $savedPatternRules] = self::getSavedPatterns($target);

        $schema = [
            Select::make('saved_pattern')
                ->label('Load saved pattern')
                ->searchable()
                ->placeholder('Select a saved pattern...')
                ->options($savedPatterns)
                ->hidden(empty($savedPatterns))
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set) use ($savedPatternRules): void {
                    if ($state === null || $state === '') {
                        return;
                    }
                    $rule = $savedPatternRules[(int) $state] ?? null;
                    if (! $rule) {
                        return;
                    }
                    $set('use_regex', $rule['use_regex'] ?? true);
                    $set('find_replace', $rule['find_replace'] ?? '');
                    $set('replace_with', $rule['replace_with'] ?? '');
                    self::applyConditionsFromSavedRule($rule, $set);
                })
                ->dehydrated(false),
            Toggle::make('use_regex')
                ->label('Use Regex')
                ->live()
                ->helperText('Use regex patterns to find and replace. If disabled, will use direct string comparison.')
                ->default(true),
            TextInput::make('find_replace')
                ->label(fn (Get $get) => ! $get('use_regex') ? 'String to replace' : 'Pattern to replace')
                ->required()
                ->placeholder(
                    fn (Get $get) => $get('use_regex') ? '^(US- |UK- |CA- )' : 'US -'
                )
                ->helperText(
                    fn (Get $get) => ! $get('use_regex')
                        ? 'This is the string you want to find and replace.'
                        : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
                ),
            TextInput::make('replace_with')
                ->label('Replace with (optional)')
                ->placeholder('Leave empty to remove'),
        ];

        if (self::targetSupportsProbeConditions($target)) {
            $schema = array_merge($schema, self::getConditionsSchema());
        }

        return $schema;
    }

    /**
     * Build the find/replace schema for header actions (includes playlist selector).
     * Pass $columnOptions to include a column selector (e.g. for channels/series).
     *
     * @param  array<string, string>  $columnOptions
     * @return array<int, mixed>
     */
    public static function getHeaderActionSchema(string $target, array $columnOptions = []): array
    {
        [$savedPatterns, $savedPatternRules] = self::getSavedPatterns($target);

        $afterStateUpdated = function (?string $state, Set $set) use ($savedPatternRules, $columnOptions): void {
            if ($state === null || $state === '') {
                return;
            }
            $rule = $savedPatternRules[(int) $state] ?? null;
            if (! $rule) {
                return;
            }
            $set('use_regex', $rule['use_regex'] ?? true);
            if (! empty($columnOptions)) {
                $set('column', $rule['column'] ?? array_key_first($columnOptions));
            }
            $set('find_replace', $rule['find_replace'] ?? '');
            $set('replace_with', $rule['replace_with'] ?? '');
            self::applyConditionsFromSavedRule($rule, $set);
        };

        $schema = [
            Select::make('saved_pattern')
                ->label('Load saved pattern')
                ->searchable()
                ->placeholder('Select a saved pattern...')
                ->options($savedPatterns)
                ->hidden(empty($savedPatterns))
                ->live()
                ->afterStateUpdated($afterStateUpdated)
                ->dehydrated(false),
            Toggle::make('all_playlists')
                ->label('All Playlists')
                ->live()
                ->helperText('Apply find and replace to all playlists? If disabled, it will only apply to the selected playlist.')
                ->default(true),
            Select::make('playlist')
                ->label('Playlist')
                ->required()
                ->helperText('Select the playlist you would like to apply changes to.')
                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->hidden(fn (Get $get) => $get('all_playlists') === true)
                ->searchable(),
            Toggle::make('use_regex')
                ->label('Use Regex')
                ->live()
                ->helperText('Use regex patterns to find and replace. If disabled, will use direct string comparison.')
                ->default(true),
        ];

        if (! empty($columnOptions)) {
            $schema[] = Select::make('column')
                ->label('Column to modify')
                ->options($columnOptions)
                ->default(array_key_first($columnOptions))
                ->required()
                ->columnSpan(1);
        }

        $schema[] = TextInput::make('find_replace')
            ->label(fn (Get $get) => ! $get('use_regex') ? 'String to replace' : 'Pattern to replace')
            ->required()
            ->placeholder(
                fn (Get $get) => $get('use_regex') ? '^(US- |UK- |CA- )' : 'US -'
            )
            ->helperText(
                fn (Get $get) => ! $get('use_regex')
                    ? 'This is the string you want to find and replace.'
                    : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
            );

        $schema[] = TextInput::make('replace_with')
            ->label('Replace with (optional)')
            ->placeholder('Leave empty to remove');

        if (self::targetSupportsProbeConditions($target)) {
            $schema = array_merge($schema, self::getConditionsSchema());
        }

        return $schema;
    }

    /**
     * Build the reset schema for header actions (includes playlist selector).
     * Pass $columnOptions to include a column selector.
     *
     * @param  array<string, string>  $columnOptions
     * @return array<int, mixed>
     */
    public static function getHeaderResetSchema(array $columnOptions = []): array
    {
        $schema = [
            Toggle::make('all_playlists')
                ->label('All Playlists')
                ->live()
                ->helperText('Apply reset to all playlists? If disabled, it will only apply to the selected playlist.')
                ->default(false),
            Select::make('playlist')
                ->required()
                ->label('Playlist')
                ->helperText('Select the playlist you would like to apply the reset to.')
                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->hidden(fn (Get $get) => $get('all_playlists') === true)
                ->searchable(),
        ];

        if (! empty($columnOptions)) {
            $schema[] = Select::make('column')
                ->label('Column to reset')
                ->options($columnOptions)
                ->default(array_key_first($columnOptions))
                ->required()
                ->columnSpan(1);
        }

        return $schema;
    }

    /**
     * Probe-data conditions only make sense for targets backed by an actual
     * playable stream (live + VOD channels). Group/series/category rules
     * are intentionally excluded — they have no `stream_stats` of their own.
     */
    public static function targetSupportsProbeConditions(string $target): bool
    {
        return in_array($target, ['channels', 'vod_channels'], true);
    }

    /**
     * Probe-data field options shared between Find & Replace and adaptive
     * stream profile rules. The `video.resolution` shortcut is unique to
     * Find & Replace and exposes a "WIDTHxHEIGHT" string built from the
     * width/height of the first video stream.
     *
     * @return array<string, array<string, string>>
     */
    public static function probeFieldOptions(): array
    {
        return [
            __('Video') => [
                'video.resolution' => __('Resolution (WIDTHxHEIGHT)'),
                'video.codec_name' => __('Codec'),
                'video.height' => __('Height (px)'),
                'video.width' => __('Width (px)'),
                'video.bit_rate' => __('Bitrate (bps)'),
                'video.frame_rate' => __('Frame rate (fps)'),
                'video.profile' => __('Profile'),
                'video.display_aspect_ratio' => __('Aspect ratio'),
            ],
            __('Audio') => [
                'audio.codec_name' => __('Codec'),
                'audio.channels' => __('Channels'),
                'audio.sample_rate' => __('Sample rate (Hz)'),
            ],
            __('Format') => [
                'format.format_name' => __('Format name'),
            ],
        ];
    }

    /**
     * Operators applicable to a probe field — numeric fields get the
     * comparison set, string fields get equality and list membership.
     *
     * @return array<string, string>
     */
    public static function probeOperatorsForField(?string $field): array
    {
        $numericFields = [
            'video.height', 'video.width', 'video.bit_rate', 'video.frame_rate',
            'audio.channels', 'audio.sample_rate',
        ];

        if (in_array($field, $numericFields, true)) {
            return [
                '=' => '=',
                '!=' => '≠',
                '>' => '>',
                '>=' => '≥',
                '<' => '<',
                '<=' => '≤',
            ];
        }

        return [
            '=' => '=',
            '!=' => '≠',
            'in' => __('is one of'),
            'not_in' => __('is not one of'),
        ];
    }

    public static function probeValuePlaceholder(?string $field): string
    {
        return match ($field) {
            'video.resolution' => '1920x1080',
            'video.codec_name' => 'hevc',
            'video.height' => '1080',
            'video.width' => '1920',
            'video.bit_rate' => '5000000',
            'video.frame_rate' => '60',
            'video.profile' => 'High',
            'video.display_aspect_ratio' => '16:9',
            'audio.codec_name' => 'aac',
            'audio.channels' => '2',
            'audio.sample_rate' => '48000',
            'format.format_name' => 'hls',
            default => '',
        };
    }

    /**
     * Reusable schema fragment for the optional "Conditional on probe data"
     * panel. Used by every Find & Replace form so the form payload always
     * carries a consistent shape that {@see ChannelFindAndReplace} understands.
     *
     * @return array<int, mixed>
     */
    public static function getConditionsSchema(): array
    {
        return [
            Toggle::make('conditions_enabled')
                ->label(__('Only apply when probe data matches'))
                ->helperText(__('When enabled, channels are only updated if their probed media information satisfies the conditions below. Channels without probe data are skipped.'))
                ->live()
                ->default(false)
                ->columnSpanFull(),
            Select::make('conditions_match_mode')
                ->label(__('Match mode'))
                ->options([
                    'all' => __('All conditions must match (AND)'),
                    'any' => __('Any condition can match (OR)'),
                ])
                ->default('all')
                ->required()
                ->visible(fn (Get $get): bool => (bool) $get('conditions_enabled'))
                ->columnSpan(1),
            Toggle::make('require_probe_data')
                ->label(__('Require probe data'))
                ->helperText(__('When enabled, only channels with probe data are processed. When disabled, channels without probe data are still skipped (no condition can match) but no warning is emitted.'))
                ->default(true)
                ->visible(fn (Get $get): bool => (bool) $get('conditions_enabled'))
                ->columnSpan(1),
            Repeater::make('conditions')
                ->label(__('Conditions'))
                ->visible(fn (Get $get): bool => (bool) $get('conditions_enabled'))
                ->required(fn (Get $get): bool => (bool) $get('conditions_enabled'))
                ->minItems(fn (Get $get): int => $get('conditions_enabled') ? 1 : 0)
                ->columns(3)
                ->addActionLabel(__('Add condition'))
                ->columnSpanFull()
                ->schema([
                    Select::make('field')
                        ->label(__('Field'))
                        ->options(self::probeFieldOptions())
                        ->required()
                        ->searchable()
                        ->live(),
                    Select::make('op')
                        ->label(__('Operator'))
                        ->options(fn (Get $get): array => self::probeOperatorsForField($get('field')))
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('value', in_array($state, ['in', 'not_in'], true) ? [] : null)),
                    TextInput::make('value')
                        ->label(__('Value'))
                        ->required()
                        ->visible(fn (Get $get): bool => ! in_array($get('op'), ['in', 'not_in'], true))
                        ->placeholder(fn (Get $get): string => self::probeValuePlaceholder($get('field'))),
                    TagsInput::make('value')
                        ->label(__('Values'))
                        ->required()
                        ->splitKeys([',', 'Tab'])
                        ->visible(fn (Get $get): bool => in_array($get('op'), ['in', 'not_in'], true))
                        ->helperText(__('Press Enter, comma, or Tab after each value.')),
                ]),
        ];
    }

    /**
     * Restore probe-condition fields when a saved pattern is selected. Use
     * inside the `afterStateUpdated` of the saved-pattern Select alongside
     * the existing `find_replace`/`replace_with` restorers.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function applyConditionsFromSavedRule(array $rule, Set $set): void
    {
        $set('conditions_enabled', (bool) ($rule['conditions_enabled'] ?? false));
        $set('conditions_match_mode', $rule['conditions_match_mode'] ?? 'all');
        $set('require_probe_data', (bool) ($rule['require_probe_data'] ?? false));
        $set('conditions', is_array($rule['conditions'] ?? null) ? $rule['conditions'] : []);
    }

    /**
     * Normalise a Filament form payload into the {field, op, value} shape
     * understood by {@see \App\Services\StreamProfileRuleEvaluator::matches()}.
     * Returns `[]` when conditions are not enabled so callers can pass the
     * result straight through to the job constructor.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public static function normaliseConditionsFromFormData(array $data): array
    {
        if (! ($data['conditions_enabled'] ?? false)) {
            return [];
        }

        $conditions = $data['conditions'] ?? [];
        if (! is_array($conditions)) {
            return [];
        }

        $normalised = [];
        foreach ($conditions as $condition) {
            if (! is_array($condition) || empty($condition['field']) || empty($condition['op'])) {
                continue;
            }
            $value = $condition['value'] ?? null;
            if (in_array($condition['op'], ['in', 'not_in'], true)) {
                if (is_string($value)) {
                    $value = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
                } elseif (! is_array($value)) {
                    $value = [];
                }
            }
            $normalised[] = [
                'field' => $condition['field'],
                'op' => $condition['op'],
                'value' => $value,
            ];
        }

        return $normalised;
    }
}
