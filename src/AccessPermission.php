<?php

namespace Experteam\ApiLaravelBase;

use Illuminate\Auth\Access\AuthorizationException;

class AccessPermission
{

    public function validatePermission(array $dataPermissons){
        $validate = false;
        foreach ($dataPermissons as $permisson){
            $namePermison = $permisson;
            $search = array_search($permisson,\Illuminate\Support\Facades\Auth::user()->permissions);
            if(is_numeric($search))
                $validate = true;
                break;
        }

        if(!$validate){
            throw_if(!in_array($namePermison, \Illuminate\Support\Facades\Auth::user()->permissions), new AuthorizationException());
        }

    }
}
