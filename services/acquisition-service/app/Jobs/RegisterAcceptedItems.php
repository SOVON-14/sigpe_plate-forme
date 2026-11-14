<?php

namespace App\Jobs;

use App\Models\AcquisitionReceptionLine;
use App\Services\AcquisitionService;
use App\Services\AssetGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Enregistre chez Asset les biens acceptes d'une ligne de reception.
 * Regle CDC §4.5 : SEULS les biens acceptes sont transmis a
 * l'enregistrement patrimonial.
 *
 * - n° de serie fournis : un asset par unite acceptee ;
 * - quantite unitaire ou serialisee : un asset par unite ;
 * - quantite non entiere (vrac) : un asset lot avec la quantite en
 *   attribut technique.
 * Idempotent : les asset_ids deja enregistres ne sont pas recrees.
 */
class RegisterAcceptedItems implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120, 300, 600, 900];

    public function __construct(public readonly string $receptionLineId) {}

    public function handle(AssetGateway $gateway, AcquisitionService $acquisitions): void
    {
        $line = AcquisitionReceptionLine::with(['item.request'])->find($this->receptionLineId);
        if ($line === null || (float) $line->quantity_accepted <= 0) {
            return;
        }

        $existing = $line->asset_ids ?? [];
        $qty = (float) $line->quantity_accepted;
        $serials = array_values(array_filter($line->serial_numbers ?? []));
        $item = $line->item;
        $request = $item->request;

        // Cible : un asset par unite entiere acceptee ; au-dela de
        // 200 unites sans serie, on cree un asset "lot".
        $units = $this->assetUnits($qty, count($serials));
        $missing = max(0, $units - count($existing));
        if ($missing === 0) {
            return;
        }

        $created = [];
        for ($i = count($existing); $i < $units; $i++) {
            $serial = $serials[$i] ?? null;
            $payload = [
                'category_id' => $item->category_id,
                'designation' => $item->description.($serial !== null ? ' — n° série '.$serial : ''),
                'description' => $item->specifications,
                'entry_date' => $line->created_at->toDateString(),
                'entry_mode' => 'achat',
                'entry_reference' => $request->reference.' / reception '.$line->reception_id,
                'physical_state' => 'neuf',
                'location' => null,
                'technical_attributes' => array_filter([
                    'acquisition_request_id' => $request->id,
                    'acquisition_item_id' => $item->id,
                    'reception_line_id' => $line->id,
                    'serial_number' => $serial,
                    'quantity' => $units === 1 && $qty != 1 ? $qty : null,
                    'unit' => $item->unit,
                ]),
            ];

            $created[] = $gateway->registerAsset(
                $payload,
                "acq-asset:{$line->id}:{$i}",
                (string) Str::uuid7(),
            );
        }

        $acquisitions->recordRegisteredAssets(
            $line->fresh(),
            $created,
            ['type' => 'service', 'id' => 'acquisition-service'],
        );
    }

    private function assetUnits(float $qty, int $serialCount): int
    {
        // Quantite non entiere -> un seul asset "lot".
        if (floor($qty) !== $qty) {
            return 1;
        }

        // Series fournies : une unite par serie (bornees par la qte).
        if ($serialCount > 0) {
            return min((int) $qty, max($serialCount, 1));
        }

        // Vrac sans serie : un asset par unite, plafonne a 200.
        return $qty >= 1 && $qty <= 200 ? (int) $qty : 1;
    }
}
