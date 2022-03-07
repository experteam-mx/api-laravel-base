<?php

namespace Experteam\ApiLaravelBase;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ActiveEntitiesChecker
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
        foreach ($fromRedis as [$key, $id]) {
            $levelActives = json_decode(Redis::hget($key, $id));

            if (is_null($levelActives))
                continue;

            $actives = empty($actives) ? $levelActives : array_intersect($actives, $levelActives);
        }

        return $actives;
    }
}