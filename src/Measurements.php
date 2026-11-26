<?php

namespace RenokiCo\HorizonExporter;

use Laravel\Horizon\Contracts\MetricsRepository;

/**
 * The runtime and throughput of every measured job and queue, read once per request.
 *
 * Bound scoped in the service provider: Octane and the queue worker flush scoped instances at each new
 * request or job, so one scrape reads Redis once for its runtime and throughput metrics together, and
 * nothing carries over to the next scrape.
 */
class Measurements
{
    /**
     * How many jobs or queues one Redis pipeline reads.
     *
     * Large enough that a scrape is a few round trips, small enough that the replies
     * held at once stay well under a megabyte.
     *
     * @var int
     */
    public const PER_PIPELINE = 200;

    /**
     * What this request has read, by kind.
     *
     * @var array<string, array<string, array{runtime: float, throughput: int}>>
     */
    protected $read = [];

    /**
     * The runtime and throughput of every measured job or queue, by name.
     *
     * The runtime is the average of the snapshots' runtimes and the current one, as Collection::average('runtime')
     * computed it (nulls left out, summed in order); the throughput is the current one plus each snapshot's.
     *
     * @param  string  $kind  Either "job" or "queue".
     * @return array<string, array{runtime: float, throughput: int}>
     */
    public function of(string $kind): array
    {
        return $this->read[$kind] ??= $this->measure($kind);
    }

    /**
     * Read one kind in pipelines, instead of Horizon's three round trips per job or queue
     * (runtimeForJob(), throughputForJob(), snapshotsForJob()). Keys and decoding are Horizon's own.
     *
     * @param  string  $kind
     * @return array<string, array{runtime: float, throughput: int}>
     */
    protected function measure(string $kind): array
    {
        $metrics = app(MetricsRepository::class);

        $names = $kind === 'job' ? $metrics->measuredJobs() : $metrics->measuredQueues();

        $withSnapshots = (bool) config('horizon-exporter.include_snapshots');

        $measured = [];

        foreach (array_chunk($names, static::PER_PIPELINE) as $chunk) {
            $replies = $metrics->connection()->pipeline(function ($pipe) use ($chunk, $kind, $withSnapshots) {
                foreach ($chunk as $name) {
                    $pipe->hmget("{$kind}:{$name}", ['runtime', 'throughput']);

                    if ($withSnapshots) {
                        $pipe->zrange("snapshot:{$kind}:{$name}", 0, -1);
                    }
                }
            });

            foreach ($chunk as $i => $name) {
                [$runtime, $throughput] = array_values((array) $replies[$withSnapshots ? 2 * $i : $i]);

                $sum = $count = 0;
                $throughput = (int) $throughput;

                foreach ($withSnapshots ? (array) $replies[2 * $i + 1] : [] as $snapshot) {
                    $snapshot = (object) json_decode($snapshot, true);

                    if (isset($snapshot->runtime)) {
                        $sum += $snapshot->runtime;
                        $count++;
                    }

                    $throughput += $snapshot->throughput;
                }

                $measured[$name] = ['runtime' => ($sum + (float) $runtime) / ($count + 1), 'throughput' => $throughput];
            }
        }

        return $measured;
    }
}
