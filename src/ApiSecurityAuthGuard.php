<?php

namespace Experteam\ApiLaravelBase;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class ApiSecurityAuthGuard implements Guard
{

    private $user = null;

    public function __construct(private UserProvider $provider, private Request $request)
    {
    }

    public function guest()
    {

        return !$this->check();

    }

    public function check()
    {

        return !empty($this->user());

    }

    public function user()
    {
        if ($this->user != null) {
            return $this->user;
        }

        $userData = Redis::get('security.token:' . \request()->bearerToken())
            ?? Redis::get('security.appkey:' . \request()->headers->get('AppKey'));
        $userData = json_decode($userData, true);

        if (empty($userData) ||
            empty($userData['permissions']))
            return false;

        $redisUser = new User();

        $redisUser->fill($userData);
        $redisUser->id = $userData['id'] ?? null;
        $redisUser->username = $userData['username'] ?? null;

        $redisUser->permissions = $userData['permissions'] ?? null;
        $redisUser->session = $userData['session'] ?? null;
        $redisUser->role = $userData['role'] ?? null;

        $this->user = $redisUser;

        return $this->user;

    }

    public function id()
    {

        return $this->user()?->id;

    }

    public function validate(array $credentials = [])
    {

        return Redis::has('security.token:' . \request()->bearerToken());

    }

    public function setUser(Authenticatable $user)
    {

        $this->user = $user;

        return $this;

    }

    public function hasUser()
    {
        return $this->check();
    }


}
