<?php

use App\Actions\Monitoring\GetMetrics;
use App\Enums\ServiceStatus;
use App\Models\Metric;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

test('visit metrics', function () {
    $this->actingAs($this->user);

    Service::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'vito-agent',
        'type' => 'monitoring',
        'version' => 'latest',
        'status' => ServiceStatus::READY,
    ]);

    $this->get(route('monitoring', $this->server))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('monitoring/index'));
});

test('update data retention', function () {
    $this->actingAs($this->user);

    Service::factory()->create([
        'server_id' => $this->server->id,
        'name' => 'vito-agent',
        'type' => 'monitoring',
        'version' => 'latest',
        'status' => ServiceStatus::READY,
    ]);

    $this->patch(route('monitoring.update', $this->server), [
        'data_retention' => 365,
    ])
        ->assertSessionDoesntHaveErrors();

    $this->assertDatabaseHas('services', [
        'server_id' => $this->server->id,
        'type' => 'monitoring',
        'type_data->data_retention' => 365,
    ]);
});

test('monitoring json returns current and history', function () {
    $this->actingAs($this->user);

    Metric::factory()->create([
        'server_id' => $this->server->id,
        'cpu_cores' => 4,
        'cpu_physical_cores' => 2,
        'cpu_usage_percent' => 12.34,
        'memory_total' => 2000,
        'memory_used' => 1010,
        'memory_free' => 990,
        'swap_used_percent' => 0,
        'disk_total' => 1000,
        'disk_used' => 250,
        'disk_free' => 750,
        'uptime_seconds' => 3600,
        'reboot_required' => false,
    ]);

    $response = $this->getJson(route('monitoring.json', ['server' => $this->server, 'period' => '10m']));

    $response->assertSuccessful()
        ->assertJsonStructure([
            'current' => [
                'date',
                'cpu_cores',
                'cpu_physical_cores',
                'cpu_usage_percent',
                'memory_used_percent',
                'swap_used_percent',
                'disk_used_percent',
                'uptime_seconds',
                'reboot_required',
            ],
            'history' => [],
        ])
        ->assertJsonPath('current.cpu_cores', 4)
        ->assertJsonPath('current.cpu_usage_percent', 12.34)
        ->assertJsonPath('current.disk_used_percent', 25)
        ->assertJsonPath('current.memory_used_percent', 50.5)
        ->assertJsonPath('current.uptime_seconds', 3600);

    $history = $response->json('history');
    expect($history)->toBeArray();
    expect($history)->not->toBeEmpty();
    foreach (['cpu_cores', 'cpu_physical_cores', 'uptime_seconds', 'reboot_required', 'date_interval'] as $excluded) {
        $this->assertArrayNotHasKey($excluded, $history[0]);
    }
    expect($history[0])->toHaveKey('load');
    expect($history[0])->toHaveKey('cpu_usage_percent');
    expect($history[0])->toHaveKey('disk_used_percent');
    expect($history[0]['disk_used_percent'])->toEqual(25.0);
    expect($history[0])->toHaveKey('memory_used_percent');
    expect($history[0]['memory_used_percent'])->toEqual(50.5);

    foreach (['load', 'memory_total', 'cpu_usage_percent', 'disk_used_percent'] as $key) {
        if ($history[0][$key] !== null) {
            expect($history[0][$key])->toBeNumeric("history.0.{$key} should be numeric (not a string)");
            $this->assertIsNotString($history[0][$key], "history.0.{$key} must not be serialised as a JSON string");
        }
    }
});

test('monitoring json returns null current when no metrics', function () {
    $this->actingAs($this->user);

    $this->getJson(route('monitoring.json', ['server' => $this->server, 'period' => '10m']))
        ->assertSuccessful()
        ->assertJsonPath('current', null)
        ->assertJsonPath('history', []);
});

test('monitoring json defaults period when missing', function () {
    $this->actingAs($this->user);

    $this->getJson(route('monitoring.json', ['server' => $this->server]))
        ->assertSuccessful()
        ->assertJsonPath('current', null)
        ->assertJsonPath('history', []);
});

