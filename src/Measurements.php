<?php

namespace RenokiCo\HorizonExporter;

use Laravel\Horizon\Contracts\MetricsRepository;

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
     * Read one field and the snapshots of every measured job or queue, a pipeline at a time.
     *
     * This replaces two round trips per job and per queue (Horizon's runtimeForJob() or
     * throughputForJob(), then snapshotsForJob()), which made a scrape of a few hundred
     * measured jobs cost thousands of round trips. Keys and decoding are Horizon's own.
     *
     * @param  string  $kind  Either "job" or "queue".
     * @param  string  $field  Either "runtime" or "throughput".
     * @return \Generator<string, array{0: mixed, 1: array<int, object>}> The raw field value and the snapshots, by name.
     */
    public static function read(string $kind, string $field): \Generator
    {
        $metrics = app(MetricsRepository::class);

        $names = $kind === 'job' ? $metrics->measuredJobs() : $metrics->measuredQueues();

        $withSnapshots = (bool) config('horizon-exporter.include_snapshots');

        foreach (array_chunk($names, static::PER_PIPELINE) as $chunk) {
            $replies = $metrics->connection()->pipeline(function ($pipe) use ($chunk, $kind, $field, $withSnapshots) {
                foreach ($chunk as $name) {
                    $pipe->hget("{$kind}:{$name}", $field);

                    if ($withSnapshots) {
                        $pipe->zrange("snapshot:{$kind}:{$name}", 0, -1);
                    }
                }
            });

            foreach ($chunk as $i => $name) {
                yield $name => $withSnapshots
                    ? [$replies[2 * $i], array_map(fn ($snapshot) => (object) json_decode($snapshot, true), (array) $replies[2 * $i + 1])]
                    : [$replies[$i], []];
            }
        }
    }
}
