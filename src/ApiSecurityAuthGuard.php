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

    private $request;
    private $provider;
    private $user;

    public function __construct(UserProvider $provider, Request $request)
    {

        $this->request = $request;
        $this->provider = $provider;
        $this->user = null;

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

        $userData = Redis::get('security.token:' . \request()->bearerToken())
            ?? Redis::get('security.appkey:' . \request()->headers->get('AppKey'));
        $userData = json_decode($userData, true);

        if (empty($userData) ||
            empty($userData['permissions']))
            return false;

        $redisUser = new User();

        $redisUser->fill($userData);

        $redisUser->permissions = $userData['permissions'];
        $redisUser->session = $userData['session'];

        return $redisUser;

    }

    public function id()
    {

        return $this->user()->id;

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

}
