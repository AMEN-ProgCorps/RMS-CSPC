<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Auth;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }
    
    //use emailadd instead of email
    public function username()
    {
        //return 'emailadd';
        // $login = request()->input('login');
        // $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'emailadd' : 'uname';
        // request()->merge([$field => $login]);
        // return $field;
        return 'uname';
    }

    protected function authenticated(Request $request, $user)
    { 
        if($user->disabled){
            Auth::logout();
            return redirect('/login');
        }
        
    }
}
