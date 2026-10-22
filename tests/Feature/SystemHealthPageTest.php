<?php

declare(strict_types=1);

use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Widgets\SystemHealthWidget;
use App\Models\User;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        '*/info' => Http::response([
            'version' => '9.9.9',
            'python_version' => '3.14.0',
            'ffmpeg_version' => '7.1',
            'hardware_acceleration' => ['enabled' => true, 'type' => 'vaapi'],
        ]),
        '*' => Http::response('', 404),
    ]);
    config()->set('proxy.proxy_integration_enabled', true);
});

/**
 * A SystemHealthService whose /proc process list is replaced by $commands.
 *
 * @param  list<string>|null  $commands
 */
function systemHealthServiceWithProcesses(?array $commands): SystemHealthService
{
    return new class($commands) extends SystemHealthService
    {
        public function __construct(private ?array $commands) {}

        protected function processCommandLines(): ?array
        {
            return $this->commands;
        }
    };
}

it('is only available to admins', function () {
    $this->actingAs(User::factory()->create());
    expect(SystemHealth::canAccess())->toBeFalse();
    $this->get(SystemHealth::getUrl())->assertForbidden();

    $this->actingAs(User::factory()->admin()->create());
    expect(SystemHealth::canAccess())->toBeTrue();
});

it('only links the page in the sidebar for admins', function () {
    $this->actingAs(User::factory()->create());
    $this->get(PlaylistResource::getUrl())->assertOk()->assertDontSee(SystemHealth::getUrl());

    $this->actingAs(User::factory()->admin()->create());
    $this->get(PlaylistResource::getUrl())->assertOk()->assertSee(SystemHealth::getUrl());
});

it('renders the report for an admin', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(SystemHealth::class)
        ->assertOk()
        ->assertSee('Health checks')
        ->assertSee('Container services')
        ->assertSee(PHP_VERSION)
        ->assertSee(app()->version())
        ->assertSee('3.14.0')
        ->assertSee('vaapi');
});

it('shows fresh values after the refresh action', function () {
    $this->actingAs(User::factory()->admin()->create());

    Cache::put(SystemHealthService::REPORT_CACHE_KEY, [
        'checks' => [],
        'php' => ['version' => '0.0.0-stale'],
        'services' => null,
        'generated_at' => 'stale',
    ], 60);

    Livewire::test(SystemHealth::class)
        ->assertSee('0.0.0-stale')
        ->callAction('refresh')
        ->assertNotified()
        ->assertDontSee('0.0.0-stale')
        ->assertSee(PHP_VERSION);
});

it('links the dashboard widget to the page', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(SystemHealthWidget::class)
        ->assertSee('View details')
        ->assertSee(SystemHealth::getUrl());
});

it('reports container services by process and expected placement', function () {
    config()->set('app.nginx_enabled', false);
    config()->set('app.network_broadcast_enabled', false);
    config()->set('proxy.external_proxy_enabled', true);
    config()->set('database.redis.default.host', 'redis');
    config()->set('session.driver', 'redis');
    // Pinned so the result does not depend on the suite's DB (CI runs on a local Postgres).
    config()->set('database.connections.'.config('database.default').'.driver', 'sqlite');

    $services = invade(systemHealthServiceWithProcesses([
        '/usr/bin/python3 /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf',
        'php-fpm: master process (/etc/php84/php-fpm.conf)',
        '/usr/bin/php /var/www/html/artisan horizon',
        '/usr/sbin/crond -f -d 8',
    ]))->services();

    expect($services)
        ->php_fpm->toBe('running')
        ->queue->toBe('running')
        ->scheduler->toBe('running')
        ->websocket->toBe('stopped')
        ->nginx->toBe('external')
        ->m3u_proxy->toBe('external')
        ->redis->toBe('external')
        ->postgresql->toBe('disabled')
        ->network_broadcast->toBe('disabled');
});

it('matches the rewritten titles of the embedded services', function () {
    config()->set('database.connections.'.config('database.default').'.driver', 'pgsql');
    config()->set('database.connections.'.config('database.default').'.host', '127.0.0.1');

    $services = invade(systemHealthServiceWithProcesses([
        'supervisord',
        'nginx: master process /usr/sbin/nginx -g daemon off;',
        '/usr/bin/php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=36800',
        'postgres -D /var/lib/postgresql/data -h 0.0.0.0 -p 5432',
        'redis-server 127.0.0.1:36790',
        '/usr/bin/python3 /opt/m3u-proxy/main.py',
    ]))->services();

    expect($services)
        ->nginx->toBe('running')
        ->websocket->toBe('running')
        ->postgresql->toBe('running')
        ->redis->toBe('running')
        ->m3u_proxy->toBe('running');
});

it('skips the services section outside the container', function () {
    expect(invade(systemHealthServiceWithProcesses(null))->services())->toBeNull()
        ->and(invade(systemHealthServiceWithProcesses(['/usr/sbin/crond -f']))->services())->toBeNull();
});
