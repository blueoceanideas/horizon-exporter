<?php

namespace RenokiCo\HorizonExporter\Metrics;

use RenokiCo\HorizonExporter\Measurements;
use RenokiCo\LaravelExporter\GaugeMetric;

class QueuesRuntime extends GaugeMetric
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
        foreach (app(Measurements::class)->of('queue') as $queue => $measured) {
            $this->set(
                value: $measured['runtime'],
                labels: ['queue' => $queue],
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
        return 'horizon_queue_runtime';
    }

    /**
     * Get the metric help.
     *
     * @return string
     */
    protected function help(): string
    {
        return 'Get total jobs runtime by queue.';
    }

    /**
     * Get the metric allowed labels.
     *
     * @return array
     */
    protected function allowedLabels(): array
    {
        return ['queue'];
    }
}
