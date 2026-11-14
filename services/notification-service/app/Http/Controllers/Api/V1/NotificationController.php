<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\UpdatePreferences;
use App\Http\Resources\NotificationDetailResource;
use App\Http\Resources\NotificationPreferenceResource;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\NotificationTemplateResource;
use App\Models\AppNotification;
use App\Models\NotificationTemplate;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request)
    {
        $accountId = $this->accountId($request);

        $query = AppNotification::where('recipient_account_id', $accountId)
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $query->where('is_read', false);
        }
        if ($request->query('priority') !== null) {
            $query->where('priority', $request->query('priority'));
        }
        if ($request->query('acknowledged') !== null) {
            $request->boolean('acknowledged')
                ? $query->whereNotNull('acknowledged_at')
                : $query->whereNull('acknowledged_at');
        }

        return NotificationResource::collection(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $accountId = $this->accountId($request);
        $base = AppNotification::where('recipient_account_id', $accountId);

        return response()->json([
            'data' => [
                'unread' => (clone $base)->where('is_read', false)->count(),
                'critical_unacknowledged' => (clone $base)
                    ->where('priority', 'critical')
                    ->whereNull('acknowledged_at')
                    ->count(),
            ],
        ]);
    }

    public function show(Request $request, AppNotification $notification): NotificationDetailResource
    {
        $accountId = $this->accountId($request);
        $notification = $this->notifications->markRead($notification, $accountId);

        return new NotificationDetailResource($notification->load('deliveries'));
    }

    public function read(Request $request, AppNotification $notification): NotificationResource
    {
        $accountId = $this->accountId($request);

        return new NotificationResource($this->notifications->markRead($notification, $accountId));
    }

    public function acknowledge(Request $request, AppNotification $notification): NotificationResource
    {
        $accountId = $this->accountId($request);
        $updated = $this->notifications->acknowledge(
            $notification,
            $accountId,
            $request->input('comment'),
            $request->header('X-Correlation-ID'),
        );

        return new NotificationResource($updated);
    }

    public function deliveries(Request $request, AppNotification $notification): JsonResponse
    {
        $accountId = $this->accountId($request);
        if ($notification->recipient_account_id !== $accountId) {
            throw new DomainException('FORBIDDEN', 'Notification d\'un autre compte.');
        }

        return response()->json([
            'data' => $notification->deliveries()->orderBy('created_at')->get()->map(fn ($d) => [
                'id' => $d->id,
                'notification_id' => $d->notification_id,
                'channel' => $d->channel->value,
                'status' => $d->status->value,
                'attempts' => $d->attempts,
                'last_error' => $d->last_error,
                'sent_at' => $d->sent_at?->toIso8601String(),
                'delivered_at' => $d->delivered_at?->toIso8601String(),
                'created_at' => $d->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function preferences(Request $request)
    {
        $accountId = $this->accountId($request);

        return NotificationPreferenceResource::collection(
            collect($this->notifications->preferencesFor($accountId))
        );
    }

    public function updatePreferences(UpdatePreferences $request): JsonResponse
    {
        $accountId = $this->accountId($request);
        $items = collect($request->validated('preferences'))
            ->map(fn ($p) => [
                'event_type' => $p['event_type'],
                'channel' => $p['channel'],
                'enabled' => (bool) $p['enabled'],
            ])->all();

        $saved = $this->notifications->updatePreferences($accountId, $items);

        return response()->json([
            'data' => collect($saved)->map(fn ($p) => (new NotificationPreferenceResource($p))->resolve($request))->all(),
        ]);
    }

    public function templates(Request $request)
    {
        return NotificationTemplateResource::collection(
            NotificationTemplate::where('is_active', true)->orderBy('code')->get()
        );
    }

    private function accountId(Request $request): string
    {
        return AuthenticateUser::claims($request)['sub']
            ?? throw new DomainException('UNAUTHENTICATED', 'Authentification requise.');
    }
}