test('monitoring history generates MySQL safe grouped SQL', function (array $input, string $format) {
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));

    config()->set('database.connections.metrics_mysql', [
        'driver' => 'mysql',
        'database' => 'metrics_sql_test',
        'prefix' => '',
    ]);
    $default = DB::getDefaultConnection();
    $connection = DB::connection('metrics_mysql');
    $connection->setPdo(DB::connection()->getPdo());

    try {
        DB::setDefaultConnection('metrics_mysql');
        $queries = $connection->pretend(fn () => app(GetMetrics::class)->filter($this->server, $input));
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('metrics_mysql');
    }

    expect($queries)->toHaveCount(1);
    $sql = $queries[0]['query'];

    expect($sql)
        ->toContain("DATE_FORMAT(`created_at`, '{$format}') as `date`")
        ->toContain('ROUND(AVG(`load`), 2) as `load`')
        ->toContain('MAX(`oom_kill_count`) as `oom_kill_count`')
        ->toContain('group by `date` order by `date` asc')
        ->not->toContain('strftime', 'AVG(load)', 'created_at as date', '`created_at` as `date`', 'date_interval');
})->with([
    'ten minutes' => [['period' => '10m'], '%Y-%m-%d %H:%i:00'],
    'one hour' => [['period' => '1h'], '%Y-%m-%d %H:%i:00'],
    'twelve hours' => [['period' => '12h'], '%Y-%m-%d %H:00:00'],
    'one day' => [['period' => '1d'], '%Y-%m-%d %H:00:00'],
    'seven days' => [['period' => '7d'], '%Y-%m-%d 00:00:00'],
    'custom day' => [['period' => 'custom', 'from' => '2026-10-23', 'to' => '2026-10-23'], '%Y-%m-%d %H:00:00'],
    'custom days' => [['period' => 'custom', 'from' => '2026-10-21', 'to' => '2026-10-23'], '%Y-%m-%d 00:00:00'],
]);

test('monitoring json aggregates SQLite history using bucket dates', function (string $period, array $dates, array $buckets) {
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
    $this->actingAs($this->user);

    foreach ($dates as $index => $date) {
        Metric::factory()->create([
            'server_id' => $this->server->id,
            'created_at' => $date,
            'load' => [1.25, 2.5, 8.75][$index],
            'memory_total' => 2000,
            'memory_used' => [500, 1000, 1500][$index],
            'disk_total' => 1000,
            'disk_used' => [100, 300, 500][$index],
            'oom_kill_count' => [1, 3, 2][$index],
        ]);
    }

    $response = $this->getJson(route('monitoring.json', ['server' => $this->server, 'period' => $period]));

    $response->assertSuccessful()
        ->assertJsonCount(2, 'history')
        ->assertJsonPath('history.0.date', $buckets[0])
        ->assertJsonPath('history.1.date', $buckets[1])
        ->assertJsonPath('history.0.load', 1.88)
        ->assertJsonPath('history.1.load', 8.75)
        ->assertJsonPath('history.0.memory_used_percent', 37.5)
        ->assertJsonPath('history.0.disk_used_percent', 20)
        ->assertJsonPath('history.0.oom_kill_count', 3)
        ->assertJsonMissingPath('history.0.date_interval');
})->with([
    'minute' => ['10m', ['2026-10-23 11:58:10', '2026-10-23 11:58:50', '2026-10-23 11:59:10'], ['2026-10-23 11:58', '2026-10-23 11:59']],
    'hour' => ['12h', ['2026-10-23 10:10:10', '2026-10-23 10:50:50', '2026-10-23 11:10:10'], ['2026-10-23 10:00', '2026-10-23 11:00']],
    'day' => ['7d', ['2026-10-21 10:10:10', '2026-10-21 11:10:10', '2026-10-22 10:10:10'], ['2026-10-21 00:00', '2026-10-22 00:00']],
]);
