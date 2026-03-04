<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
//add needed resources
use Illuminate\Support\Facades\DB;
use Auth;
use App\Category;
use App\Office;

class CategoryController extends Controller
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
        
        /**For Admin */
        if(Auth::user()->office == 1 || Auth::user()->priv != "Standard User"){
            $offices = Office::orderBy('description','asc')->get();
            $categories = Category::with('childRecursive')
                ->whereNull('parent_id')
                ->paginate(1);
            
            //update return list order
            return view('category.list_admin',[
                'offices' => $offices,
                'categories' => $categories
            ]);

        }else{/**For Regular Users */
            // $office = Office::where('id', Auth::user()->office)
            //     ->with('record_categories')
            //     ->first();

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
            
            return view('category.list_user',[
                'categories' => $categories
            ]);
        }
        
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

        /**Set page value */
        $page = $request->page ? $request->page : 1;
                
        // Validate first the data before processing (laravel validation)
        $validatedData = $request->validate([
            'office_ids' => 'required|array',
            'office_ids.*' => 'required|max:50',
            'code' => 'required|max:50',
            'description' => 'required|max:250',
            'type' => 'required'
        ]);

        // Create Record Category
        $category = new Category;
        $category->code = $validatedData['code'];
        $category->description = $validatedData['description'];
        $category->parent_id = $request->parent_id;
        $category->type = $validatedData['type'];

        if(!$request->isPermanent){
            $category->years_active = $request->years_active;
            $category->years_storage = $request->years_storage;
        }else{
            $category->isPermanent = TRUE;
        }

        $category->save();

        foreach($validatedData['office_ids'] as $office_id){
            DB::table('office_category')->insert(
                [
                    'category_id' => $category->id,
                    'office_id' => $office_id
                ]
            );
        }

        //get page to return to
        $page = explode(".", $category->code);
        return redirect('/records/categories?page='.$page[0])->with('success', 'New Record Category Created');
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

        $category = Category::find($id);

        if(!$category){
            return redirect('/records/categories')->with('error', 'Not a Valid Record Category');
        }

        if(Auth::user()->office == 1 || Auth::user()->priv != "Standard User"){
            $offices = Office::orderBy('description','asc')->get();
        }else{
            $offices = Office::where('id',Auth::user()->office)->get();
        }
        
        $office_ids = array();
        foreach ($category->offices as $office) {
            array_push($office_ids, $office->id);
        }

        return view('category.edit', [
            'category'  => $category, 
            'offices'   => $offices,
            'office_ids'=> $office_ids
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
            'code' => 'required|max:50',
            'description' => 'required|max:250',
            'type' =>'required'
        ]);
        
        $category = Category::find($id);
        $category->code = $validatedData['code'];
        $category->description = $validatedData['description'];
        $category->type = $validatedData['type'];;

        //get page to return to
        $page = explode(".", $category->code);

        if(!$request->isPermanent){
            $category->years_active = $request->years_active;
            $category->years_storage = $request->years_storage;
        }else{
            $category->isPermanent = TRUE;
            $category->years_active = NULL;
            $category->years_storage = NULL;
        }

        $category->save();

        foreach ($category->offices as $office) {
            DB::table('office_category')
                ->where('category_id', $category->id)
                ->where('office_id', $office->id)
                ->delete();
        }

        foreach($validatedData['office_ids'] as $office_id){
            DB::table('office_category')->insert(
                [
                    'category_id' => $category->id,
                    'office_id' => $office_id
                ]
            );
        }

        return redirect('/records/categories?page='.$page[0])->with('success', 'Updated Record Category');
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

        $category = Category::find($request->id);

        //delete data in office_category table
        foreach ($category->offices as $office) {
            DB::table('office_category')
                ->where('category_id', $category->id)
                ->where('office_id', $office->id)
                ->delete();
        }

        $category->delete();
        return redirect('/categories')->with('success', 'Successfully Deleted Record Category');
    }
}