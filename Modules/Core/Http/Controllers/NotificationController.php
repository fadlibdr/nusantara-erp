<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Core\Services\NotificationService;

/**
 * The signed-in user's own inbox.
 *
 * No permission middleware, deliberately: a notification is addressed to one
 * person and every query here is scoped to $request->user(). There is no
 * parameter that could widen it to somebody else's rows, which is a stronger
 * guarantee than a permission check on an endpoint that accepts a user id.
 */
class NotificationController extends ApiController
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok(
            $this->notifications->forUser($user, $request->boolean('unread'), $request->integer('limit') ?: 50),
            null,
            ['unread' => $this->notifications->unreadCount($user)],
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->ok(['unread' => $this->notifications->unreadCount($request->user())]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['sometimes', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'all' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        $marked = ($data['all'] ?? false)
            ? $this->notifications->markAllRead($user)
            : $this->notifications->markRead($user, $data['ids'] ?? []);

        return $this->ok(
            ['marked' => $marked, 'unread' => $this->notifications->unreadCount($user)],
            $marked === 1 ? '1 notifikasi ditandai dibaca.' : "{$marked} notifikasi ditandai dibaca."
        );
    }
}
