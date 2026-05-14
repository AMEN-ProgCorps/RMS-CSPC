<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\DB;
use Auth; //added to use Auth
use App\Transaction; //added to use the Model Transaction
use App\Office;
use App\TransactionFlow;
use App\TransactionNature;
use App\Status;
use App\Category;
use App\TransactionCF;
use Carbon\Carbon;
use PDF;
use Illuminate\Support\Facades\Storage;

class TransactionsController extends Controller
{
    /**List Current Transactions for the Office 
     * Only displays transactions from the last 12 months
    */
    public function index()
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        $status = Status::where('office_id', Auth::user()->office)
            ->where('date_in', '!=', null)
            ->where('date_out', null)
            ->where('status', null)
            ->orderBy('date_in', 'desc')
            ->whereBetween('date_in',[
                Carbon::today()->subMonths(12),
                Carbon::now()
            ])
            ->whereHas('transaction', function($query){
                $query->where('completed', 0);
            })->get();

        return view('transactions.list_current',[
            'status'        =>  $status
        ]);
    }

    public function create_flow()
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        $offices = Office::orderBy('description','asc')->get();
        return view('transactions.create_flow',['offices'=>$offices]);
    }

    /**Return List of Transactions Originating From the Office */
    public function list($type)
    {   
        
        /**
         * Only displays transactions from the last 2 months
         *
         * 1 -> list transaction originating from the office
         * 2 -> Incoming
         * 3 -> Outgoing
         * 4 -> CF Received
         */
        
        if(Auth::guest()){
            return view('auth.login');
        }

        if($type == 1){
            $transactions = Transaction::orderBy('Date_created','desc')
                ->where('requestorid', Auth::user()->office)
                ->whereBetween('Date_created',[
                    Carbon::today()->subMonths(2),
                    Carbon::now()
                ])->get();
            
            return view('transactions.list',['transactions'=>$transactions]);
        }else if($type == 2){
            $status = Status::where('office_id', Auth::user()->office)
                ->whereNotNull('date_in')
                ->where('originating_office', '!=', 0)
                ->orderBy('date_in', 'desc')
                ->whereHas('transaction', function($query){
                    $query->whereBetween('Date_created',[
                        Carbon::today()->subMonths(2),
                        Carbon::now()
                    ]);
                })->get();
            //return $status;
            return view('transactions.list_incoming',['status'=>$status]);
        }else if($type == 3){
            $status = Status::from( 'status as s1' )
                ->select(DB::raw('s1.*, s2.date_out AS orig_date_out'))
                ->join('status AS s2', function ($join) {
                    $join->on('s1.barcode_value', '=', 's2.barcode_value')
                        ->whereRAW('s1.originating_office = s2.office_id AND
                        s1.flow-1 = s2.flow');
                })
                ->whereBetween('s2.date_out',[
                    Carbon::today()->subMonths(2),
                    Carbon::now()
                ])
                ->where('s1.originating_office', Auth::user()->office)
                ->orderBy('orig_date_out', 'desc')
                ->get();
            
            return view('transactions.list_outgoing',['status'=>$status]);
        }else if($type == 4){
            $cfs = TransactionCF::where('office_id', Auth::user()->office)
                ->orderBy('date_in', 'asc')
                ->whereBetween('date_in',[
                    Carbon::today()->subMonths(2),
                    Carbon::now()
                ])->get();

            return view('transactions.list_cf',['cfs' => $cfs]);
        }else{
            return redirect('/');
        }
    }

    public function search()
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $offices = Office::orderBy('description','asc')->get();
        return view('transactions.search',['offices'=>$offices]);
    }

    public function get_transactions(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        /**Build appropriate query base from input */
        $query = array();
        if($request->control_no){
            $push = array("control_no", "LIKE", "%".$request->control_no."%");
            array_push($query,$push);
        }
        if($request->barcode){
            $push = array("Barcode","=", $request->barcode);
            array_push($query,$push);
        }
        if($request->office_id){
            $push = array("requestorid","=", $request->office_id);
            array_push($query,$push);
        }
        if($request->description){
            $push = array("description","LIKE", "%".$request->description."%");
            array_push($query,$push);
        }

        if($request->to && $request->from){
            $date_from = explode('/', $request->from);
            $date_to = explode('/', $request->to);
            
            $transactions = Transaction::whereBetween('Date_created',[
                Carbon::create($date_from[2], $date_from[0], $date_from[1],0,0,0),
                Carbon::create($date_to[2], $date_to[0], $date_to[1],23,59,59)
            ])->where($query)->orderBy('Date_created','desc')->get();
        }else{
            if(!$query){
                return redirect('/search_transactions')->with('error', 'Please Fill Search Parameters!');
            }
            $transactions = Transaction::where($query)->orderBy('Date_created','desc')->get();
        }
        
        if( Auth::user()->priv != 'Standard User' || Auth::user()->office == 1){
            return view('transactions.search_results',['transactions'=>$transactions]);
        }else{
            $allowed_transactions = array();
            $check = 0;
            /**
             * allowed an office to see a transaction if it is one of the ff: 
             * 1) requestor
             * 2) in the flow/status
             * 3) in the CF
             */
            foreach($transactions as $transaction){
                if($transaction->requestorid == Auth::user()->office){
                    $check++;
                }
                if($transaction->status->where('office_id', Auth::user()->office)->count() > 0){
                    $check++;
                }
                if($transaction->copy_furnished->where('office_id', Auth::user()->office)->count() > 0){
                    $check++;
                }

                if($check > 0){
                    array_push($allowed_transactions, $transaction);
                }
                $check = 0;
            }
            return view('transactions.search_results',['transactions'=>$allowed_transactions]);
        }
    }

    public function show($id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $barcode = Input::get('barcode');

        if ($id == "barcode"){
            $transaction = Transaction::where('Barcode',$barcode)->first();
        }else{
            $transaction = Transaction::find($id);
        }

        if(!$transaction){
            return redirect('/')->with('error', 'No Transaction Found');
        }

        /**If the user is not from Records, Checks if office is allowed to view transaction */
        //if(Auth::user()->office != 1){
        if(Auth::user()->priv == 'Standard User'){
            $check = 0;
            if($transaction->requestorid == Auth::user()->office){
                $check++;
            }
            if($transaction->status->where('office_id', Auth::user()->office)->count() > 0){
                $check++;
            }
            if($transaction->copy_furnished->where('office_id', Auth::user()->office)->count() > 0){
                $check++;
            }

            if($check == 0){
                return redirect('/')->with('error', 'Not Allowed to View Transaction');
            }
        }
        
        $categories = Office::find(Auth::user()->office)
            ->record_categories
            ->where('parent_id','!=',NULL);
        
        $offices = Office::orderBy('description','asc')->get();

        /**Get office ids with CF*/
        $cf_ids = array();
        foreach($transaction->copy_furnished as $CF){
            array_push($cf_ids, $CF->office_id);
        }

        return view('transactions.show',[
            'transaction'   =>  $transaction, 
            'categories'    =>  $categories,
            'offices'       =>  $offices,
            'cf_ids'        =>  $cf_ids
        ]);
    }

    public function forward($freeFlow, $transaction_id, $current_flow)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $transaction = Transaction::find($transaction_id);
        
        /**Check if Completed Transaction */
        if($transaction->completed){
            return redirect('/show_transaction/'.$transaction->id)->with('error', 'Transaction Already Completed');
        }
        
        if($freeFlow){
            $offices = Office::orderBy('Code', 'asc')->get();

            /**Get office id's in the next level */
            $office_ids = $transaction->status->where('flow', $current_flow+1);
            $ids = array();
            foreach($office_ids as $office){
                array_push($ids, $office->office_id);
            }

            return view('transactions.forward',[
                'offices'       => $offices,
                'transaction'   => $transaction,
                'current_flow'  => $current_flow,
                'ids'           => $ids /** not to included in select */
            ]);
        }else{
            $offices = Office::orderBy('description', 'asc')->get();
            
            /**Check if the office is correct*/
            if($transaction->status[count($transaction->status)-1]->office_id != Auth::user()->office){
                return redirect('/')->with('error', 'Transaction Not For Your Office');
            }
            /**if path is change */
            if( $transaction->path()->count() == $current_flow){
                $current_flow = 0;
            }

            return view('transactions.forward',[
                'offices'       => $offices,
                'transaction'   => $transaction,
                'current_flow'  => $current_flow
            ]);
        }
    }

    public function forwarded(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        /* Validate first the data before processing (laravel validation)*/
        $validatedData = $request->validate([
            'notes' => 'max:1000'
        ]);
        
        $transaction = Transaction::find($request->transaction_id);
        
        /**Transaction with specified path */
        if(!$transaction->freeFlow){
            $current = $transaction->status[count($transaction->status) - 1];
            $next_path = $transaction->path[$request->current_flow];
            
            /**Create new status */
            $status = new Status;
            $status->flow = $current->flow + 1;
            $status->barcode_value = $transaction->Barcode;
            $status->notes = $validatedData['notes'];
            $status->office_id = $request->office_id;
            $status->originating_office = Auth::user()->office;
            $status->forwarded_by = Auth::id();
            /**zero also means that the path was changed */
            $status->chrono = $request->office_id == $request->next_office ? $next_path->chrono_order:0;
            $status->save();
    
            /**Update current status*/
            DB::table('status')->where('barcode_value', $transaction->Barcode)
                ->where('flow', $current->flow)
                ->update([
                    'date_out'  =>  Carbon::now(),
                    'status'    =>  "Forwarded"
                ]);
    
            /**Update Transaction */
            if($transaction->path_change == 1){
                $transaction->path_change = 1;
            }else{
                $transaction->path_change = $status->chrono != 0 ? 0:1;
            }
            $transaction->save();
    
            return redirect('/show_transaction/'.$transaction->id)->with('success', 'Transaction Forwarded');
        
        /** Free Flow Transactions */
        }else{ 
            /**Check if office is a receiver from a higher level*/
            foreach($request->office_ids as $office_id){
                $not_yet_receive = $transaction->status()->where('office_id', $office_id)->whereNull('date_in')->count();
                if($not_yet_receive > 0){
                    $office = Office::find($office_id);
                    return redirect('/show_transaction/'.$transaction->id)->with('error', $office->Code.' have not yet receive the transaction!');
                }
            }
            
            foreach($request->office_ids as $office_id){
                /** Create new status */
                $status = new Status;
                $status->flow = $request->current_flow + 1;
                $status->barcode_value = $transaction->Barcode;
                $status->notes = $validatedData['notes'];
                $status->office_id = $office_id;
                $status->originating_office = Auth::user()->office;
                $status->chrono = null;
                $status->forwarded_by = Auth::id();
                $status->save();
            }
            /**Update current status*/
            DB::table('status')->where('barcode_value', $transaction->Barcode)
                ->where('flow', $request->current_flow)
                ->where('office_id', Auth::user()->office)
                ->update([
                    'date_out'  =>  Carbon::now(),
                    'status'    =>  "Forwarded"
                ]);
            return redirect('/show_transaction/'.$transaction->id)->with('success', 'Transaction Forwarded');
        }
        
    }
    
    public function return($transaction_id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        $transaction = Transaction::find($transaction_id);

        /**Check if Completed Transaction */
        if($transaction->completed){
            return redirect('/show_transaction/'.$transaction->id)->with('error', 'Transaction Already Completed');
        }
        
        /**Check if the office is correct*/
        if($transaction->status[count($transaction->status)-1]->office_id != Auth::user()->office){
            return redirect('/')->with('error', 'Transaction Not For Your Office');
        }

        return view('transactions.return',[
            'office_id'       => $transaction->status[count($transaction->status)-2]->office_id,
            'transaction'   => $transaction
        ]);
    }

    public function returned(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        /* Validate first the data before processing (laravel validation)*/
        $validatedData = $request->validate([
            'notes' => 'max:250'
        ]);

        $transaction = Transaction::find($request->transaction_id);
        $status_count = count($transaction->status);
        /**Get current status*/
        $current = $transaction->status[$status_count - 1];
        /**Get from office*/
        $from = $transaction->status[$status_count - 2];

        DB::table('status')->where('barcode_value', $transaction->Barcode)
            ->where('flow', $current->flow)
            ->where('office_id', $current->office_id)
            ->update([
                'date_out'  =>  Carbon::now(),
                'status'    => "Returned"
            ]);

        $status = new Status;
        $status->office_id = $from->office_id;
        $status->barcode_value = $transaction->Barcode;
        $status->flow = $current->flow + 1;
        $status->chrono = $from->chrono;
        $status->notes = $validatedData['notes'];
        $status->originating_office = Auth::user()->office;
        $status->forwarded_by = Auth::id();
        $status->save();

        return redirect('/show_transaction/'.$transaction->id)->with('success', 'Transaction Returned');
    }

    /**Show View for receiving transaction */
    public function receive()
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        return view('transactions.receive');
    }

    public function received(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        $transaction = Transaction::where('Barcode', $request->barcode)->with('status')->first();
        
        if (!$transaction){
            return redirect('/receive_transaction')->with('error', 'No Transaction Found');
        }
        if($transaction->completed){
            return redirect('/receive_transaction')->with('error', 'Transaction Already Completed');
        }

        /**Process Receiving CF */
        if($request->copyFurnished){

            $updated = DB::table('transaction_cf')->where('barcode_value', $transaction->Barcode)
                ->where('office_id', Auth::user()->office)
                ->update([
                    'date_in'     =>    Carbon::now(),
                    'received_by' =>    Auth::id()
                ]);

            if($updated){
                return redirect('/show_transaction/'.$transaction->id)->with('success', 'Transaction Received');
            }else{
                return redirect('/receive_transaction')->with('error', 'CF Not For Your Office');
            }
        
        /**Process Receiving Regular Transactions */
        }else{
            /**Check if Completed Transaction */
            // if($transaction->completed){
            //     return redirect('/show_transaction/'.$transaction->id)->with('error', 'Transaction Already Completed');
            // }

            /**Receive Free Flow Transactions */
            if($transaction->freeFlow){
                /** check if office is allowed to receive transaction */
                $status = $transaction->status()->whereNull('status')
                    ->whereNull('date_in')
                    ->where('office_id', Auth::user()->office)
                    ->get();
                
                if(count($status)>0){
                    //update transaction status
                    DB::table('status')->where('barcode_value', $transaction->Barcode)
                        ->where('flow', $status[0]->flow)
                        ->where('office_id', Auth::user()->office)
                        ->update([
                            'date_in'   => Carbon::now(),
                            'received_by' =>    Auth::id()
                        ]);
                    
                    return redirect('/show_transaction/'.$transaction->id)->with('success', 'Transaction Received');
                }else{
                    return redirect('/receive_transaction')->with('error', 'Cannot Receive Transaction!');
                }
            
            /**Receive Transactions with specified flow */
            }else{
                /**Get Current Location */
                $current = $transaction->status->where('location', 'Current')->first();
                /* 
                Removed because there are transactions that comes from one office going to itself. Like Supply Office
                //Transaction is currently in the office or already received
                if($transaction->current_office == Auth::user()->office){
                    return redirect('/show_transaction/'.$transaction->id)->with('error', 'Transaction Currently in the Office');
                }
                */
                /**Get Next Location */
                $next = $transaction->status->where('flow', $current->flow+1)->first();
                /**No Next Office base on Path/Flow */
                if(!$next){
                    return redirect('/receive_transaction')->with('error', 'Transaction Not For Your Office');
                }
                
                //check correct receiving office
                if(Auth::user()->office == $next->office_id){
                    //update transaction status
                    DB::table('status')->where('barcode_value', $transaction->Barcode)
                        ->where('flow', $current->flow)
                        ->where('office_id', $current->office_id)
                        ->update([
                            'location'  =>  null
                        ]);
                    
                    DB::table('status')->where('barcode_value', $transaction->Barcode)
                        ->where('flow', $next->flow)
                        ->where('office_id', $next->office_id)
                        ->update([
                            'date_in'       =>  Carbon::now(),
                            'location'      =>  'Current',
                            'received_by'   =>  Auth::id()
                        ]);
                    
                    $transaction->current_office = Auth::user()->office;
                    $transaction->current_chrono = $next->chrono;
                    
                    if($transaction->save()){
                        return redirect('/show_transaction/'.$transaction->id)->with('success', 'Transaction Received');
                    }else{
                        return redirect('/receive_transaction')->with('error', 'Failed To Received Transaction');
                    }

                }else{
                    return redirect('/receive_transaction')->with('error', 'Transaction Not For Your Office');
                }
            }
        }
    }
    
    public function create($transaction_type)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $transaction_natures = TransactionNature::where('office_id',Auth::user()->office)->get();
        $offices = Office::orderBy('description','asc')->get();

        if($transaction_type == 1){
            return view('transactions.create_internal',[
                'transaction_nature'    => $transaction_natures
            ]);
        }else if($transaction_type == 2){
            return view('transactions.create_memo',[
                'offices'               => $offices
            ]);
        }else{
            return redirect('/')->with('error', 'No Path Selected!');
        }
        
    }

    public function store(Request $request)
    { 
        if(Auth::guest()){
            return view('auth.login');
        }
    
        /* Validate first the data before processing (laravel validation)*/
        $validatedData = $request->validate([
            'description' => 'required|max:1000',
            'notes' => 'max:1000'
        ]);
        
        $transaction = new Transaction;
        $transaction->Date_created = Carbon::now();
        
        /** Generate Barcode */
        $i = true;
        
        while($i){
            $str = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
            //generate a string of len = 10
            $key = substr(str_shuffle($str), 0, 10);
            if(Transaction::where('Barcode', $key)->count() > 0){
                $i = true;
            }else{
                $i = false;
            }
        }

        // while($i){
        //     $transaction_count = Transaction::whereDate('Date_created', '=', Carbon::today()->toDateString())->count();
        //     $transaction_count++;
        //     $key = date("mdy")."-".str_pad($transaction_count, 3, '0', STR_PAD_LEFT);
        //     if(Transaction::where('Barcode', $key)->count() > 0){
        //         $i = true;
        //     }else{
        //         $i = false;
        //     }
        // }

        $transaction->Barcode = $key;
        $transaction->description = $validatedData['description'];
        $transaction->requestorid = Auth::user()->office;
        $transaction->current_office = $request->isFreeFlow ? NULL : Auth::user()->office;
        $transaction->current_chrono = !$request->isFreeFlow ? 1:0;
        $transaction->createdby = Auth::id();
        $transaction->freeFlow = $request->isFreeFlow ? 1:0;
        $transaction->nature_id = $request->isFreeFlow ? NULL : $request->nature_id;
        if(Auth::user()->office == 1){
            $transaction->control_no = $request->year."-".$request->month."-".$request->number;
        }else{
            $transaction->control_no = NULL;
        }
        
        /** Create Transaction with a specified path 
         * Usually for Internal Transactions
        */
        if(!$request->isFreeFlow){ 
            if(!$request->nature_id){
                return redirect('/new_transaction/1')->with('error', 'Please Choose A Transaction Path!');
            }

            $transaction_nature = TransactionNature::find($transaction->nature_id);
        
            if(!$transaction_nature){
                return redirect('/new_transaction/1')->with('error', 'Failed to Create Transaction!');
            }

            if( $transaction_nature->transaction_flow->count() < 2){
                return redirect('/new_transaction/1')->with('error', 'Invalid Transaction Flow: Too Short!');
            }

            //check if control no. exists
            if(Transaction::where('control_no', $transaction->control_no)->count() > 0 && $transaction->control_no != NULL){
                return redirect('/new_transaction/1')->with('error', 'Control No. Already Exists!');
            }
            
            $transaction->current_chrono = 1;
            foreach ($transaction_nature->transaction_flow as $index => $flow){
                /** Only creates the originating and the next office */
                if($index == 2){
                    break;
                }

                $status = new Status;
                $status->barcode_value = $transaction->Barcode;
                $status->office_id = $flow->office_id;
                $status->flow = $index+1;
                $status->chrono = $flow->chrono_order;
                $status->originating_office = Auth::user()->office;
                $status->forwarded_by = Auth::id();

                if($index == 0){
                    //$status->date_in = $transaction->Date_created;
                    $status->date_out = $transaction->Date_created;
                    $status->location = "Current";
                    $status->status = "Created";
                    $status->originating_office = 0;
                }

                if($index == 1 && !$transaction->freeFlow){
                    $status->notes = $validatedData['notes'];
                }

                $status->save();
            }

        /** Create Transaction without a specified path 
         * Usually used for Memo
        */
        }else if ($request->isFreeFlow){
            if(count($request->office_ids)>0){
                //start add
                $status = new Status;
                $status->barcode_value = $transaction->Barcode;
                $status->office_id = Auth::user()->office;
                $status->originating_office = 0;
                $status->flow = 0;
                $status->date_in = null;
                $status->date_out = Carbon::now();
                $status->location = null;
                $status->forwarded_by = Auth::id();
                $status->save();
                //end
                foreach ($request->office_ids as $index => $office_id) {
                    $status = new Status;
                    $status->barcode_value = $transaction->Barcode;
                    $status->office_id = $office_id;
                    $status->originating_office = Auth::user()->office;
                    $status->flow = 1; //change from 0
                    $status->date_in = null;
                    $status->date_out = null;
                    $status->location = null;
                    $status->notes = $validatedData['notes'];
                    $status->forwarded_by = Auth::id();
                    $status->save();
                }
            }else{
                return redirect('/')->with('error', 'Failed to Create Transaction');
            }
        }
        
        $transaction->save();
        //Handle File Upload
        if($request->hasFile('file') && $request->isFreeFlow){
            //Get filename with the extension
            $filenameWithExt = $request->file('file')->getClientOriginalName();
            //Get just the filename
            //$filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
            //Get just the extension
            $extension = $request->file('file')->getClientOriginalExtension();
            //Filename to store
            $fileNameToStore = $transaction->id.'.'.$extension;
            //Upload File
            $folder = 'Memorandum/'.Auth::user()->office;

            $path = Storage::disk('ftp-admin')->putFileAs(
                $folder, $request->file('file'), $fileNameToStore
            );

            if(!$path){
                return redirect('/show_transaction/'.$transaction->id)->with('error', 'File Upload Failed!');
            }else{
                $transaction->upload_location = $path;
                $transaction->save();
            }
        }

        return redirect('/show_transaction/'.$transaction->id)->with('success', 'New Transaction Created');
    }

    public function destroy(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $transaction = Transaction::find($request->id);

        if(!$transaction){
            return redirect('/')->with('error', 'No Transaction Found');
        }
        /**Not allowed to delete the office is not admin or not the requestor */
        if($transaction->requestorid != Auth::user()->office && Auth::user()->office != 1){
            return redirect('/')->with('error', 'Not Allowed to Delete Transaction');
        }
        
        /**Delete status*/
        DB::table('status')->where('barcode_value', $transaction->Barcode)->delete();
        /**Delete CFs */
        DB::table('transaction_cf')->where('barcode_value', $transaction->Barcode)->delete();
        /**Delete Transaction */
        $transaction->delete();

        return redirect('/')->with('success', 'Transaction Successfully Deleted');
    }

    public function update(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $transaction = Transaction::find($request->id);
        
        if(!$transaction){
            return redirect('/')->with('error', 'No Transaction Found');
        }
        if($transaction->requestorid != Auth::user()->office && Auth::user()->office != 1){
            return redirect('/')->with('error', 'Not Allowed');
        }
        if($transaction->completed){
            return redirect('/')->with('error', 'Transaction Already Completed');
        }

        /* Validate first the data before processing (laravel validation)*/
        $validatedData = $request->validate([
            'description' => 'required|max:250',
            'notes' => 'max:250'
        ]);

        $transaction->description = $validatedData['description'];

        if ($transaction->flow){
            $transaction->notes = $validatedData['notes'];
        }else{
            DB::table('status')->where('barcode_value', $transaction->Barcode)
            ->where('flow', 2)
            ->update([
                'notes'  =>  $validatedData['notes']
            ]);
        }

        $transaction->save();
        return redirect('/show_transaction/'.$transaction->id)->with('success', 'Transaction Updated');
    }

    public function complete(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $transaction = Transaction::find($request->id);
        
        if(!$transaction){
            return redirect('/')->with('error', 'No Transaction Found');
        }

        if($transaction->freeFlow){
            if($transaction->requestorid != Auth::user()->office){
                return redirect('/show_transaction/'.$transaction->id)->with('error', 'Not Allowed');
            }
        }else{
            if($transaction->requestorid != Auth::user()->office && $transaction->current_location->id != Auth::user()->office){
                return redirect('/show_transaction/'.$transaction->id)->with('error', 'Not Allowed');
            }
            $current = $transaction->status->where('location', 'Current')->first();
            //update transaction status
            DB::table('status')->where('barcode_value', $transaction->Barcode)
                ->where('flow', $current->flow)
                ->where('office_id', $current->office_id)
                ->update([
                    'status'    =>  'Completed',
                    'location'  =>  NULL
                ]);
        }

        $transaction->completed = true;
        $transaction->Remarks = 'Completed';
        $transaction->save();

        return redirect('/show_transaction/'.$transaction->id)->with('success', 'Transaction Completed');
    }

    public function add_action(Request $request)
    {   
        if(Auth::guest()){
            return view('auth.login');
        }

        /* Validate first the data before processing (laravel validation)*/
        $validatedData = $request->validate([
            'action' => 'max:1000'
        ]);

        DB::table('status')->where('barcode_value', $request->barcode)
            ->where('flow', $request->flow)
            ->where('office_id', Auth::user()->office)
            ->update([
                'action'  =>  $validatedData['action'],
                'status'  =>  'Action Taken'
            ]);

        $status = Status::where('barcode_value', $request->barcode)
            ->where('flow', $request->flow)
            ->where('office_id', Auth::user()->office)
            ->first();
        
        return redirect('/show_transaction/'.$status->transaction->id)->with('success', 'Action Added');
    }

    public function delete_status(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $status = Status::where('barcode_value', $request->barcode_value)
            ->where('flow', $request->flow)
            ->where('office_id', $request->office_id)
            ->where('date_in', NULL)
            ->first();
        
        if(!$status){
            return redirect('/show_transaction/'.$status->transaction->id)->with('error', 'No Transaction Flow Found!');
        }

        if($status->originating_office != Auth::user()->office && $status->date_in != NULL){
            return redirect('/show_transaction/'.$status->transaction->id)->with('error', 'Action Not Allowed!');
        }
        
        /**Update the preceeding status: date_out, notes to be set to null */
        if(!$status->transaction->freeFlow){
            DB::table('status')->where('barcode_value', $request->barcode_value)
            ->where('flow', $request->flow-1)
            ->update([
                'date_out'  =>  NULL,
                'status'    =>  NULL
            ]);
        }

        $deleted = Status::where('barcode_value', $request->barcode_value)
            ->where('flow', $request->flow)
            ->where('office_id', $request->office_id)
            ->where('date_in', NULL)
            ->delete();

        if($deleted){
            return redirect('/show_transaction/'.$status->transaction->id)->with('success', 'Action Successful!');
        }else{
            return redirect('/show_transaction/'.$status->transaction->id)->with('error', 'Action Failed!');
        }
    }

    public function print_barcode($id, $orientation)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $transaction = Transaction::find($id);

        $pdf = PDF::loadView('templates.barcode',[
            'transaction' => $transaction,
            'orientation' => $orientation
            ])->setPaper('a4', $orientation);

        return $pdf->stream($transaction->Barcode.'.pdf');
    }

    public function print_transactions(Request $request)
    {
        /**
         * 1 -> list transaction originating from the office
         * 2 -> Incoming
         * 3 -> Outgoing
         * 4 -> CF Received
         */

        if(Auth::guest()){
            return view('auth.login');
        }

        $from   = Input::get('from'); /* month/day/year */
        $to     = Input::get('to');
        $type = Input::get('type');
        
        $date_from = explode('/', $from);
        $date_to = explode('/', $to);
        /** 1 -> list transaction originating from the office */
        if($type == 1){
            $transactions = Transaction::where('requestorid', Auth::user()->office)
            ->orderBy('Date_created', 'asc')
            ->whereBetween('Date_created',[
                Carbon::create($date_from[2], $date_from[0], $date_from[1],0,0,0),
                Carbon::create($date_to[2], $date_to[0], $date_to[1],23,59,59)
            ])->get();

            $pdf = PDF::loadView('templates.transactions',[
                'transactions' => $transactions,
                'from'  => $from,
                'to'    => $to
                ])->setPaper('A4', $request->orientation);
            
            return $pdf->stream('Transactions.pdf');

        }else if($type == 2){ /** 2 -> Incoming */
            $status = Status::where('office_id', Auth::user()->office)
                ->where('originating_office', '!=', 0)
                // ->whereHas('transaction', function($query)use ($date_from, $date_to){
                //     $query->whereBetween('Date_created',[
                //         Carbon::create($date_from[2], $date_from[0], $date_from[1],0,0,0),
                //         Carbon::create($date_to[2], $date_to[0], $date_to[1],23,59,59)
                //     ]);
                // })
                ->orderBy('date_in', 'asc')
                ->whereBetween('date_in', [
                    Carbon::create($date_from[2], $date_from[0], $date_from[1],0,0,0),
                    Carbon::create($date_to[2], $date_to[0], $date_to[1],23,59,59)
                ])->get();

            $pdf = PDF::loadView('templates.incoming',[
                'status' => $status,
                'from'  => $from,
                'to'    => $to
                ])->setPaper('A4', $request->orientation);
            
            return $pdf->stream('IncomingTransactions.pdf');

        }else if($type == 3){ /** 3 -> Outgoing */
            $status = Status::from( 'status as s1' )
                ->select(DB::raw('s1.*, s2.date_out AS orig_date_out'))
                ->join('status AS s2', function ($join) {
                    $join->on('s1.barcode_value', '=', 's2.barcode_value')
                        ->whereRAW('s1.originating_office = s2.office_id AND
                        s1.flow-1 = s2.flow');
                })
                ->where('s1.originating_office', Auth::user()->office)
                ->whereBetween('s2.date_out', [
                        Carbon::create($date_from[2], $date_from[0], $date_from[1],0,0,0),
                        Carbon::create($date_to[2], $date_to[0], $date_to[1],23,59,59)
                ])
                ->orderBy('orig_date_out', 'asc')->get();
            
            //$customPaper = array(0,0,595,842); // set to long ph
            $pdf = PDF::loadView('templates.outgoing',[
                'status' => $status,
                'from'  => $from,
                'to'    => $to
                ])->setPaper('A4', $request->orientation);
            
            return $pdf->stream('OutgoingTransactions.pdf');
        }else if($type == 4){ /** 4 -> CF Received */
            $cfs = TransactionCF::where('office_id', Auth::user()->office)
                ->orderBy('date_in', 'asc')
                ->whereBetween('date_in',[
                    Carbon::create($date_from[2], $date_from[0], $date_from[1],0,0,0),
                    Carbon::create($date_to[2], $date_to[0], $date_to[1],23,59,59)
                ])->get();
            
            $pdf = PDF::loadView('templates.cf',[
                'cfs'   => $cfs,
                'from'  => $from,
                'to'    => $to
                ])->setPaper('A4', $request->orientation);
            
            return $pdf->stream('CFs.pdf');
        }else{
            return redirect('/');
        }

    }

    public function add_control_no(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        $control_no = $request->year."-".$request->month."-".$request->number;
        //check if control no. exists
        if(Transaction::where('control_no', $control_no)->count() > 0){
            return redirect('/show_transaction/'.$request->transaction_id)->with('error', 'Control No. Already Exists!');
        }
        
        $transaction = Transaction::find($request->transaction_id);
        $transaction->control_no = $control_no;
        $transaction->save();
        return redirect('/show_transaction/'.$transaction->id)->with('success', 'Control No. Added Successfully!');
    }

    public function update_control_no(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $control_no = $request->year."-".$request->month."-".$request->number;
        $transaction = Transaction::find($request->transaction_id);
        //check if control no is same as input
        if($control_no == $transaction->control_no){
            return redirect('/show_transaction/'.$request->transaction_id)->with('success', 'No Changes Made!');
        }
        //check if control no. exists
        if(Transaction::where('control_no', $control_no)->count() > 0){
            return redirect('/show_transaction/'.$request->transaction_id)->with('error', 'Control No. Already Exists!');
        }
        
        $transaction->control_no = $control_no;
        $transaction->save();
        return redirect('/show_transaction/'.$transaction->id)->with('success', 'Control No. Updated Successfully!');
    }

    public function delete_control_no(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $transaction = Transaction::find($request->transaction_id);
        $transaction->control_no = NULL;
        $transaction->save();

        return redirect('/show_transaction/'.$request->transaction_id)->with('success', 'Control No. Deleted Successfully!');
    }

    public function memo_upload(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        $transaction = Transaction::find($request->transaction_id);
        if(!$transaction){
            return redirect('/')->with('error', 'No Such Transaction Exist!');
        }

        //Handle File Upload
        if($request->hasFile('file')){
            //Get filename with the extension
            $filenameWithExt = $request->file('file')->getClientOriginalName();
            //Get just the filename
            //$filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
            //Get just the extension
            $extension = $request->file('file')->getClientOriginalExtension();
            //Filename to store
            $fileNameToStore = $transaction->id.'.'.$extension;
            //Upload File
            $folder = 'Memorandum/'.$transaction->requestorid;

            $path = Storage::disk('ftp-admin')->putFileAs(
                $folder, $request->file('file'), $fileNameToStore
            );

            if(!$path){
                return redirect('/show_transaction/'.$transaction->id)->with('error', 'File Upload Failed!');
            }else{
                $transaction->upload_location = $path;
                $transaction->save();
            }
        }

        return redirect('/show_transaction/'.$transaction->id)->with('success', 'File Uploaded Successfully!');

    }

    public function memo_download($id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        /**
         * Record Office, the Originating Office and those who are in CF are allowed to download a record
         */
        $transaction = Transaction::find($id);
        if(!$transaction){
            return redirect('/')->with('error', 'No Such Transaction Exist!');
        }

        
        $isReceiver = $transaction->status->where('office_id', Auth::user()->office)->count();
        $isCF = $transaction->copy_furnished->where('office_id', Auth::user()->office)->count();
        
        if(Auth::user()->office == 1 || Auth::user()->priv != "Standard User" || $transaction->requestorid == Auth::user()->office || $isCF > 0 || $isReceiver > 0){
            if($transaction->upload_location){
                $contents = Storage::disk('ftp-admin')->download($transaction->upload_location);
                return $contents;  
            }else{
                return redirect('/')->with('error', 'No File Attached!');
            }
        }else{
            return redirect('/')->with('error', 'Acess Denied');
        }
        
    }

    public function memo_delete(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        $transaction = Transaction::find($request->transaction_id);
        if(!$transaction){
            return redirect('/')->with('error', 'No Such Transaction Exist!');
        }

        if (Auth::user()->office == 1 || Auth::user()->priv != "Standard User" || Auth::user()->office == $transaction->requestorid){
            Storage::disk('ftp-admin')->delete($transaction->upload_location);
            $transaction->upload_location = NULL;
            $transaction->save();

            return redirect('/show_transaction/'.$transaction->id)->with('success', 'File Deleted Successfully!');
        }else{
            return redirect('/')->with('error', 'Acess Denied');
        }
    }

}