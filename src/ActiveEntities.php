<?php

namespace Experteam\ApiLaravelBase;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ActiveEntities
{

    public function check(string $modelClass, array $session = [])
    {
        $_modelClass = Str::camel($modelClass);
        $prefix = "companies.$_modelClass:";

        if (empty($session))
            $session = \Illuminate\Support\Facades\Auth::user()->session;

        $fromRedis = [
            [$prefix . 'GLOBAL', 0],
            [$prefix . 'Country', $session['country_id'] ?? null],
            [$prefix . 'CompanyCountry', $session['company_country_id'] ?? null],
            [$prefix . 'Location', $session['location_id'] ?? null],
            [$prefix . 'LocationEmployee', $session['location_employee_id'] ?? null],
            [$prefix . 'Installation', $session['installation_id'] ?? null],
        ];

        $actives = [];
        $inactives = [];

        foreach ($fromRedis as [$key, $id]) {
            $levelConfigured = json_decode(Redis::hget($key, $id), true);

            if (is_null($levelConfigured))
                continue;

            $levelActives = array_keys(array_filter($levelConfigured, function($v) { return $v; }));
            $actives = empty($actives) ? $levelActives : array_intersect($actives, $levelActives);

            $levelInactives = array_keys(array_filter($levelConfigured, function($v) { return !$v; }));
            $inactives = empty($inactives) ? $levelInactives : array_intersect($inactives, $levelInactives);
        }

        return array_diff($actives, $inactives);
    }
}