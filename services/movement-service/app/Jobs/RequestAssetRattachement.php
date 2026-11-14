<?php

namespace App\Jobs;

use App\Models\MovementOperation;
use App\Services\AssetGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Demande a Asset la confirmation du rattachement (TEC-017).
 * Idempotent : la cle d'idempotence est stable
 * (operation + bien) donc un retry ne duplique pas l'effet cote Asset.
 * En cas d'indisponibilite d'Asset, le job est retente par la queue ;
 * l'operation reste en pending_asset_confirmation.
 */
class RequestAssetRattachement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120, 300, 600, 900];

    public function __construct(
        public readonly string $operationId,
        public readonly string $assetId,
    ) {}

    public function handle(AssetGateway $gateway): void
    {
        $operation = MovementOperation::find($this->operationId);
        if ($operation === null || $operation->status->value !== 'pending_asset_confirmation') {
            return; // annule ou deja cloturee : rien a demander
        }

        // Deja confirme ? (rejeu du job apres coup)
        $confirmations = $operation->asset_confirmations ?? [];
        if (isset($confirmations[$this->assetId])) {
            return;
        }

        $gateway->confirmRattachement(
            $this->assetId,
            (string) $operation->destination_institution_id,
            $operation->destination_unit_id,
            "rattachement:{$this->operationId}:{$this->assetId}",
            (string) Str::uuid7(),
        );
    }
}
