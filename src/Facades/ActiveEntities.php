<?php

namespace Experteam\ApiLaravelBase\Facades;


class ActiveEntities extends \Illuminate\Support\Facades\Facade
{

    protected static function getFacadeAccessor()
    {

        return 'ActiveEntitiesChecker';

    }

}
