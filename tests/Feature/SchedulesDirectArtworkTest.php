<?php

use App\Models\Epg;
use App\Models\User;
use App\Services\SchedulesDirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('fetches station artwork and includes in XMLTV', function () {
    $user = User::factory()->create();

    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'sd_username' => 'test@example.com',
        'sd_password' => 'password',
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_id' => 'USA-NY12345-X',
        'sd_station_ids' => ['12345', '67890'],
        'sd_days_to_import' => 1,
    ]);

    // Mock station logos API response
    Http::fake([
        'json.schedulesdirect.org/20141201/metadata/stations' => Http::response([
            [
                'stationID' => '12345',
                'stationLogo' => [
                    ['URL' => 'https://example.com/logo1.png'],
                ],
            ],
            [
                'stationID' => '67890',
                'stationLogo' => [
                    ['URL' => 'https://example.com/logo2.png'],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response([
            'map' => [
                ['stationID' => '12345', 'channel' => '1.1'],
                ['stationID' => '67890', 'channel' => '2.1'],
            ],
            'stations' => [
                [
                    'stationID' => '12345',
                    'name' => 'Test Channel 1',
                    'callsign' => 'TEST1',
                    'stationLogo' => [
                        ['URL' => 'https://example.com/logo1.png', 'height' => 270, 'width' => 360],
                    ],
                ],
                [
                    'stationID' => '67890',
                    'name' => 'Test Channel 2',
                    'callsign' => 'TEST2',
                    'stationLogo' => [
                        ['URL' => 'https://example.com/logo2.png', 'height' => 270, 'width' => 360],
                    ],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([
            [
                'stationID' => '12345',
                'programs' => [
                    [
                        'programID' => 'EP123',
                        'airDateTime' => '2025-09-18T20:00:00Z',
                        'duration' => 3600,
                    ],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([
            [
                'programID' => 'EP123',
                'titles' => [['title120' => 'Test Program']],
                'descriptions' => [
                    'description1000' => [['description' => 'Test description']],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/metadata/programs' => Http::response([
            [
                'programID' => 'EP123',
                'data' => [
                    'Ep' => [
                        ['URI' => 'https://example.com/program123.jpg'],
                    ],
                ],
            ],
        ]),
    ]);

    $service = new SchedulesDirectService;

    // Ensure storage directory is clean
    Storage::fake('local');

    $service->syncEpgData($epg);

    // Check that the XMLTV file was created
    expect(Storage::disk('local')->exists($epg->file_path))->toBeTrue();

    $xmlContent = Storage::disk('local')->get($epg->file_path);

    // Check for channel icons
    expect($xmlContent)->toContain('<channel id="12345">');
    expect($xmlContent)->toContain('<icon src="https://example.com/logo1.png" />');
    expect($xmlContent)->toContain('<channel id="67890">');
    expect($xmlContent)->toContain('<icon src="https://example.com/logo2.png" />');

    // Check for program content (program artwork is disabled for now due to API format issues)
    expect($xmlContent)->toContain('<programme channel="12345"');
});

it('handles missing artwork gracefully', function () {
    $user = User::factory()->create();

    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'sd_username' => 'test@example.com',
        'sd_password' => 'password',
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_id' => 'USA-NY12345-X',
        'sd_station_ids' => ['12345'],
        'sd_days_to_import' => 1,
    ]);

    // Mock responses with no artwork
    Http::fake([
        'json.schedulesdirect.org/20141201/metadata/stations' => Http::response([
            ['stationID' => '12345'],
        ]),
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response([
            'map' => [
                ['stationID' => '12345', 'channel' => '1.1'],
            ],
            'stations' => [
                ['stationID' => '12345', 'name' => 'Test Channel 1', 'callsign' => 'TEST1'],
            ],
        ]),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([
            [
                'stationID' => '12345',
                'programs' => [
                    [
                        'programID' => 'EP123',
                        'airDateTime' => '2025-09-18T20:00:00Z',
                        'duration' => 3600,
                    ],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([
            [
                'programID' => 'EP123',
                'titles' => [['title120' => 'Test Program']],
                'descriptions' => [
                    'description1000' => [['description' => 'Test description']],
                ],
            ],
        ]),
        'json.schedulesdirect.org/20141201/metadata/programs' => Http::response([
            ['programID' => 'EP123', 'data' => []],
        ]),
    ]);

    $service = new SchedulesDirectService;

    Storage::fake('local');

    $service->syncEpgData($epg);

    $xmlContent = Storage::disk('local')->get($epg->file_path);

    // Verify XMLTV is still generated without artwork
    expect($xmlContent)->toContain('<channel id="12345">');
    expect($xmlContent)->toContain('<programme channel="12345"');
    expect($xmlContent)->toContain('Test Program');

    // Verify no icon tags are present when no artwork available
    expect($xmlContent)->not->toContain('<icon');
});
