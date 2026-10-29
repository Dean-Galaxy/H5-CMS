<?php

namespace App\Modules\Identity\Services;

use App\Contracts\BehaviorEventRecorder;
use App\Modules\Identity\Models\Viewer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** Identity facts only. Consent, anonymous subjects and attribution remain owned by 05. */
final class ViewerBehavior
{
    private const LAST_VIEWER = 'identity.last_viewer';

    public function preparingLogin(Viewer $viewer): void
    {
        $request = request();
        if (! $request->hasSession()) {
            return;
        }
        $previous = $request->session()->get(self::LAST_VIEWER);
        if ($previous !== null && $previous !== $viewer->id) {
            if (! $this->clear()) {
                // A broken consumer cannot leave another viewer's opaque context attached.
                // Retain only Identity's own login state; never enumerate or read 05's keys.
                $session = $request->session();
                $login = $session->only([Auth::guard('web')->getName(), 'password_hash_web',
                    'identity.version.web', 'identity.return_to', 'identity.pending_verify']);
                $session->invalidate();
                $session->put($login);
                $session->regenerateToken();
            }
        }
        // This is the authenticated Identity account, not an analytics subject or source ID.
        $request->session()->put(self::LAST_VIEWER, $viewer->id);
    }

    public function registered(string $viewerId): void
    {
        $this->record('registration_succeeded', ['viewer_id' => $viewerId], 'identity:registration:'.$viewerId);
    }

    public function loggedIn(Viewer $viewer): void
    {
        $this->record('login_succeeded', ['viewer_id' => $viewer->id], $this->sessionKey('login', $viewer->id));
    }

    public function verified(Viewer $viewer): void
    {
        $this->record('email_verified', ['viewer_id' => $viewer->id], 'identity:verification:'.$viewer->id);
    }

    public function failed(string $operation, string $errorCode, Request $request): void
    {
        $requestId = $request->attributes->get('request_id');
        if (! is_string($requestId) || ! in_array($operation, ['registration', 'login'], true)) {
            return;
        }
        $this->record($operation.'_failed', ['error_code' => $errorCode], 'identity:'.$operation.':failed:'.$requestId);
    }

    public function logout(Viewer $viewer): void
    {
        // Capture the real account and browser context before Auth::logout / invalidate.
        // A false return or an exception must not prevent the separate clear attempt.
        $this->record('logout', ['viewer_id' => $viewer->id], $this->sessionKey('logout', $viewer->id));
        $this->clear();
    }

    private function sessionKey(string $action, string $viewerId): string
    {
        // Do not persist a raw session credential; one fact per authenticated session.
        return 'identity:'.$action.':'.$viewerId.':'.hash('sha256', request()->session()->getId());
    }

    private function clear(): bool
    {
        try {
            app(BehaviorEventRecorder::class)->clearBrowserContext();

            return true;
        } catch (Throwable) {
            // logout still invalidates every session attribute even if the collector fails.
            return false;
        }
    }

    private function record(string $event, array $properties, string $key): void
    {
        try {
            app(BehaviorEventRecorder::class)->record($event, $properties, $key);
        } catch (Throwable) {
            // Best effort identity analytics must not change authentication or its result.
            // Do not log exceptions which may contain collector payload or credentials.
        }
    }
}
