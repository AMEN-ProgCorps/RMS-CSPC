<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Auth;
use App\User;

class PasswordsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        if($id != Auth::id()){
            redirect ('/');
        }

        $user = User::find($id);
        return view('password.edit',['user'=>$user]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        if($id != Auth::id()){
            redirect ('/');
        }
        //validate
        $validatedData = $request->validate([
            'password' => 'required|min:6',
            'new_password_1' => 'required|min:6',
            'new_password_2' => 'required|min:6'
        ]);

        $user = User::find($id);
        
        if(Hash::check($validatedData['password'], $user->password)){
            if( $validatedData['new_password_1'] === $validatedData['new_password_2']){
                $user->password = Hash::make($validatedData['new_password_1']);
                if($user->save()){
                    return redirect("/users/".$user->id)->with('success', 'Password Updated Successfully!');
                }else{
                    return redirect("/passwords/".$user->id."/edit")->with('error', 'Failed To Update Password!');
                }
            }else{
                return redirect("/passwords/".$user->id."/edit")->with('error', 'New Password Mismatch!');
            }
        }else{
            return redirect("/passwords/".$user->id."/edit")->with('error', 'Existing Password Mismatch!');
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function reset(Request $request)
    {
        if(Auth::user()->office != 1){
            return view('auth.login');
        }

        $user = User::find($request->id);
        $user->password = Hash::make("0123456789");
        
        if($user->save()){
            return redirect('/users/'.$user->id)->with('success','New Password: 0123456789');
        }else{
            return redirect('/users/'.$user->id.'/edit')->with('error','Failed To Update Password!');
        }
    }
}
