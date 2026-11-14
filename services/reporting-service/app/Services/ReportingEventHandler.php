<?php

namespace App\Services;

/**
 * Consommateur principal : Reporting ingere TOUS les evenements
 * metier (binding '#') pour alimenter projections et indicateurs.
 * Le dedoublonnage est assure par la cle event_id (ingested_events).
 */
class ReportingEventHandler
{
    public function __construct(private readonly ReportingService $reporting) {}

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function handle(array $envelope): string
    {
        return $this->reporting->ingest($envelope);
    }
}
