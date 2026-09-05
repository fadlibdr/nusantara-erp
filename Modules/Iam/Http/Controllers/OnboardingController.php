<?php

namespace Modules\Iam\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Iam\Http\Requests\UpdateOnboardingRequest;
use Modules\Iam\Http\Resources\UserResource;
use Modules\Iam\Support\OnboardingGuide;

/**
 * The in-app onboarding guide — owner's request of 5 Sep 2026: "on boarding
 * is not working, make it pop-up every user is logged in and create a button
 * to skip the onboarding process also make the choice is remembered".
 *
 * Until then onboarding existed only as docs/ONBOARDING/<role>.md, handed
 * over by hand; the application itself never showed a new employee anything.
 * Both routes act on the caller's own record and need no permission beyond a
 * session: which guide you get is decided by your role, and whether you have
 * read it is nobody else's to set.
 */
class OnboardingController extends ApiController
{
    /**
     * GET iam/me/onboarding — the caller's guide, split into its sections,
     * plus where the caller stands with it.
     *
     * A user holds one role in practice (UserSeeder, PANDUAN-ADMINISTRATOR
     * §3.4); the first is taken when there are several. A role without a
     * guide — a custom role, or an account with no role yet — is a 404 the
     * SPA keeps quiet about when it opens the guide by itself at login, and
     * shows as-is when the person asks for it from the account menu.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $role = $user->getRoleNames()->first();
        $guide = $role === null ? null : OnboardingGuide::load($role);

        if ($guide === null) {
            return $this->error(
                $role === null
                    ? 'Akun Anda belum memegang peran, jadi belum ada panduan onboarding untuk ditampilkan. Minta administrator menetapkan peran Anda.'
                    : "Belum ada panduan onboarding untuk peran {$role}. Panduan yang ada mengikuti kedua belas peran bawaan (docs/ONBOARDING).",
                404,
            );
        }

        return $this->ok($guide + $this->standing($user));
    }

    /**
     * PUT iam/me/onboarding { status } — remember the decision on the user,
     * so it follows them to every browser and device (the shared field
     * tablet is why this is not a localStorage flag). Returns the same shape
     * as auth/me: the SPA replaces its session copy with it and the pop-up
     * logic reads the fresh status without another round trip.
     */
    public function update(UpdateOnboardingRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $status = $request->validated('status');

        // seen_at travels with the status: a reset (null) clears both, so a
        // later "skipped" carries the moment it was skipped, not the first
        // time the guide was ever shown.
        $user->forceFill([
            'onboarding_status' => $status,
            'onboarding_seen_at' => $status === null ? null : now(),
        ])->save();

        return $this->ok(new UserResource($user->load('roles')), match ($status) {
            'skipped' => 'Panduan onboarding dilewati — bisa dibuka lagi dari menu akun.',
            'completed' => 'Panduan onboarding selesai.',
            default => 'Panduan onboarding akan tampil lagi saat Anda masuk berikutnya.',
        });
    }

    /** @return array{status: string|null, seen_at: string|null} */
    private function standing(User $user): array
    {
        return [
            'status' => $user->onboarding_status,
            'seen_at' => $user->onboarding_seen_at?->toJSON(),
        ];
    }
}
