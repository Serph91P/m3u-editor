<?php

use Illuminate\Support\Facades\DB;

it('uses concurrent PostgreSQL index rollback outside a transaction', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only migration assertion.');
    }

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $migration = require database_path('migrations/2026_08_30_132557_add_schedules_direct_login_cooldown_to_epgs_table.php');

    DB::rollBack();
    $rollbackStatements = [];

    try {
        expect(DB::transactionLevel())->toBe(0);
        $migration->down();
        $rollbackStatements = $statements;
    } finally {
        try {
            $migration->up();
        } finally {
            DB::beginTransaction();
        }
    }

    expect($rollbackStatements)->toContain('DROP INDEX CONCURRENTLY IF EXISTS epgs_sd_account_identifier_index');
});
