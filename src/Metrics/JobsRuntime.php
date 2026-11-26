<?php

namespace RenokiCo\HorizonExporter\Metrics;

use RenokiCo\HorizonExporter\Measurements;
use RenokiCo\LaravelExporter\GaugeMetric;
use RenokiCo\LaravelExporter\Metric;

class JobsRuntime extends GaugeMetric
{
    /**
     * The group this metric gets shown into.
     *
     * @var string|null
     */
    public static $showsOnGroup = 'horizon-metrics';

    /**
     * Perform the update call on the collector.
     *
     * @return void
     */
    public function update(): void
    {
        foreach (Measurements::read('job', 'runtime') as $job => [$runtime, $snapshots]) {
            // The average of the snapshots' runtimes and the current one, as Collection::average('runtime')
            // computes it (nulls left out, summed in order), without a collection per job.
            $sum = $count = 0;

            foreach ([...$snapshots, (object) ['runtime' => (float) $runtime]] as $snapshot) {
                if (isset($snapshot->runtime)) {
                    $sum += $snapshot->runtime;
                    $count++;
                }
            }

            $this->set(
                value: $sum / $count,
                labels: ['job' => $job],
            );
        }
    }

    /**
     * Get the metric name.
     *
     * @return string
     */
    protected function name(): string
    {
        return 'horizon_job_runtime';
    }

    /**
     * Get the metric help.
     *
     * @return string
     */
    protected function help(): string
    {
        return 'Get total workload runtime by job name.';
    }

    /**
     * Get the metric allowed labels.
     *
     * @return array
     */
    protected function allowedLabels(): array
    {
        return ['job'];
    }
}
