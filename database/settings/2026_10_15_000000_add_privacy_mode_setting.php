<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.privacy_mode')) {
            $this->migrator->add('general.privacy_mode', false);
        }
    }
};
