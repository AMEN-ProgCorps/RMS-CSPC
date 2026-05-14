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
use App\Status;
use App\Record;

class OfficesController extends Controller
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

        $offices = Office::orderBy('description','asc')->get();
        return view('offices.list')->with('offices',$offices);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // used modal
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
        //validate
        $validatedData = $request->validate([
            'code' => 'required|min:2|max:250',
            'description' => 'required|min:3|max:250'
        ]);
        
        $office = new Office;
        $office->code = $validatedData['code'];
        $office->description = $validatedData['description'];
        $office->save();

        return redirect('/offices')->with('success', 'New Office Created');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //used modal
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        // used modal
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        //validate
        $validatedData = $request->validate([
            'code' => 'required|min:2|max:250',
            'description' => 'required|min:3|max:250'
        ]);
        //search and update office
        $office = Office::find($request->id);
        $office->code = $validatedData['code'];
        $office->description = $validatedData['description'];
        $office->save();

        return redirect('/offices')->with('success','Updated Office Details');
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

        if($request->id == 1){
            return redirect('/offices')->with('error', 'Cannot Delete Records Office!');
        }

        /** Checks if the office is in use on the following Table */
        $count = 0;
        if(Transaction::where('requestorid', $request->id)->count() > 0){
            $count++;
        }
        if(Status::where('office_id', $request->id)->count() > 0){
            $count++;
        }
        if(Status::where('originating_office', $request->id)->count() > 0){
            $count++;
        }
        if(TransactionCF::where('office_id', $request->id)->count() > 0){
            $count++;
        }
        if(Record::where('office_id', $request->id)->count() > 0){
            $count++;
        }
        
        if($count > 0){
            return redirect('/offices')->with('error', 'The Office has Transactions and/or Records. Deleting it will affect the information about them.');
        }

        $office = Office::find($request->id);
        if($office->delete()){
            return redirect('/offices')->with('success','Office Succesfully Deleted');
        }else{
            return redirect('/offices')->with('error','Failed to Delete Office');
        }
    }
}
