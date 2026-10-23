<?php

namespace App\Actions\Monitoring;

use App\Models\Metric;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use stdClass;

class GetMetrics
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{current: ?array<string, mixed>, history: Collection<int, stdClass>}
     */
    public function filter(Server $server, array $input): array
    {
        $input = array_merge(['period' => '10m'], $input);

        $this->validate($input);

        if (isset($input['from'])) {
            $input['from'] = Carbon::parse($input['from'])->format('Y-m-d').' 00:00:00';
        }

        if (isset($input['to'])) {
            $input['to'] = Carbon::parse($input['to'])->format('Y-m-d').' 23:59:59';
        }

        return [
            'current' => $this->current($server),
            'history' => $this->metrics(
                server: $server,
                fromDate: $this->getFromDate($input),
                toDate: $this->getToDate($input),
                interval: $this->getInterval($input)
            ),
        ];
    }

    /**
     * @return ?array<string, mixed>
     */
    private function current(Server $server): ?array
    {
        /** @var ?Metric $latest */
        $latest = $server->metrics()->latest('id')->first();

        if (! $latest) {
            return null;
        }

        $diskUsedPercent = $latest->disk_total > 0
            ? round(($latest->disk_used / $latest->disk_total) * 100, 2)
            : null;

        $memoryUsedPercent = $latest->memory_total > 0
            ? round(($latest->memory_used / $latest->memory_total) * 100, 2)
            : null;

        return [
            'date' => $latest->created_at->format('Y-m-d H:i:s'),
            'cpu_cores' => $latest->cpu_cores,
            'cpu_physical_cores' => $latest->cpu_physical_cores,
            'cpu_usage_percent' => $latest->cpu_usage_percent,
            'memory_used_percent' => $memoryUsedPercent,
            'swap_used_percent' => $latest->swap_used_percent,
            'disk_used_percent' => $diskUsedPercent,
            'uptime_seconds' => $latest->uptime_seconds,
            'reboot_required' => $latest->reboot_required,
        ];
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function metrics(
        Server $server,
        Carbon $fromDate,
        Carbon $toDate,
        string $interval
    ): Collection {
        $query = Metric::query()->toBase();
        $grammar = $query->getGrammar();
        $createdAt = $grammar->wrap('created_at');
        $bucket = $grammar instanceof MySqlGrammar
            ? "DATE_FORMAT({$createdAt}, '".str_replace('%M', '%i', $interval)."')"
            : "strftime('{$interval}', {$createdAt})";
        $floatFields = [
            'load', 'memory_total', 'memory_used', 'memory_free',
            'disk_total', 'disk_used', 'disk_free',
            'cpu_usage_percent', 'cpu_steal_percent',
            'swap_total', 'swap_used', 'swap_free', 'swap_used_percent',
        ];

        $query->selectRaw($bucket.' as '.$grammar->wrap('date'));
        foreach ($floatFields as $field) {
            $column = $grammar->wrap($field);
            $precision = in_array($field, ['swap_total', 'swap_used', 'swap_free']) ? 0 : 2;
            $query->selectRaw("ROUND(AVG({$column}), {$precision}) as {$column}");
        }
        $oomKillCount = $grammar->wrap('oom_kill_count');

        return $query
            ->where('server_id', $server->id)
            ->whereBetween('created_at', [$fromDate->format('Y-m-d H:i:s'), $toDate->format('Y-m-d H:i:s')])
            ->selectRaw("MAX({$oomKillCount}) as {$oomKillCount}")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) use ($floatFields): stdClass {
                foreach ($floatFields as $key) {
                    $item->{$key} = $item->{$key} !== null ? (float) $item->{$key} : null;
                }
                $item->oom_kill_count = $item->oom_kill_count !== null ? (int) $item->oom_kill_count : null;
                $item->date = Carbon::parse($item->date)->format('Y-m-d H:i');
                $item->disk_used_percent = ($item->disk_total ?? 0) > 0
                    ? round(($item->disk_used / $item->disk_total) * 100, 2)
                    : null;
                $item->memory_used_percent = ($item->memory_total ?? 0) > 0
                    ? round(($item->memory_used / $item->memory_total) * 100, 2)
                    : null;

                return $item;
            });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function getFromDate(array $input): Carbon
    {
        if ($input['period'] === 'custom') {
            return new Carbon($input['from']);
        }

        return Carbon::parse('-'.convert_time_format($input['period']));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function getToDate(array $input): Carbon
    {
        if ($input['period'] === 'custom') {
            return new Carbon($input['to']);
        }

        return Carbon::now();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function getInterval(array $input): string
    {
        if ($input['period'] === 'custom') {
            $from = new Carbon($input['from']);
            $to = new Carbon($input['to']);
            $periodInHours = $from->diffInHours($to);
        }

        if (! isset($periodInHours)) {
            $periodInHours = Carbon::parse(
                convert_time_format($input['period'])
            )->diffInHours();
        }

        if (abs($periodInHours) <= 1) {
            return '%Y-%m-%d %H:%M:00';
        }

        if (abs($periodInHours) <= 24) {
            return '%Y-%m-%d %H:00:00';
        }

        return '%Y-%m-%d 00:00:00';
    }

    private function validate(array $input): void
    {
        $isCustom = ($input['period'] ?? null) === 'custom';

        $rules = [
            'period' => [
                'required',
                Rule::in([
                    '10m',
                    '30m',
                    '1h',
                    '12h',
                    '1d',
                    '7d',
                    'custom',
                ]),
            ],
            'from' => array_filter([$isCustom ? 'required' : 'nullable', 'date', $isCustom ? 'before_or_equal:to' : null]),
            'to' => array_filter([$isCustom ? 'required' : 'nullable', 'date', $isCustom ? 'after_or_equal:from' : null]),
        ];

        Validator::make($input, $rules)->validate();
    }
}
