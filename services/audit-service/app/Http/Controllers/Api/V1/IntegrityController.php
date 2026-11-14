<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Services\AuditService;
use App\Services\OutboxRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrityController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function verify(Request $request): JsonResponse
    {
        $result = $this->audit->verifyChain();
        $claims = AuthenticateUser::claims($request);

        // La verification elle-meme est auditee — jamais silencieuse.
        $this->outbox->record(
            'audit.integrity_check_completed',
            'audit_chain',
            (string) Str::uuid7(),
            [
                'valid' => $result['valid'],
                'entries_checked' => $result['entries_checked'],
                'first_invalid_sequence' => $result['first_invalid_sequence'],
                'first_invalid_entry_id' => $result['first_invalid_entry_id'],
                'checked_at' => now()->toIso8601String(),
                'verified_by' => $claims['sub'] ?? null,
            ],
            correlationId: $request->header('X-Correlation-ID'),
            actor: isset($claims['sub'])
                ? ['type' => 'user', 'id' => $claims['sub']]
                : ['type' => 'service', 'id' => (string) Str::uuid7()],
        );

        return response()->json([
            'data' => [
                'valid' => $result['valid'],
                'entries_checked' => $result['entries_checked'],
                'first_invalid_sequence' => $result['first_invalid_sequence'],
                'first_invalid_entry_id' => $result['first_invalid_entry_id'],
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
