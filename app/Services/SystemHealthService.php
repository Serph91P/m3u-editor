<?php

namespace App\Services;

use App\Facades\GitInfo;
use App\Providers\VersionServiceProvider;
use Composer\InstalledVersions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Number;
use PDO;

/**
 * Collects the health checks shown on the dashboard System Health widget and
 * the fuller phpinfo-style report shown on the Tools > System Health page.
 */
class SystemHealthService
{
    public const REPORT_CACHE_KEY = 'system_health_report';

    private const REPORT_TTL_SECONDS = 30;

    /**
     * Container processes started by docker/8.4/supervisord.conf, keyed by
     * service, matched against each /proc/<pid>/cmdline. nginx, php-fpm and
     * postgres child processes rewrite their titles, so the patterns match the
     * rewritten master titles as well as the original binary paths.
     *
     * @var array<string, string>
     */
    private const PROCESS_PATTERNS = [
        'supervisord' => '/(^|\/)supervisord(\s|$)/',
        'nginx' => '/^nginx: master|(^|\/)nginx\s/',
        'php_fpm' => '/^php-fpm/',
        'queue' => '/artisan horizon/',
        'websocket' => '/artisan reverb:start/',
        'scheduler' => '/(^|\/)crond(\s|$)/',
        'postgresql' => '/^(\S*\/)?postgres(\s|:|$)/',
        'redis' => '/(^|\/)redis-server(\s|$)/',
        'm3u_proxy' => '/m3u-proxy\/main\.py/',
        'network_broadcast' => '/artisan network:broadcast/',
    ];

    /**
     * Pass/fail checks for the dashboard widget.
     *
     * @return list<array{key: string, label: string, ok: bool, detail: string}>
     */
    public function checks(): array
    {
        $checks = [];

        $updateAvailable = $this->safe(fn () => VersionServiceProvider::updateAvailable(), false);
        $checks[] = [
            'key' => 'version',
            'label' => __('Version'),
            'ok' => ! $updateAvailable,
            'detail' => $updateAvailable ? __('Update available') : __('Up to date'),
        ];

        $databaseOk = $this->safe(fn () => DB::connection()->getPdo() !== null);
        $checks[] = [
            'key' => 'database',
            'label' => __('Database'),
            'ok' => $databaseOk,
            'detail' => $databaseOk
                ? $this->safe(fn () => DB::connection()->getDriverName(), __('Connected'))
                : __('Unavailable'),
        ];

        // Only surface Redis when the app is actually backed by it (a SQLite-only
        // install may run file cache + sync queue and have no Redis at all).
        if ($this->usesRedis()) {
            $redisOk = $this->safe(fn () => (bool) Redis::connection()->ping());
            $checks[] = [
                'key' => 'redis',
                'label' => __('Redis'),
                'ok' => $redisOk,
                'detail' => $redisOk ? __('Connected') : __('Unavailable'),
            ];
        }

        // Debug mode. A production instance left with APP_DEBUG=true leaks
        // stack traces and config on any error - a real, stable config-health
        // signal that never needs to be live.
        $debug = (bool) config('app.debug');
        $isProduction = app()->environment('production');
        $checks[] = [
            'key' => 'debug',
            'label' => __('Debug mode'),
            'ok' => ! ($debug && $isProduction),
            'detail' => match (true) {
                ! $debug => __('Disabled'),
                $isProduction => __('Enabled'),
                default => __('Enabled (:env)', ['env' => app()->environment()]),
            },
        ];

        $free = $this->safe(fn () => (int) disk_free_space(base_path()), 0);
        $total = $this->safe(fn () => (int) disk_total_space(base_path()), 0);
        $freePct = $total > 0 ? (int) round($free / $total * 100) : null;
        $checks[] = [
            'key' => 'disk',
            'label' => __('Disk free'),
            'ok' => $freePct === null || $freePct > 10,
            'detail' => $freePct !== null
                ? __(':size free (:percent%)', ['size' => Number::fileSize($free, precision: 1), 'percent' => $freePct])
                : __('Unknown'),
        ];

        return $checks;
    }

