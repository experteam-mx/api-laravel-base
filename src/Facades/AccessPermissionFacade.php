<?php

namespace Experteam\ApiLaravelBase\Facades;


class AccessPermissionFacade extends \Illuminate\Support\Facades\Facade
{

    protected static function getFacadeAccessor()
    {

        return 'AccessPermission';

    }

}
