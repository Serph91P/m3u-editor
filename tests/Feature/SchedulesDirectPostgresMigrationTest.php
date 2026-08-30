<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('fails before changing PostgreSQL schema when up runs inside a transaction', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only migration assertion.');
    }

    $migration = require database_path('migrations/2026_08_30_132557_add_schedules_direct_login_cooldown_to_epgs_table.php');
    DB::rollBack();
    $migration->down();
    DB::beginTransaction();
    $exception = null;
    $schemaChanged = null;

    try {
        try {
            $migration->up();
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        $schemaChanged = Schema::hasTable('schedules_direct_login_cooldowns')
            || Schema::hasTable('schedules_direct_login_cooldown_claims')
            || Schema::hasColumn('epgs', 'sd_account_identifier');
    } finally {
        DB::rollBack();

        try {
            $migration->up();
        } finally {
            DB::beginTransaction();
        }
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toContain('outside a transaction')
        ->and($schemaChanged)->toBeFalse();
});

it('fails before changing PostgreSQL schema when down runs inside a transaction', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only migration assertion.');
    }

    $migration = require database_path('migrations/2026_08_30_132557_add_schedules_direct_login_cooldown_to_epgs_table.php');
    $exception = null;

    try {
        $migration->down();
    } catch (Throwable $throwable) {
        $exception = $throwable;
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toContain('outside a transaction')
        ->and(Schema::hasTable('schedules_direct_login_cooldowns'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_account_identifier'))->toBeTrue();
});

it('uses concurrent PostgreSQL index statements outside a transaction', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only migration assertion.');
    }

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $migration = require database_path('migrations/2026_08_30_132557_add_schedules_direct_login_cooldown_to_epgs_table.php');
    DB::rollBack();
    $outsideTransactionStatements = [];

    try {
        expect(DB::transactionLevel())->toBe(0);
        $migration->down();
        $migration->up();
        $outsideTransactionStatements = $statements;
    } finally {
        DB::beginTransaction();
    }

    expect($outsideTransactionStatements)->toContain('DROP INDEX CONCURRENTLY IF EXISTS epgs_sd_account_identifier_index')
        ->and($outsideTransactionStatements)->toContain('CREATE INDEX CONCURRENTLY epgs_sd_account_identifier_index ON epgs (sd_account_identifier)')
        ->and($outsideTransactionStatements)->not->toContain('DROP INDEX IF EXISTS epgs_sd_account_identifier_index')
        ->and($outsideTransactionStatements)->not->toContain('CREATE INDEX epgs_sd_account_identifier_index ON epgs (sd_account_identifier)');
});
