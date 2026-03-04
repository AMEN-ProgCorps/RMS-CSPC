<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
//add needed resources
use Illuminate\Support\Facades\DB;
use Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Office;
use App\User;
use App\Transaction;
use App\TransactionCF;
use App\Record;
use App\Status;

class UsersController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        if(Auth::user()->office != 1){
            return redirect('/');
        }

        $users_active = array();
        $users_disabled = array();

        $users = User::all();
        foreach($users as $user){
            if($user->disabled){
                array_push($users_disabled, $user);
            }else{
                array_push($users_active, $user);
            }
        }
        return view('users.list',[
            'users_active'      => $users_active,
            'users_disabled'    => $users_disabled
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        if(Auth::user()->office != 1){
            return redirect('/');
        }

        $offices = Office::orderBy('description','asc')->get();
        return view('users.create')->with('offices',$offices);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        if(Auth::user()->office != 1){
            return redirect('/');
        }

        $validatedData = $request->validate([
            'fname' => 'required|min:3|max:120',
            'mi'    => 'max:5',
            'lname' => 'required|min:3|max:120',
            'uname' => 'required|max:20|unique:account',
            'emailAdd' => 'required|max:255|unique:account',
            'CPno'  => 'required|max:15',
            'office_id' => 'required',
            'password' => 'required|min:6|confirmed',
            'priv' => 'required'
        ]);

        $user = new User;
        $user->lname = $validatedData['lname'];
        $user->fname = $validatedData['fname'];
        $user->mi = $validatedData['mi'];
        $user->uname = $validatedData['uname'];
        $user->password = Hash::make($validatedData['password']);
        $user->office = $validatedData['office_id'];
        $user->createdby = Auth::id();
        $user->emailAdd = $validatedData['emailAdd'];
        $user->CPno = $validatedData['CPno'];
        $user->priv = $validatedData['priv'];
        $user->activate = 0;
        
        if($user->save()){
            return redirect('/users/'.$user->id)->with('success', 'New Account Created!');
        }else{
            return redirect('/users')->with('error', 'Failed To Create New Account!');
        }
        
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        if($id != Auth::id() && Auth::user()->office != 1){
            return redirect('/');
        }
        
        $user = User::find($id);
        return view('users.profile',['user' => $user]);

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

        $user = User::find($id);
        $offices = Office::all();
        return view('users.edit', ['user'=>$user, 'offices'=>$offices]);
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
        if( $id == 103 && Auth::id() != 103){
            return redirect('/users')->with('error','Cannot Update Admin!');
        }

        //validate
        $validatedData = $request->validate([
            'fname' => 'required|min:3|max:120',
            'mi'    => 'max:5',
            'lname' => 'required|min:3|max:120',
            //'uname' => 'required|max:20|unique:account',
            'emailAdd' => 'required|max:255',
            'CPno'  => 'required|max:15'
            //'office_id' => 'required',
            //'priv' => 'required'
        ]);
        //update user information
        $user = User::find($id);
        $user->fname = $validatedData['fname'];
        $user->mi = $validatedData['mi'];
        $user->lname = $validatedData['lname'];
        //$user->uname = $validatedData['uname'];
        $user->emailAdd = $validatedData['emailAdd'];
        $user->CPno = $validatedData['CPno'];
        if(Auth::user()->priv != 'Standard User'){
            $user->office = $request->office_id;
            $user->priv = $request->priv;
        }

        if($user->save()){
            return redirect('/users/'.$user->id)->with('success', 'Updated User Details!');
        }else{
            return redirect('/users')->with('error', 'Failed To Update User Details!');
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        if($request->id == 103){
            return redirect('/users')->with('error','Cannot Delete Admin!');
        }

        /** Checks if the user is in use on the following Table */
        $count = 0;
        if(Transaction::where('createdby', $request->id)->count() > 0){
            $count++;
        }
        if(Status::where('forwarded_by', $request->id)->count() > 0){
            $count++;
        }
        if(Status::where('received_by', $request->id)->count() > 0){
            $count++;
        }
        if(TransactionCF::where('received_by', $request->id)->count() > 0){
            $count++;
        }
        if(Record::where('uploader_id', $request->id)->count() > 0){
            $count++;
        }

        if($count > 0){
            return redirect('/users')->with('error','The User has created Transaction(s) and/or Record(s). Deleting it will affect the information about them.');
        }

        $user = User::find($request->id);
        if($user->delete()){
            return redirect('/users')->with('success','User Succesfully Deleted!');
        }else{
            return redirect('/users')->with('error','Failed To Delete User!');
        }
        
    }

    public function update_username(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        if( $request->id == 103 && Auth::id() != 103){
            return redirect('/users')->with('error','Cannot Update Admin!');
        }

        $validatedData = $request->validate([
            'uname' => 'required|max:20',
            'id' => 'required'
        ]);

        $user = User::find($validatedData['id']);
        if(User::where('uname', $validatedData['uname'])->count() > 0){
            return redirect('/users/'.$validatedData['id'].'/edit')->with('error','The username has already been taken!');
        }

        $user->uname = $validatedData['uname'];
        if($user->save()){
            return redirect('/users/'.$validatedData['id'])->with('success','Username Succesfully Updated!');
        }else{
            return redirect('/users/'.$validatedData['id'].'/edit')->with('error','Username Update Failed!');
        }
    }

    public function disable_user(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        if( $request->id == 103 && Auth::id() != 103){
            return redirect('/users')->with('error','Cannot Disable Admin!');
        }

        $user = User::find($request->id);
        $user->disabled = $request->disabled;

        if($request->disabled){
            $message = "Disabled!";
        }else{
            $message = "Activated!";
        }

        if($user->save()){
            return redirect('/users/')->with('success','User Succesfully '.$message);
        }else{
            return redirect('/users/')->with('error','User Failed to be '.$message);
        }
    }
}