    /**
     * Full system report for the System Health page, cached briefly so page
     * polling and repeat visits do not re-probe every service.
     *
     * @return array<string, mixed>
     */
    public function report(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::REPORT_CACHE_KEY);
        }

        return Cache::remember(self::REPORT_CACHE_KEY, self::REPORT_TTL_SECONDS, fn (): array => [
            'checks' => $this->checks(),
            'application' => $this->applicationInfo(),
            'php' => $this->phpInfo(),
            'os' => $this->osInfo(),
            'database' => $this->databaseInfo(),
            'cache' => $this->cacheInfo(),
            'proxy' => $this->proxyInfo(),
            'services' => $this->services(),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Whether any core subsystem (cache, queue, session, broadcasting) is
     * actually configured to use Redis.
     */
    public function usesRedis(): bool
    {
        return in_array('redis', $this->drivers(), true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function applicationInfo(): array
    {
        $buildDate = $this->safe(fn () => GitInfo::getBuildDate(), null);

        return [
            'version' => VersionServiceProvider::getVersion(),
            'latest_version' => $this->safe(fn () => VersionServiceProvider::getRemoteVersion() ?: null, null),
            'branch' => $this->safe(fn () => GitInfo::getBranch(), null),
            'commit' => $this->safe(fn () => ($commit = GitInfo::getCommit()) ? substr($commit, 0, 12) : null, null),
            'build_date' => $buildDate ?: null,
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'timezone' => config('app.timezone'),
            'url' => config('app.url'),
            'laravel_version' => app()->version(),
            'filament_version' => $this->packageVersion('filament/filament'),
            'livewire_version' => $this->packageVersion('livewire/livewire'),
        ];
    }

    /**
     * Values come from the PHP SAPI serving this request (php-fpm in the
     * container); queue workers and the scheduler run the CLI binary.
     *
     * @return array<string, mixed>
     */
    protected function phpInfo(): array
    {
        $opcache = $this->safe(fn () => function_exists('opcache_get_status') ? (opcache_get_status(false) ?: null) : null, null);
        $extensions = get_loaded_extensions();
        natcasesort($extensions);

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'ini_file' => php_ini_loaded_file() ?: null,
            'memory_limit' => ini_get('memory_limit') ?: null,
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize') ?: null,
            'post_max_size' => ini_get('post_max_size') ?: null,
            'opcache' => (bool) ($opcache['opcache_enabled'] ?? false),
            'jit' => (bool) ($opcache['jit']['on'] ?? false),
            'extensions' => array_values($extensions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function osInfo(): array
    {
        $osRelease = $this->readFile('/etc/os-release');
        preg_match('/^PRETTY_NAME="?([^"\n]+)"?$/m', (string) $osRelease, $osName);

        $meminfo = (string) $this->readFile('/proc/meminfo');
        $memoryKb = fn (string $field): ?int => preg_match('/^'.$field.':\s+(\d+)\s+kB/m', $meminfo, $match) ? (int) $match[1] * 1024 : null;

        $uptime = $this->readFile('/proc/uptime');
        $load = $this->safe(fn () => sys_getloadavg() ?: null, null);
        $cpuCount = preg_match_all('/^processor\s*:/m', (string) $this->readFile('/proc/cpuinfo'));

        return [
            'name' => $osName[1] ?? php_uname('s').' '.php_uname('r'),
            'kernel' => php_uname('r'),
            'architecture' => php_uname('m'),
            'hostname' => gethostname() ?: null,
            'container' => file_exists('/.dockerenv'),
            'cpu_count' => $cpuCount ?: null,
            'memory_total' => $memoryKb('MemTotal'),
            'memory_available' => $memoryKb('MemAvailable'),
            'load_average' => $load ? implode(' / ', array_map(fn ($value) => number_format($value, 2), $load)) : null,
            'uptime_seconds' => $uptime !== null ? (int) (float) $uptime : null,
            'disk_free' => $this->safe(fn () => (int) disk_free_space(base_path()), null),
            'disk_total' => $this->safe(fn () => (int) disk_total_space(base_path()), null),
            'ffmpeg_version' => $this->binaryVersion((string) config('dvr.ffmpeg_path', '/usr/bin/ffmpeg'), '/ffmpeg version (\S+)/'),
            'comskip' => is_executable((string) config('dvr.comskip_path')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function databaseInfo(): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}", []);
        $driver = $config['driver'] ?? null;
        $connected = $this->safe(fn () => DB::connection()->getPdo() !== null);

        return [
            'driver' => $driver,
            'connected' => $connected,
            'server_version' => $connected ? $this->safe(fn () => DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), null) : null,
            'database' => $driver === 'sqlite' ? basename((string) ($config['database'] ?? '')) : ($config['database'] ?? null),
            'host' => $driver === 'sqlite' ? null : ($config['host'] ?? null),
            'placement' => $driver === 'sqlite' ? 'embedded' : $this->placement($config['host'] ?? null),
            'size' => $connected ? $this->databaseSize($driver, $config) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function cacheInfo(): array
    {
        [$cache, $queue, $session, $broadcasting] = $this->drivers();
        $usesRedis = $this->usesRedis();
        $redisConnected = $usesRedis && $this->safe(fn () => (bool) Redis::connection()->ping());
        $server = $redisConnected ? $this->redisInfo('server') : [];
        $memory = $redisConnected ? $this->redisInfo('memory') : [];

        return [
            'cache_driver' => $cache,
            'queue_driver' => $queue,
            'session_driver' => $session,
            'broadcast_driver' => $broadcasting,
            'redis_used' => $usesRedis,
            'redis_connected' => $redisConnected,
            'redis_version' => $server['redis_version'] ?? null,
            'redis_memory' => $memory['used_memory_human'] ?? null,
            'redis_host' => $usesRedis ? config('database.redis.default.host').':'.config('database.redis.default.port') : null,
            'redis_placement' => $usesRedis ? $this->placement(config('database.redis.default.host')) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function proxyInfo(): array
    {
        if (! config('proxy.proxy_integration_enabled', true)) {
            return ['enabled' => false];
        }

        $proxy = app(M3uProxyService::class);
        $response = $this->safe(fn () => $proxy->getProxyInfo(), ['success' => false, 'info' => []]);
        $info = $response['info'] ?? [];

        return [
            'enabled' => true,
            'mode' => $proxy->mode(),
            'reachable' => (bool) ($response['success'] ?? false),
            'version' => $info['version'] ?? null,
            'python_version' => $info['python_version'] ?? null,
            'ffmpeg_version' => $info['ffmpeg_version'] ?? null,
            'streamlink_version' => $info['streamlink_version'] ?? null,
            'ytdlp_version' => $info['ytdlp_version'] ?? null,
            'hardware_acceleration' => ($info['hardware_acceleration']['enabled'] ?? false)
                ? ($info['hardware_acceleration']['type'] ?? null)
                : null,
        ];
    }

    /**
     * Status of each supervisord-managed container service, or null when the
     * app is not running under the container's supervisord (local dev, bare
     * metal), where these processes are not expected to exist. The supervisord
     * socket is root-only (0700), so this reads /proc instead of asking it.
     *
     * @return array<string, string>|null running, stopped, external or disabled
     */
    protected function services(): ?array
    {
        $commands = $this->processCommandLines();
        if ($commands === null || ! $this->isRunning($commands, 'supervisord')) {
            return null;
        }

        $databaseConfig = config('database.connections.'.config('database.default'), []);
        $usesPostgres = ($databaseConfig['driver'] ?? null) === 'pgsql';
        $proxyEnabled = config('proxy.proxy_integration_enabled', true);

        // Where each service is expected to run: in this container, on another
        // host (external), or not at all (unused).
        $placements = [
            'nginx' => config('app.nginx_enabled') ? 'embedded' : 'external',
            'php_fpm' => 'embedded',
            'queue' => 'embedded',
            'websocket' => 'embedded',
            'scheduler' => 'embedded',
            'postgresql' => $usesPostgres ? $this->placement($databaseConfig['host'] ?? null) : 'unused',
            'redis' => $this->usesRedis() ? $this->placement(config('database.redis.default.host')) : 'unused',
            'm3u_proxy' => $proxyEnabled ? app(M3uProxyService::class)->mode() : 'unused',
            'network_broadcast' => config('app.network_broadcast_enabled') ? 'embedded' : 'unused',
        ];

        $services = [];
        foreach ($placements as $service => $placement) {
            $services[$service] = match (true) {
                $this->isRunning($commands, $service) => 'running',
                $placement === 'external' => 'external',
                $placement === 'unused' => 'disabled',
                default => 'stopped',
            };
        }

        return $services;
    }

    /**
     * Cache, queue, session and broadcasting drivers, in that order.
     *
     * @return list<string|null>
     */
    protected function drivers(): array
    {
        return [
            config('cache.stores.'.config('cache.default').'.driver'),
            config('queue.connections.'.config('queue.default').'.driver'),
            config('session.driver'),
            config('broadcasting.connections.'.config('broadcasting.default').'.driver'),
        ];
    }

    /**
     * Embedded when the host points back at this container.
     */
    protected function placement(?string $host): string
    {
        return in_array(strtolower(trim((string) $host)), ['', 'localhost', '127.0.0.1', '::1'], true)
            ? 'embedded'
            : 'external';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function databaseSize(?string $driver, array $config): ?int
    {
        return $this->safe(fn () => match ($driver) {
            'sqlite' => is_file($config['database'] ?? '') ? filesize($config['database']) : null,
            'pgsql' => (int) DB::scalar('select pg_database_size(current_database())'),
            'mysql', 'mariadb' => (int) DB::scalar('select sum(data_length + index_length) from information_schema.tables where table_schema = database()'),
            default => null,
        }, null);
    }

    /**
     * One INFO section from Redis, flattened (predis nests it under the
     * section name, phpredis does not).
     *
     * @return array<string, mixed>
     */
    protected function redisInfo(string $section): array
    {
        $info = $this->safe(fn () => Redis::connection()->command('info', [$section]), []);

        return is_array($info) ? Arr::get($info, ucfirst($section), $info) : [];
    }

    protected function binaryVersion(string $path, string $pattern): ?string
    {
        if (! is_executable($path)) {
            return null;
        }

        return $this->safe(function () use ($path, $pattern) {
            $result = Process::timeout(5)->run([$path, '-version']);

            return preg_match($pattern, $result->output(), $match) ? $match[1] : null;
        }, null);
    }

    protected function packageVersion(string $package): ?string
    {
        return $this->safe(fn () => InstalledVersions::getPrettyVersion($package), null);
    }

    /**
     * @return list<string>|null
     */
    protected function processCommandLines(): ?array
    {
        if (! is_readable('/proc/self/cmdline')) {
            return null;
        }

        $commands = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $command = trim(str_replace("\0", ' ', (string) $this->readFile($file)));
            if ($command !== '') {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    /**
     * @param  list<string>  $commands
     */
    protected function isRunning(array $commands, string $service): bool
    {
        return preg_grep(self::PROCESS_PATTERNS[$service], $commands) !== [];
    }

    protected function readFile(string $path): ?string
    {
        if (! @is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    protected function safe(callable $callback, mixed $fallback = false): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
