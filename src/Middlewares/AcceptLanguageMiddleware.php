<?php

namespace Experteam\ApiLaravelBase\Middlewares;

use App;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AcceptLanguageMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        app()->setLocale(
            $this->parseHttpLocale($request)
        );

        return $next($request);
    }

    // @see: https://github.com/orkhanahmadov/laravel-accept-language-middleware
    private function parseHttpLocale(Request $request): string
    {
        $accepted = explode(',', $request->server('HTTP_ACCEPT_LANGUAGE', ''));

        $selected = Collection::make($accepted)
            ->map(function ($config) {
                $segments = explode(';', $config);

                $mapping['locale'] = trim($segments[0]);

                if (empty($segments[1])) {
                    $mapping['factor'] = 1;
                } else {
                    [, $factor] = explode('=', $segments[1]);

                    $mapping['factor'] = $factor;
                }

                return $mapping;
            })
            ->sortByDesc(fn($config) => $config['factor'])
            ->filter(fn($config) => in_array($config['locale'], $this->enabledLocales()));

        return $selected->isEmpty()
            ? App::getFallbackLocale()
            : $selected->first()['locale'];
    }

    private function enabledLocales(): array
    {
        $dir = base_path('lang');
        $enabled = array_diff(scandir($dir), array('..', '.'));

        return [...$enabled];
    }
}
