<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PasswordReset;
use Illuminate\Support\Facades\Mail; // Use the correct namespace for Mail
use Illuminate\Support\Facades\URL;  // Add this line to use the URL facade
use Illuminate\Support\Str; // Add this line to use the Str facade
use Carbon\Carbon;
use App\Models\User;
use DB;
use Exception;
class ForgotPasswordController extends Controller
{
    public function forgetPassword(Request $request)
    {
       
        try{
            $user = DB::table('users')->where('email', $request->email)->get();
            // dd($user);
            
            
                $token = Str::random(40);
                $domain= URL::to('/');
                $url=$domain.'/reset-password?token='.$token;

                $data['url']=$url;
                $data['email']=$request->email;
                $data['title']='Password Reset';
                $data['body']='Please Click on below Link to reset your password';

                Mail::send('forgetPasswordMail',['data'=>$data],function($message) use($data){
                    $message->to($data['email'])->subject($data['title']);
                });
                $datetime=Carbon::now()->format('y-m-d H:i:s');
                PasswordReset::updateOrCreate(
                    ['email'=>$request->email],
                    [
                        'email'=>$request->email,
                        'token'=>$token,
                        'created_at'=>$datetime,
                    ]
                    
                    );
                    return response()->json(['success'=>true,'msg'=>'Please Check Your Mail']);

            

        }catch(\Exception $e){
            return response()->json(['success'=>false,'msg'=>$e->getMessage()]);

        }

    }

    // reset Password view Load
public function resetPasswordLoad(Request $request)
{
    $resetData = PasswordReset::where('token',$request->token)->get();
    if(isset($request->token) && count($resetData) > 0){
        $user = User::where('email',$resetData['email'])->get();
        return view('resetPassword',compact('user'));

    }
    else{
        return view('404');
    }
}
}
