<?php

namespace RenokiCo\HorizonExporter\Test;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MetricsRepository;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use RenokiCo\HorizonExporter\Measurements;
use RenokiCo\HorizonExporter\Metrics\JobsRuntime;
use RenokiCo\HorizonExporter\Metrics\JobsThroughput;
use RenokiCo\HorizonExporter\Metrics\QueuesRuntime;
use RenokiCo\HorizonExporter\Metrics\QueuesThroughput;

class BatchedReadsTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public static function snapshotSettings(): array
    {
        return ['with snapshots' => [true], 'without snapshots' => [false]];
    }

    /**
     * @dataProvider snapshotSettings
     */
    public function test_batched_reads_render_what_the_per_item_reads_rendered(bool $withSnapshots)
    {
        config(['horizon-exporter.include_snapshots' => $withSnapshots]);

        // More jobs than one pipeline reads, so a pipeline boundary is crossed.
        $jobs = $this->measure(Measurements::PER_PIPELINE + 5, 6);

        foreach ($this->perItemMetrics() as $class => $perItem) {
            $batched = $this->render(fn ($registry) => new $class($registry));
            $expected = $this->render($perItem);

            $this->assertSame($expected, $batched, $class);
        }

        $runtime = $this->render(fn ($registry) => new JobsRuntime($registry));
        $this->assertSame($jobs + 1, substr_count($runtime, 'horizon_job_runtime{job='));
    }

    public function test_redis_round_trips_do_not_grow_with_the_measured_jobs()
    {
        $roundTrips = function () {
            $this->app->forgetScopedInstances(); // A new scrape is a new request.
            $calls = 0;
            Redis::connection('horizon')->setEventDispatcher($this->app['events']);
            Event::listen(CommandExecuted::class, function () use (&$calls) {
                $calls++;
            });

            foreach (array_keys($this->perItemMetrics()) as $class) {
                $this->render(fn ($registry) => new $class($registry));
            }

            Event::forget(CommandExecuted::class);

            return $calls;
        };

        $this->measure(5, 2);
        $few = $roundTrips();

        Redis::connection('horizon')->flushdb();
        $this->measure(2 * Measurements::PER_PIPELINE + 10, 2);
        $many = $roundTrips();

        // phpredis reports no pipelines as commands and predis reports each as one, so at most
        // the two job metrics read two more pipelines each; per-job reads would add thousands.
        $this->assertGreaterThan(0, $few);
        $this->assertLessThanOrEqual($few + 4, $many);
    }

    /**
     * Measure jobs and queues through Horizon itself: current values, two snapshots, a job whose
     * second snapshot holds no values, and a job measured after the last snapshot.
     */
    protected function measure(int $jobs, int $queues): int
    {
        $metrics = app(MetricsRepository::class);
        $at = CarbonImmutable::parse('2026-11-26 08:00:00');

        $measure = function (callable $which) use ($metrics, $jobs, $queues) {
            for ($i = 0; $i < $jobs; $i++) {
                if ($which($i)) {
                    for ($n = 0; $n <= $i % 4; $n++) {
                        $metrics->incrementJob("App\\Jobs\\Tenant{$i}Job", 3.25 * ($i % 11) + $n);
                    }
                }
            }

            for ($i = 0; $i < $queues; $i++) {
                if ($which($i)) {
                    $metrics->incrementQueue("queue-{$i}", 7.5 + $i);
                }
            }
        };

        CarbonImmutable::setTestNow($at);
        $measure(fn () => true);
        $metrics->snapshot();

        CarbonImmutable::setTestNow($at->addMinutes(5));
        $measure(fn ($i) => $i % 2 === 0);
        $metrics->snapshot();

        $measure(fn ($i) => $i % 3 === 0);
        $metrics->incrementJob('App\\Jobs\\MeasuredAfterTheLastSnapshot', 12.0);

        return $jobs;
    }

    /**
     * The four metrics as they read Redis before the batched reads: two round trips per job and queue.
     */
    protected function perItemMetrics(): array
    {
        return [
            JobsRuntime::class => fn ($registry) => new class($registry) extends JobsRuntime
            {
                public function update(): void
                {
                    if ($jobs = app(MetricsRepository::class)->measuredJobs()) {
                        foreach ($jobs as $job) {
                            $value = collect(config('horizon-exporter.include_snapshots') ? app(MetricsRepository::class)->snapshotsForJob($job) : [])
                                ->merge([(object) ['runtime' => app(MetricsRepository::class)->runtimeForJob($job)]])
                                ->average('runtime');

                            $this->set(value: $value, labels: ['job' => $job]);
                        }
                    }
                }
            },
            JobsThroughput::class => fn ($registry) => new class($registry) extends JobsThroughput
            {
                public function update(): void
                {
                    if ($jobs = app(MetricsRepository::class)->measuredJobs()) {
                        foreach ($jobs as $job) {
                            $value = app(MetricsRepository::class)->throughputForJob($job);

                            if (config('horizon-exporter.include_snapshots')) {
                                foreach (app(MetricsRepository::class)->snapshotsForJob($job) as $snapshot) {
                                    $value += $snapshot->throughput;
                                }
                            }

                            $this->set(value: $value, labels: ['job' => $job]);
                        }
                    }
                }
            },
            QueuesRuntime::class => fn ($registry) => new class($registry) extends QueuesRuntime
            {
                public function update(): void
                {
                    if ($queues = app(MetricsRepository::class)->measuredQueues()) {
                        foreach ($queues as $queue) {
                            $value = collect(config('horizon-exporter.include_snapshots') ? app(MetricsRepository::class)->snapshotsForQueue($queue) : [])
                                ->merge([(object) ['runtime' => app(MetricsRepository::class)->runtimeForQueue($queue)]])
                                ->average('runtime');

                            $this->set(value: $value, labels: ['queue' => $queue]);
                        }
                    }
                }
            },
            QueuesThroughput::class => fn ($registry) => new class($registry) extends QueuesThroughput
            {
                public function update(): void
                {
                    if ($queues = app(MetricsRepository::class)->measuredQueues()) {
                        foreach ($queues as $queue) {
                            $value = app(MetricsRepository::class)->throughputForQueue($queue);

                            if (config('horizon-exporter.include_snapshots')) {
                                foreach (app(MetricsRepository::class)->snapshotsForQueue($queue) as $snapshot) {
                                    $value += $snapshot->throughput;
                                }
                            }

                            $this->set(value: $value, labels: ['queue' => $queue]);
                        }
                    }
                }
            },
        ];
    }

    protected function render(callable $metric): string
    {
        $registry = new CollectorRegistry(new InMemory);
        $metric($registry)->update();

        return (new RenderTextFormat)->render($registry->getMetricFamilySamples());
    }
}
