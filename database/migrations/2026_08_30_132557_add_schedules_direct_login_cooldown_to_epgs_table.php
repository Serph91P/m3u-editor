<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('schedules_direct_login_cooldowns') && ! Schema::hasColumn('schedules_direct_login_cooldowns', 'account_identifier')) {
            Schema::drop('schedules_direct_login_cooldowns');
        }

        if (! Schema::hasTable('schedules_direct_login_cooldowns')) {
            Schema::create('schedules_direct_login_cooldowns', function (Blueprint $table): void {
                $table->string('account_identifier', 64)->primary();
                $table->timestamp('started_at');
                $table->timestamp('cooldown_until');
                $table->timestamp('notified_at')->nullable();
            });
        } else {
            $missingStartedAt = ! Schema::hasColumn('schedules_direct_login_cooldowns', 'started_at');
            $missingCooldownUntil = ! Schema::hasColumn('schedules_direct_login_cooldowns', 'cooldown_until');
            $missingNotifiedAt = ! Schema::hasColumn('schedules_direct_login_cooldowns', 'notified_at');

            if ($missingStartedAt || $missingCooldownUntil || $missingNotifiedAt) {
                Schema::table('schedules_direct_login_cooldowns', function (Blueprint $table) use ($missingStartedAt, $missingCooldownUntil, $missingNotifiedAt): void {
                    if ($missingStartedAt) {
                        $table->timestamp('started_at')->nullable();
                    }

                    if ($missingCooldownUntil) {
                        $table->timestamp('cooldown_until')->nullable();
                    }

                    if ($missingNotifiedAt) {
                        $table->timestamp('notified_at')->nullable();
                    }
                });
            }
        }

        $missingAccountIdentifier = ! Schema::hasColumn('epgs', 'sd_account_identifier');
        $missingCooldownStartedAt = ! Schema::hasColumn('epgs', 'sd_login_cooldown_started_at');
        $missingCooldownUntil = ! Schema::hasColumn('epgs', 'sd_login_cooldown_until');
        $missingCooldownNotifiedAt = ! Schema::hasColumn('epgs', 'sd_login_cooldown_notified_at');

        if ($missingAccountIdentifier || $missingCooldownStartedAt || $missingCooldownUntil || $missingCooldownNotifiedAt) {
            Schema::table('epgs', function (Blueprint $table) use ($missingAccountIdentifier, $missingCooldownStartedAt, $missingCooldownUntil, $missingCooldownNotifiedAt): void {
                if ($missingAccountIdentifier) {
                    $table->string('sd_account_identifier', 64)->nullable();
                }

                if ($missingCooldownStartedAt) {
                    $table->timestamp('sd_login_cooldown_started_at')->nullable();
                }

                if ($missingCooldownUntil) {
                    $table->timestamp('sd_login_cooldown_until')->nullable();
                }

                if ($missingCooldownNotifiedAt) {
                    $table->timestamp('sd_login_cooldown_notified_at')->nullable();
                }
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            $index = DB::selectOne(<<<'SQL'
                SELECT
                    index_definition.indisvalid,
                    table_class.relname AS table_name,
                    pg_get_indexdef(index_class.oid) AS definition
                FROM pg_class index_class
                JOIN pg_index index_definition ON index_definition.indexrelid = index_class.oid
                JOIN pg_class table_class ON table_class.oid = index_definition.indrelid
                JOIN pg_namespace index_namespace ON index_namespace.oid = index_class.relnamespace
                WHERE index_class.relname = ?
                  AND index_namespace.nspname = current_schema()
                SQL, ['epgs_sd_account_identifier_index']);

            $hasExpectedIndex = $index
                && in_array($index->indisvalid, [true, 1, '1', 't'], true)
                && $index->table_name === 'epgs'
                && str_contains(str_replace('"', '', $index->definition), '(sd_account_identifier)');

            if ($hasExpectedIndex) {
                return;
            }

            if (DB::transactionLevel() > 0) {
                DB::statement('DROP INDEX IF EXISTS epgs_sd_account_identifier_index');
                DB::statement('CREATE INDEX epgs_sd_account_identifier_index ON epgs (sd_account_identifier)');
            } else {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS epgs_sd_account_identifier_index');
                DB::statement('CREATE INDEX CONCURRENTLY epgs_sd_account_identifier_index ON epgs (sd_account_identifier)');
            }

            return;
        }

        if (! Schema::hasIndex('epgs', 'epgs_sd_account_identifier_index')) {
            Schema::table('epgs', function (Blueprint $table): void {
                $table->index('sd_account_identifier');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules_direct_login_cooldowns');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS epgs_sd_account_identifier_index');
        } elseif (Schema::hasIndex('epgs', 'epgs_sd_account_identifier_index')) {
            Schema::table('epgs', function (Blueprint $table): void {
                $table->dropIndex(['sd_account_identifier']);
            });
        }

        $columns = collect([
            'sd_account_identifier',
            'sd_login_cooldown_started_at',
            'sd_login_cooldown_until',
            'sd_login_cooldown_notified_at',
        ])->filter(fn (string $column): bool => Schema::hasColumn('epgs', $column))->all();

        if ($columns !== []) {
            Schema::table('epgs', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
