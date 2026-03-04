<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Auth;
use App\Record;
use App\Office;
use App\Category;
use App\OfficeRecord;
use App\RecordHistory;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class RecordsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //return "here in index";
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

        if(Auth::user()->office == 1 || Auth::user()->priv != "Standard User"){ /**For Admin */
            $categories = Category::with('childRecursive')
                ->orderBy('code','asc')
                ->whereNull('parent_id')
                ->get();
            
            return view('records.create_admin',[
                'categories'    => $categories,
                'offices'       => $offices
            ]);
        }else{/**For Regular Users */
            $office = Office::where('id', Auth::user()->office)
                ->with('record_categories')
                ->first();

            $categories = Category::whereNull('parent_id')
                ->with(['child.offices'  => function($query){
                    $query->where('id', Auth::user()->office);
                }])->get();
            
            //Remove unnecessary categories
            foreach ($categories as $i=>$category) {
                foreach($category->child as $j=>$child){
                    if(count($child->offices) == 0){
                        unset($categories[$i]->child[$j]);
                    }
                }
                if(count($category->child) == 0){
                    unset($categories[$i]);
                }
            }
            
            return view('records.create_user',[
                'offices' => $offices,
                'categories' => $categories
            ]);
        }
        
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

        $category = Category::find($request->category_id);
        if(!$category){
            return redirect('/')->with('error', 'Invalid Category');
        }

        $validatedData = $request->validate([
            'category_id' => 'required',
            //'file' => 'file|max:1999',
            'description' => 'required|max:250'
        ]);

        $start_date = explode('/', $request->start_date);

        $record = new Record;
        $record->category_id = $validatedData['category_id'];
        $record->description = $validatedData['description'];
        $record->upload_date = Carbon::now();
        $record->uploader_id = Auth::id();
        
        //If the record comes from an external office(category type 3), office_id is set to records office/admin
        //If the account is not from the records office, office_id is set to the uploader office
        if($request->office_id == NULL){
            if($category->type == 3){
                $record->office_id = 1;
            }else{
                $record->office_id = Auth::user()->office;
            }
        }else{
            $record->office_id = $request->office_id;
        }
        
        $record->start_date = Carbon::create($start_date[2], $start_date[0], $start_date[1],0,0,0);
        $record->status = 0;
        
        if( $record->save() ){/**If saved successfully */
            //Handle File Upload
            if($request->hasFile('file')){
                //Get filename with the extension
                $filenameWithExt = $request->file('file')->getClientOriginalName();
                //Get just the filename
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                //Get just the extension
                $extension = $request->file('file')->getClientOriginalExtension();
                //Filename to store
                $fileNameToStore = $record->id.'_'.$filename.'.'.$extension;
                //Upload File
                $folder = 'Records/'.$record->office_id.'/'.$record->category->id;

                $path = Storage::disk('ftp-admin')->putFileAs(
                    $folder, $request->file('file'), $fileNameToStore
                );

                if($path){/**If File is uploaded successfully */
                    //Save upload location
                    $record->upload_location = $path;
                    $record->save();
                    
                    //Save office_ids office with Copy Furnish
                    if($request->office_ids){
                        foreach($request->office_ids as $office_id) {
                            $office_record = New OfficeRecord;
                            $office_record->office_id = $office_id;
                            $office_record->record_id = $record->id;
                            $office_record->save();
                        }
                    }

                    //create record history
                    $history = New RecordHistory;
                    $history->record_id = $record->id;
                    $history->status = 1;
                    $history->date = Carbon::now();
                    $history->description = "Uploaded With File";
                    $history->user = Auth::user()->id;
                    $history->count = 1;
                    $history->save();

                    return redirect('/records/'.$record->id)->with('success', 'Record Created Successfully!');
                }else{/**If Failed to upload File */
                    $record->delete();
                    return redirect('/records/create')->with('error', 'Failed to Upload File. Record Not Created!');
                }
            }else{ /**No File Attached */
                //Save office_ids office with Copy Furnish
                if($request->office_ids){
                    foreach($request->office_ids as $office_id) {
                        $office_record = New OfficeRecord;
                        $office_record->office_id = $office_id;
                        $office_record->record_id = $record->id;
                        $office_record->save();
                    }
                }

                //create record history
                $history = New RecordHistory;
                $history->record_id = $record->id;
                $history->status = 1;
                $history->date = Carbon::now();
                $history->description = "Uploaded Without File";
                $history->user = Auth::user()->id;
                $history->count = 1;
                $history->save();

                return redirect('/records/'.$record->id)->with('success', 'Record Created Successfully! No File Included!');
            }
        }else{ 
            return redirect('/records/create')->with('error', 'Record Not Created!');
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

        $record = Record::find($id);
        if(!$record){
            return redirect('/records/categories')->with('error', 'No Record Found');
        }
        
        //Check if office is with Copy Furnish
        $isCF = $record->offices->where('id', Auth::user()->office)->count();
        
        if ($isCF > 0 || Auth::user()->office == 1 || Auth::user()->priv != "Standard User" || $record->office_id == Auth::user()->office) {
            return view('records.show',['record'=>$record]);
        }else{
            return redirect('/')->with('error', 'Access Denied');
        }
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
        
        $record = Record::find($id);
        if(!$record){
            return redirect('/records/category')->with('error', 'Not a Valid Record');
        }
        
        $offices = Office::orderBy('description','asc')->get();
        /**Get Offices with CF */
        $office_ids = array();
        foreach ($record->offices as $office) {
            array_push($office_ids, $office->id);
        }
        
        if(Auth::user()->office == 1 || Auth::user()->priv != "Standard User"){ /**For Admin */
            $categories = Category::with('childRecursive')
                ->orderBy('code','asc')
                ->whereNull('parent_id')
                ->get();
            
            return view('records.edit_admin',[
                'record'        =>  $record, 
                'categories'    =>  $categories,
                'offices'       =>  $offices,
                'office_ids'    =>  $office_ids
            ]);
        }else{/**For Regular Users */
            if($record->office_id != Auth::user()->office){
                return redirect('/records/categories')->with('error', 'Access Denied');
            }

            $office = Office::where('id', Auth::user()->office)
                ->with('record_categories')
                ->first();

            $categories = Category::whereNull('parent_id')
                ->with(['child.offices'  => function($query){
                    $query->where('id', Auth::user()->office);
                }])->get();
            
            //Remove unnecessary categories
            foreach ($categories as $i=>$category) {
                foreach($category->child as $j=>$child){
                    if(count($child->offices) == 0){
                        unset($categories[$i]->child[$j]);
                    }
                }
                if(count($category->child) == 0){
                    unset($categories[$i]);
                }
            }

            return view('records.edit_user',[
                'offices' => $offices,
                'categories' => $categories,
                'record'    => $record,
                'office_ids'    =>  $office_ids
            ]);
        }
    
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

        $category = Category::find($request->category_id);
        if(!$category){
            return redirect('/')->with('error', 'Invalid Category');
        }

        $validatedData = $request->validate([
            'category_id' => 'required',
            //'file' => 'file|max:1999',
            'description' => 'required|max:250'
        ]);

        $start_date = explode('/', $request->start_date);

        $record = Record::find($id);
        $record->category_id = $validatedData['category_id'];
        $record->description = $validatedData['description'];
        
        //If the record comes from an external office(category type 3), office_id is set to records office/admin
        //If the account is not from the records office, office_id is set to the uploader office
        if($request->office_id == NULL){
            if($category->type == 3){
                $record->office_id = 1;
            }else{
                $record->office_id = Auth::user()->office;
            }
        }else{
            $record->office_id = $request->office_id;
        }
        
        $record->start_date = Carbon::create($start_date[2], $start_date[0], $start_date[1],0,0,0);
        $record->status = 0;

        //Delete existing data in office_record (CF offices)
        OfficeRecord::where('record_id', $record->id)->delete();
        $count = RecordHistory::where('record_id', $record->id)->count();
        
        if( $record->save() ){/**If saved successfully */
            //Handle File Upload
            if($request->hasFile('file')){
                //Delete File on Storage
                Storage::disk('ftp-admin')->delete($record->upload_location);

                //Get filename with the extension
                $filenameWithExt = $request->file('file')->getClientOriginalName();
                //Get just the filename
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                //Get just the extension
                $extension = $request->file('file')->getClientOriginalExtension();
                //Filename to store
                $fileNameToStore = $record->id.'_'.$filename.'.'.$extension;
                //Upload File
                $folder = 'Records/'.$record->office_id.'/'.$record->category->id;

                $path = Storage::disk('ftp-admin')->putFileAs(
                    $folder, $request->file('file'), $fileNameToStore
                );

                if($path){/**If File is uploaded successfully */
                    //Save upload location
                    $record->upload_location = $path;
                    $record->save();
                    
                    //Save office_ids office with Copy Furnish
                    if($request->office_ids){
                        foreach($request->office_ids as $office_id) {
                            $office_record = New OfficeRecord;
                            $office_record->office_id = $office_id;
                            $office_record->record_id = $record->id;
                            $office_record->save();
                        }
                    }

                    //Create History
                    $history = New RecordHistory;
                    $history->record_id = $record->id;
                    $history->status = 2;
                    $history->date = Carbon::now();
                    $history->description = "Record Updated. File Also Updated";
                    $history->user = Auth::user()->id;
                    $history->count = $count+1;
                    $history->save();

                    return redirect('/records/'.$record->id)->with('success', 'Record Updated Successfully!');
                }else{/**If Failed to upload File */
                    $record->delete();
                    return redirect('/records/categories/'.$record->category_id)->with('error', 'Failed to Upload File. Record Not Updated!');
                }
            }else{ /**No File Attached */
                //Save office_ids office with Copy Furnish
                if($request->office_ids){
                    foreach($request->office_ids as $office_id) {
                        $office_record = New OfficeRecord;
                        $office_record->office_id = $office_id;
                        $office_record->record_id = $record->id;
                        $office_record->save();
                    }
                }
                
                //Create History
                $history = New RecordHistory;
                $history->record_id = $record->id;
                $history->status = 2;
                $history->date = Carbon::now();
                $history->description = "Record Updated. File Wasn't Updated";
                $history->user = Auth::user()->id;
                $history->count = $count+1;
                $history->save();

                return redirect('/records/'.$record->id)->with('success', 'Record Updated Successfully! No File Included!');
            }
        }else{ 
            return redirect('/records/categories/'.$record->category_id)->with('error', 'Record Not Created!');
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

        $record = Record::find($request->id);
        if(!$record){
            return redirect('/records/categories')->with('error', 'No Such Record Exist!'); 
        }
        /**
         * Record Office and the Originating Office can only delete a record
         */
        if(Auth::user()->office != 1 && $record->office_id != Auth::user()->office){
            return redirect('/')->with('error', 'You Are Not Allowed To Delete Record!');
        }
    
        //Delete File on Storage
        Storage::disk('ftp-admin')->delete($record->upload_location);
        $record->delete();

        //Delete existing data in office_record (CF offices)
        OfficeRecord::where('record_id', $record->id)->delete();
        //Delete existing data in record_history
        RecordHistory::where('record_id', $record->id)->delete();

        return redirect('/records/categories/'.$record->category_id)->with('success', 'Record Deleted');
    }

    public function download($id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        /**
         * Record Office, the Originating Office and those who are in CF are allowed to download a record
         */
        $record = Record::find($id);
        if(!$record){
            return redirect('/records/categories')->with('error', 'No Such Record Exist!');
        }
        $isCF = $record->offices->where('id', Auth::user()->office)->count();

        if(Auth::user()->office == 1 || Auth::user()->priv != "Standard User" || $record->office_id == Auth::user()->office || $isCF > 0){
            if($record->upload_location){
                $contents = Storage::disk('ftp-admin')->download($record->upload_location);
                return $contents;  
            }else{
                return redirect('/records/categories')->with('error', 'No File Attached!');
            }
        }else{
            return redirect('/records/categories')->with('error', 'Acess Denied');
        }
        
    }
    
    /**Return list of offices */
    public function listOffice()
    {
        if(Auth::user()->office != 1){
            return redirect('/')->with('error', 'Access Denied');
        }

        $offices = Office::orderBy('description','asc')->get();
        return view('records.list_office',['offices'=>$offices]);

    }
    
    /**Return list of categories available per office */
    public function listOfficeCategories($office_id)
    {
        if(Auth::user()->office != 1){
            return redirect('/')->with('error', 'Access Denied');
        }

        $office = Office::find($office_id);
         
        return view('records.list_office_categories',[
            'categories' => $office->record_categories->where('parent_id','<>',NULL),
            'office'     => $office
        ]);
    }
    
    /**Return list of records available for every category available per office */
    public function listOfficeCategoriesRecords($office_id, $category_id)
    {   
        if(Auth::user()->office != 1){
            return redirect('/')->with('error', 'Access Denied');
        }
        /** Displays the record where the office has a CF */
        $records = Record::whereRaw(
            "category_id = ? AND (
                id IN (
                    SELECT record_id FROM office_records WHERE office_id = ?
                ) OR office_id = ? )",
            [$category_id, $office_id, $office_id])
            ->get();
        
        $office = Office::find($office_id);
        $category = Category::find($category_id);
        $offices = Office::orderBy('description','asc')->get();
        
        return view('records.list_office_categories_records',[
            'records'   => $records,
            'category'  => $category,
            'office'    => $office,
            'offices'   => $offices
        ]);
    }

    /**List all records per category */
    public function listCategoriesRecords($category_id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $category = Category::find($category_id);
        $offices = Office::orderBy('description','asc')->get();

        if(Auth::user()->office == 1 || Auth::user()->priv != "Standard User"){ /**For Admin */
            $records = Record::where('category_id', $category->id)->get();
        }else{/**For Regular Users */
            $records = Record::whereRaw(
                    "category_id = ? AND (
                        id IN (
                            SELECT record_id FROM office_records WHERE office_id = ?
                        ) OR office_id = ? )",
                [$category->id, Auth::user()->office, Auth::user()->office])
                ->get();
        }
        
        if(!$category || !$category->parent_id){
            return redirect('/records/categories')->with('error', 'Not a Valid Record Category');
        }
        
        return view('records.list_categories_records', [
            'category'  => $category,
            'offices'   => $offices,
            'records'   => $records
        ]);
    }

    /**Search Records */
    public function search()
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        $offices = Office::orderBy('description','asc')->get();        
        $categories = Category::with('childRecursive')
            ->orderBy('code','asc')
            ->whereNull('parent_id')
            ->get();
           
        return view('records.search',[
            'categories'    => $categories,
            'offices'       => $offices
        ]);
    }

    /**Get Records */ /**to fix query for regular users */
    public function get_records(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $query = array();

        if($request->category_id){
            $push = array("category_id","=", $request->category_id);
            array_push($query,$push);
        }
        if($request->office_id){
            $push = array("office_id","=", $request->office_id);
            array_push($query,$push);
        }
        if($request->description){
            $push = array("description","LIKE", "%".$request->description."%");
            array_push($query,$push);
        }

        if(Auth::user()->office == 1 || Auth::user()->priv != "Standard User"){ /**For Admin */
            if($request->to && $request->from){
                $date_from = explode('/', $request->from);
                $date_to = explode('/', $request->to);
                
                $records = Record::whereBetween('start_date',[
                    Carbon::create($date_from[2], $date_from[0], $date_from[1],0,0,0),
                    Carbon::create($date_to[2], $date_to[0], $date_to[1],23,59,59)
                ])->where($query)->get();
            }else{
                if(!$query){
                    return redirect('/records/search')->with('error', 'Please Fill Search Parameters!');
                }
                $records = Record::where($query)->get();
            }
        }else{/**For Regular users */
            if($request->to && $request->from){
                $date_from = explode('/', $request->from);
                $date_to = explode('/', $request->to);
                
                $records = Record::whereBetween('start_date',[
                    Carbon::create($date_from[2], $date_from[0], $date_from[1],0,0,0),
                    Carbon::create($date_to[2], $date_to[0], $date_to[1],23,59,59)
                ])->where($query)
                ->whereRaw('id IN (SELECT record_id FROM office_records WHERE office_id = ?)', [Auth::user()->office])
                ->get();
            }else{
                if(!$query){
                    return redirect('/records/search')->with('error', 'Please Fill Search Parameters!');
                }
                $records = Record::where($query)
                ->whereRaw('id IN (SELECT record_id FROM office_records WHERE office_id = ?)', [Auth::user()->office])
                ->get();
            }
        }
        
        return view('records.search_results',[
            'records'   => $records
        ]);
    }
}