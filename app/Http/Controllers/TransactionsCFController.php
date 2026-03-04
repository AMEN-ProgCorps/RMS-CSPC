<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Auth;
use Carbon\Carbon;
use App\TransactionCF;

class TransactionsCFController extends Controller
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
        if(Auth::guest()){
            return view('auth.login');
        }

        /**Check if the office already exist */
        $count_cf = TransactionCF::where('barcode_value', $request->barcode)
            ->whereIn('office_id', $request->office_ids)
            ->count();
        if($count_cf > 0){
            return redirect('/show_transaction/barcode?barcode='.$request->barcode)->with('error', 'One of the Office(s) Already Exist');
        }

        foreach($request->office_ids as $office_id){
            $cf = new TransactionCF;
            $cf->barcode_value = $request->barcode;
            $cf->office_id = $office_id;
            $cf->save();
        }

        return redirect('/show_transaction/barcode?barcode='.$request->barcode)->with('success', 'Copy Furnihed Added');
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
        //
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
        //
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
        
        $deleted = DB::table('transaction_cf')
            ->where('barcode_value', $request->barcode)
            ->where('office_id', $request->office_id)
            ->delete();
        if(!$deleted){
            return redirect('/show_transaction/barcode?barcode='.$request->barcode)->with('error', 'Failed To Delete Office');
        }
        
        return redirect('/show_transaction/barcode?barcode='.$request->barcode)->with('success', 'Office Successfully Deleted');
    }
}
