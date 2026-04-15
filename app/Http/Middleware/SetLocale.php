<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class SetLocale
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $locale = config('app.locale');

        // 1. Check if user is authenticated and has a locale preference
        if (auth()->check() && auth()->user()->locale) {
            $locale = auth()->user()->locale;
        }
        // 2. Check session for fallback (for non-authenticated or temporary changes)
        elseif (Session::has('locale')) {
            $locale = Session::get('locale');
        }
        // 3. Browser detection as a last resort
        else {
            $browserLocale = substr($request->server('HTTP_ACCEPT_LANGUAGE'), 0, 2);
            if (in_array($browserLocale, ['en', 'fr'])) {
                $locale = $browserLocale;
            }
        }

        App::setLocale($locale);

        // Ensure session remains updated
        if (Session::get('locale') !== $locale) {
            Session::put('locale', $locale);
        }

        return $next($request);
    }
}
