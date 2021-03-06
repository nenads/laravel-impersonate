<?php

namespace Lab404\Impersonate\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;

class ImpersonateManager
{
    /**
     * @var Application
     */
    private $app;

    /**
     * UserFinder constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @param   int $id
     * @param   string|null $guardName
     * @return  Model
     */
    public function findUserById($id, $guardName = null)
    {
      if($guardName)
      {
          $userProvider = $this->app['config']->get('auth.guards.'. $guardName .'.provider');
          $model = $this->app['config']->get('auth.providers.'. $userProvider .'.model');

          if(!$model)
            throw new \Exception("Auth guard doesn not exist.", 1);

      } else {
        $model = $this->app['config']->get('auth.providers.users.model');
      }

        $user = call_user_func([
            $model,
            'findOrFail'
        ], $id);

        return $user;
    }

    /**
     * @return bool
     */
    public function isImpersonating()
    {
        return session()->has($this->getSessionKey());
    }

    /**
     * @param   void
     * @return  int|null
     */
    public function getImpersonatorId()
    {
        return session($this->getSessionKey(), null);
    }

    /**
    * @return string|null
    */
   public function getImpersonatorGuardName()
   {
       return session($this->getSessionGuard(), null);
   }

   /**
   * @return string|null
   */
  public function getImpersonatorGuardUsingName()
  {
      return session($this->getSessionGuardUsing(), null);
  }

    /**
     * @param Model $from
     * @param Model $to
     * @param   string|null $guardName
     * @return bool
     */
    public function take($from, $to, $guardName = null)
    {
        try {
            session()->put(config('laravel-impersonate.session_key'), $from->getKey());
            session()->put(config('laravel-impersonate.session_guard'), $this->getCurrentAuthGuardName());
            session()->put(config('laravel-impersonate.session_guard_using'), $guardName);

            $this->app['auth']->guard($this->getCurrentAuthGuardName())->quietLogout();
            $this->app['auth']->guard($guardName)->quietLogin($to);

        } catch (\Exception $e) {

            unset($e);
            return false;
        }

        $this->app['events']->fire(new TakeImpersonation($from, $to));

        return true;
    }

    /**
     * @return  bool
     */
    public function leave()
    {
        try {

            $impersonated = $this->app['auth']->guard($this->getImpersonatorGuardUsingName())->user();
            $impersonator = $this->findUserById($this->getImpersonatorId(), $this->getImpersonatorGuardName());

            $this->app['auth']->guard($this->getCurrentAuthGuardName())->quietLogout();
            $this->app['auth']->guard($this->getImpersonatorGuardName())->quietLogin($impersonator);

            $this->clear();

        } catch (\Exception $e) {
          dd($e);
            unset($e);
            return false;
        }

        $this->app['events']->fire(new LeaveImpersonation($impersonator, $impersonated));

        return true;
    }

    /**
     * @return void
     */
    public function clear()
    {
        session()->forget($this->getSessionKey());
        session()->forget($this->getSessionGuard());
        session()->forget($this->getSessionGuardUsing());
    }

    /**
     * @return string
     */
    public function getSessionKey()
    {
        return config('laravel-impersonate.session_key');
    }

    /**
     * @return string
     */
    public function getSessionGuard()
    {
        return config('laravel-impersonate.session_guard');
    }

    /**
     * @return string
     */
    public function getSessionGuardUsing()
    {
        return config('laravel-impersonate.session_guard_using');
    }

    /**
     * @return  string
     */
    public function getTakeRedirectTo()
    {
        try {
            $uri = route(config('laravel-impersonate.take_redirect_to'));
        } catch (\InvalidArgumentException $e) {
            $uri = config('laravel-impersonate.take_redirect_to');
        }

        return $uri;
    }

    /**
     * @return  string
     */
    public function getLeaveRedirectTo()
    {
        try {
            $uri = route(config('laravel-impersonate.leave_redirect_to'));
        } catch (\InvalidArgumentException $e) {
            $uri = config('laravel-impersonate.leave_redirect_to');
        }

        return $uri;
    }

    /**
     * @return string|null
     */
    public function getCurrentAuthGuardName()
    {
        $guards = array_keys(config('auth.guards'));
        foreach ($guards as $guard) {
            if ($this->app['auth']->guard($guard)->check()) {
                return $guard;
            }
        }
        return null;
    }
}
