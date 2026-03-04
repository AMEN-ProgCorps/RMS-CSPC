<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
//add needed resources
use\Auth;
use App\TransactionNature;
use App\TransactionFlow;
use App\Office;
use App\Transaction;

class FlowsController extends Controller
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

        $transaction_natures = TransactionNature::where('office_id',Auth::user()->office)->get();
        return view('flows.list', ['transaction_natures'=>$transaction_natures]);
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
        $offices = Office::orderBy('description','asc')->get();
        return view('flows.create',['offices'=>$offices]);
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
        
        /*
        //Some office has transaction going to the same office..Like Supply Office to Supply Office
        if($request->office_ids[0] == Auth::user()->office){
            return redirect('/flows/create')->with('error', 'Second entry should not be your office');
        }
        */
        /* Validate first the data before processing (laravel validation)*/
        $validatedData = $request->validate([
            'description' => 'required|max:250',
            'office_ids' => 'required|array',
            'office_ids.*' => 'required|max:50'
        ]);

        $transaction_nature = new TransactionNature;
        $transaction_nature->description = $validatedData['description'];
        $transaction_nature->office_id = Auth::user()->office;
        $transaction_nature->isfreeflow = 0;
        $transaction_nature->save();

        if(count($request->office_ids)>0){
            $transaction_flow = new TransactionFlow;
            $transaction_flow->nature_id = $transaction_nature->Nature_id;
            $transaction_flow->office_id = Auth::user()->office;
            $transaction_flow->chrono_order = 1;
            $transaction_flow->save();

            foreach($request->office_ids as $index=> $office_id) {
                $transaction_flow = new TransactionFlow;
                $transaction_flow->nature_id = $transaction_nature->Nature_id;
                $transaction_flow->office_id = $office_id;
                $transaction_flow->chrono_order = $index+2;
                $transaction_flow->save();
            }
        }

        return redirect('/flows')->with('success', 'New Transaction Flow Created');
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
        
        $transaction_nature = TransactionNature::find($id);
        return view('flows.show',['transaction_nature'=>$transaction_nature]);
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
        $office_ids = array();
        $offices = Office::orderBy('description','asc')->get();
        $transaction_nature = TransactionNature::find($id);

        foreach ($transaction_nature->transaction_flow as $flow) {
            array_push($office_ids, $flow->office_id);
        }

        return view('flows.edit',[
            'transaction_nature'=> $transaction_nature, 
            'offices'           => $offices,
            'office_ids'        => $office_ids
        ]);
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

        /* Validate first the data before processing (laravel validation)*/
        $validatedData = $request->validate([
            'office_ids' => 'required|array',
            'office_ids.*' => 'required|max:50',
            'description' => 'required|max:250'
        ]);
        
        $transaction_nature = TransactionNature::find($id);
        $transaction_nature->description = $validatedData['description'];
        $transaction_nature->save();
        
        TransactionFlow::where('nature_id', $id)->delete();

        foreach($validatedData['office_ids'] as $index=>$office_id){
            $transaction_flow = new TransactionFLow;
            $transaction_flow->office_id = $office_id;
            $transaction_flow->nature_id = $id;
            $transaction_flow->chrono_order = $index+1;
            $transaction_flow->save();
        }

        return redirect('/flows')->with('success', 'Updated Transaction Flow');
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

        $transaction_nature = TransactionNature::find($request->id);
        $count = Transaction::where('nature_id', $request->id)
            ->where('completed', 0)
            ->count();
        
        if($count > 0){
            return redirect('/flows')->with('error', 'Transaction Flow is in use');
        }

        $transaction_nature->delete();
        TransactionFlow::where('nature_id', $request->id)->delete();

        return redirect('/flows')->with('success', 'Successfully Deleted Transaction Flow');
    }
}