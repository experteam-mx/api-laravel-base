<?php

namespace Experteam\ApiLaravelBase\Facades;

use Illuminate\Support\Facades\Facade;
use Experteam\ApiLaravelBase\ActiveEntities;

class ActiveEntitiesFacade extends Facade
{

    protected static function getFacadeAccessor()
    {

        return ActiveEntities::class;

    }

}
