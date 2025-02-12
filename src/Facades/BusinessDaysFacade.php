<?php

namespace Experteam\ApiLaravelBase\Facades;


class BusinessDaysFacade extends \Illuminate\Support\Facades\Facade
{

    protected static function getFacadeAccessor()
    {

        return 'BusinessDays';

    }

}
