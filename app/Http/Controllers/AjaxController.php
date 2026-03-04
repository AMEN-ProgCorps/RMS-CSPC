<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
//add needed resources
use Auth;
use App\Category;
use App\Office;
use App\Record;
use App\TransactionNature;
use Illuminate\Support\Facades\Input;
use Carbon\Carbon;

class AjaxController extends Controller
{
    public function getOfficePerCategory($category_id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $offices = Category::find($category_id)->offices;
        return $offices;
        return response()->json($offices);
    }

    public function getCategoryInternal($isInternal)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        $office_id = Auth::user()->office;

        if ( $office_id == 1 ) {
            $categories = Category::orderBy('code','asc')
                ->where('isInternal', $isInternal)
                ->get();
        } else {
            $categories = Office::find($office_id)
                ->record_categories
                ->where('isInternal', $isInternal);
        }
    
        //get parent_ids
        $parent_ids = array();
        foreach ($categories as $category) {
            if (in_array($category->parent_id, $parent_ids) == FALSE && $category->parent_id) {
                array_push($parent_ids, $category->parent_id);
            }
        }
        
        $categories = Category::with('childRecursive')
            ->orderBy('code','asc')
            ->find($parent_ids);
    
        return response()->json($categories);
    }

    public function getCategoryPerOffice()
    {
        $office_id = Input::get('office_id');
        $type = Input::get('type');

        if(Auth::guest()){
            return view('auth.login');
        }

        if($type == 1){
            if ($office_id == 10) { // for presidents' office
                $categories = Office::find($office_id)
                    ->record_categories()
                    ->where('description', 'like', "%Executive%")
                    ->orWhere('description', 'like', "%Admin%")
                    ->orWhere('description', 'like', "%memo%")
                    ->wherePivot('office_id', $office_id)
                    ->get();
            }else {
                $categories = Office::find($office_id)
                    ->record_categories()
                    ->where('description', 'like', "%memo%")
                    ->wherePivot('office_id', $office_id)
                    ->get();
            }
        }else{
            $categories = Office::find($office_id)
                ->record_categories;
        }
        
        return response()->json($categories);
    }

    public function getCategory($id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        return Category::find($id);
    }

    public function getTransactionFlow($id)
    {
        if(Auth::guest()){
            return view('auth.login');
        }
        
        $flow = TransactionNature::find($id);
        return $flow->transaction_flow;

    }
}