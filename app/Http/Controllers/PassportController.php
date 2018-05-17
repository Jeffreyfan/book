<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Auth;
use SmsManager;
use Validator;
use App;

class PassportController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth', ['except'=>[
            'showRegisterForm', 'showLoginForm', 'register', 'login', 'LoginBySms', 'loginByPassword'
        ]]);

        $this->middleware('guest', ['only'=>[
            'showRegisterForm', 'showLoginForm'
        ]]);

        //模拟微信登陆，测试状态下通过中间件
        if(config('sim_wechat_oauth') == 'on'){
            $this->middleware('wechat.sim', ['only'=>[
                'showRegisterForm', 'showLoginForm'
            ]]);
        }

        //微信中间件
        $this->middleware('wechat.oauth:snsapi_userinfo', ['only'=>[
            'showRegisterForm', 'showLoginForm'
        ]]);
    }

    public function showRegisterForm()
    {
        $oauth_user = [];
        if(session()->has('wechat.oauth_user.default')) {
            $oauth_user = session('wechat.oauth_user.default');
        }
        return view('passport.register_form', compact('oauth_user'));
    }

    /**
     * 注册表单提交位置
     */
    public function register(Request $request){
        $validator = Validator::make($request->all(), [
            'mobile'     => 'required|confirm_mobile_not_change',
            'verifyCode' => 'required|verify_code',
            'password' => 'required|min:6',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput(['mobile']);
        }
        $userData = [
            'name'=>'手机用户'.substr($request->mobile, -4),
            'mobile'=>$request->mobile,
            'password'=>bcrypt($request->password),
            'last_actived_at'=>date('Y-m-d H:i:s', time()),
        ];
        $oauth_user = session('wechat.oauth_user.default');
        if($oauth_user){
            $userData['openid'] = $oauth_user->getId();
            $userData['name'] = $oauth_user->getName();
            $userData['avatar'] = $oauth_user->getAvatar();
        }
        $user = User::create($userData);
        Auth::login($user);
        session()->flash('success', '注册成功并已自动登录~');
        return redirect()->route('index');
    }

    public function showLoginForm(User $user){
        //如果获取到微信授权，并且已经在系统中记录过，则直接取得系统授权
        if(session()->has('wechat.oauth_user.default') && !App::environment('local')){
            $oauth_user = session('wechat.oauth_user.default');
            $user = $user->where('openid', $oauth_user->getId())->first();
            if($user){
                Auth::login($user, true);
                session()->flash('success', '微信授权登录成功');
                return redirect()->intended(route('index'));
            }else{
                //获取到授权，但是并不存在于系统中
                session()->flash('success', '微信授权成功，请继续完成注册');
                return redirect()->route('passport.register');
            }
        }
        return view('passport.login_form');
    }

    public function login(Request $request){
        if($request->loginType == 'sms'){
            return $this->loginBySms($request);
        }else if($request->loginType == 'password'){
            return $this->loginByPassword($request);
        }else{
            abort(401);
        }
    }

    /**
     * 短信方式登录
     * @param Request $request
     * @return $this|\Illuminate\Http\RedirectResponse
     */
    protected function LoginBySms(Request $request){
        $validator = Validator::make($request->all(), [
            'mobile'     => 'required|confirm_mobile_not_change|confirm_rule:check_mobile_exists',
            'verifyCode' => 'required|verify_code',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        $user = User::where('mobile', $request->mobile)->first();
        Auth::login($user, true);
        session()->flash('success', '欢迎回来！');
        return redirect()->intended(route('index'));
    }

    /**
     * 密码方式登录
     * @param Request $request
     * @return $this|\Illuminate\Http\RedirectResponse
     */
    protected function loginByPassword(Request $request){
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|zh_mobile',
            'password' => 'required',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        if(Auth::attempt($request->all(['mobile', 'password']),  true)){
            session()->flash('success', '欢迎回来！');
            return redirect()->intended(route('index'));
        }else{
            return redirect()->back()->withErrors('登录失败')->withInput();
        }
    }

    public function logout(){
        Auth::logout();
        session()->flash('success', '您已成功退出');
        return redirect()->route('index');
    }

    public function showForgotForm(Request $request){
        if($request->has('autoSend')){
            session()->flash('message', '一条短信已经发送到您的手机');
        }
        return view('passport.forgot');
    }

    public function forgot(Request $request){
        $validator = Validator::make($request->all(), [
            'mobile'     => 'required|confirm_mobile_not_change|confirm_rule:check_mobile_exists',
            'verifyCode' => 'required|verify_code',
            'password'=>'required|min:6|confirmed',
        ]);
        if ($validator->fails()) {
            return redirect()->route('passport.forgot')->withErrors($validator);
        }
        $user = Auth::user();
        $user->password = bcrypt($request->password);
        $result = $user->save();
        if($result){
            Auth::logout();
            return redirect()->route('passport.login')->with('success', ' 密码修改成功！请重新登录')->withInput(['loginType'=>'password']);
        }
    }


}
