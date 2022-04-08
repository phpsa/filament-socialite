<?php

namespace DutchCodingCompany\FilamentSocialite\Http\Controllers;

use App\Helpers\GitlabHelper;
use App\Models\User;
use DutchCodingCompany\FilamentSocialite\Models\SocialiteUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Nova\Nova;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class SocialiteLoginController extends Controller
{
    public function redirectToProvider(string $provider)
    {
        $services = config('services', []);
        if (! key_exists($provider, $services)) {
            throw new \Exception(__('Provider does not exist'));
        }

        $scopes = config('services.' . $provider . 'scopes', []);

        return Socialite::with($provider)
            ->scopes($scopes)
            ->redirect();
    }

    public function processCallback(Request $request, string $provider)
    {
        $services = config('services', []);
        if (! key_exists($provider, $services)) {
            throw new \Exception(__('Provider does not exist'));
        }

        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException $exception) {
            return redirect()->route(config('filament.auth.login'))
                ->withErrors([
                    'email' => [
                        __('Login failed, please try again.'),
                    ],
                ]);
        }

        $domains = config('filament-socialite.domain_allowlist', []);
        if (count($domains) > 0) {
            if (! in_array(Str::afterLast($oauthUser->getEmail(), '@'), $domains)) {
                return redirect()->route(config('filament.auth.login'))
                    ->withErrors([
                        'email' => [
                            __('Your email is not part of a domain that is allowed.'),
                        ],
                    ]);
            }
        }

        $socialiteUser = SocialiteUser::where('provider', $provider)
            ->where('provider_id', $oauthUser->getId())
            ->first();
        if ($socialiteUser) {
            $this->guard()->login($socialiteUser->user);

            return redirect()->intended();
        }

        $registration = config('filament-socialite.registration', false);
        $exists = User::whereEmail($oauthUser->getEmail())->exists();
        if (! $registration) {
            abort(403);
        }

        if (!$exists) {
            DB::beginTransaction();
            try {
                $user = User::create(
                    [
                        'name' => $oauthUser->getName(),
                        'email' => $oauthUser->getEmail(),
                        'password' => null,
                    ]
                );
                SocialiteUser::create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_id' => $oauthUser->getId(),
                ]);

                DB::commit();
            } catch (\Throwable $exception) {
                DB::rollBack();
                throw $exception;
            }
        }

        $this->guard()->login($user);

        return redirect()->intended();
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard(config('filament.auth.guard'));
    }
}