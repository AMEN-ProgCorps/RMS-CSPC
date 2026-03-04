<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\DB;
use Auth;
use App\Office;
use App\Record;
use App\Category;
use App\OfficeRecord;
use Carbon\Carbon;
use PDF;

class ReportsController extends Controller
{
    public function get_memos()
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $offices = Office::orderBy('description','asc')->get();
        return view("reports.get_memos",[
            "offices" => $offices
        ]);
    }

    public function list_memos(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        /*date input format: month/day/year */
        /**
         * type:
         * 1 - From the Office
         * 2 - For the Office
         */

        /**Other offices can only search memos to and from itself */
        /**Admin is also allowed to search memos */
        //Auth::user()->office == 1
        Auth::user()->priv != "Standard User" ? $office_id = $request->id : $office_id = Auth::user()->office;
        
        $validatedData = $request->validate([
            'from' => 'required',
            'to' => 'required',
            'type' => 'required'
        ]);

        $date_from = explode('/', $validatedData['from']); /**convert date to array */
        $date_to = explode('/', $validatedData['to']);

        /**Get office details */
        $office = Office::find($office_id);

        $type = $validatedData['type'];

        /**Get Category IDs with description of memo or order */
        $category_ids = array();
        $categories = Category::select('id')
            ->where('description', 'LIKE', '%memo%')
            ->orWhere('description', 'LIKE', '%order%')
            ->get();
        foreach($categories as $category){
            array_push($category_ids, $category->id);
        }
        
        if($type == 1){
            $records = Record::where('office_id', $office->id)
                ->whereIn('category_id', $category_ids)
                ->whereBetween('start_date',[
                    Carbon::create($date_from[2], $date_from[0], $date_from[1],0,0,0),
                    Carbon::create($date_to[2], $date_to[0], $date_to[1],23,59,59)
                ])->get();
        }else if($type == 2){
            $records = Record::whereIn('category_id', $category_ids)
                ->whereRaw('id IN (select record_id from office_records where office_id = ?)', $office->id)
                ->whereBetween('start_date',[
                    Carbon::create($date_from[2], $date_from[0], $date_from[1],0,0,0),
                    Carbon::create($date_to[2], $date_to[0], $date_to[1],23,59,59)
                ])->get();
            
        }
        
        return view('reports.list_memos',[
            'records'       => $records,
            'office'        => $office,
            'category_ids'  => $category_ids,
            'to'            => $validatedData['to'],
            'from'          => $validatedData['from'],
            'type'          => $type
        ]);
    }

    public function get_disposition()
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $offices = Office::orderBy('description','asc')->get();
        
        return view('reports.get_disposition',[
            'offices'   => $offices
        ]);
    }

    public function list_disposition(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $validatedData = $request->validate([
            'office_id' => 'required'
        ]);

        $office = Office::find($validatedData['office_id']);

        $records = Record::where('office_id', $office->id)
            ->where('status', '!=', 4)
            ->whereHas('category', function($query){
                $query->whereNotNull('years_active')
                    ->whereNotNull('years_storage');
            })->get();
        
        $records_active = array();
        $records_storage = array();
        $records_disposal = array();
        
        /**
         * Status of a record: 
         * 1 => Active
         * 2 => Storage
         * 3 => For Disposal
         * 4 => Disposed
         * to get value use record->status
         */

        foreach($records as $record){
            if($record->status == 1){
                array_push($records_active, $record);
            }else if($record->status == 2){
                array_push($records_storage, $record);
            }else if($record->status == 3){
                array_push($records_disposal, $record);
            }
        }
        
        return view('reports.list_disposition',[
            'records_active'    => $records_active,
            'records_storage'   => $records_storage,
            'records_disposal'  => $records_disposal,
            'office'            => $office
        ]);
    }

    public function print_disposition(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $validatedData = $request->validate([
            'office_id' => 'required'
        ]);

        $office = Office::find($validatedData['office_id']);

        $records = Record::where('office_id', $office->id)
            ->where('status', 0)
            ->whereHas('category', function($query){
                $query->whereNotNull('years_active')
                    ->whereNotNull('years_storage');
            })->get();
            
        foreach($records as $i => $record){
            if($record->status != 3){
                unset($records[$i]);
            }
        }

        $pdf = PDF::loadView('templates.disposition',[
            'records'   => $records,
            'office'    => $office
        ])->setPaper('A4', $request->orientation);
        
        return $pdf->stream('disposition.pdf');
    }
}