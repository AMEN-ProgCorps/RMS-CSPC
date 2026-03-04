<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Auth;
use App\School;

class SchoolsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        if(Auth::user()->office != 1){
            return redirect('/')->with('error', 'Action Not Allowed!');
        }

        $school = School::find(1);

        return view('school.show',['school' => $school]);

    }

    public function update(Request $request)
    {
        if(Auth::guest()){
            return view('auth.login');
        }

        if(Auth::user()->office != 1){
            return redirect('/')->with('error', 'Action Not Allowed!');
        }

        $validatedData = $request->validate([
            'name'    => 'required|max:250',
            'code'      => 'required|max:250',
            'address'   => 'required|max:250'
        ]);

        $school = School::find(1);
        $school->name = $validatedData['name'];
        $school->code = $validatedData['code'];
        $school->address = $validatedData['address'];

        if($school->save()){
            return redirect('/customize')->with('success', 'School Info Updated');
        }else{
            return redirect('/customize')->with('error', 'failed to Update School Info');
        }
    }

    public function update_photo(Request $request)
    {
        if(Auth::guest()){
        return view('auth.login');
        }
        if(Auth::user()->office != 1){
            return redirect('/')->with('error', 'Action Not Allowed!');
        }

        $type = $request->type;
        if($request->hasFile('file')){
            //Get filename with the extension
            $filenameWithExt = $request->file('file')->getClientOriginalName();
            //Get just the filename
            $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
            //Get just the extension
            $extension = $request->file('file')->getClientOriginalExtension();
            //Filename to store
            if($type == 3){
                $fileNameToStore = 'user-img-background.jpg';
            }else{
                $fileNameToStore = $filename.'.'.$extension;
            }
            //Upload File
            $folder = 'images';

            $path = Storage::disk('public')->putFileAs(
                $folder, $request->file('file'), $fileNameToStore
            );
            
            $school = School::find(1);
            
            if($type == 1){
                $school->logo = $folder.'/'.$fileNameToStore;
            }else if($type == 2){
                $school->background_1 = $folder.'/'.$fileNameToStore;
            }else if($type == 3){
                $school->background_2 = $folder.'/'.$fileNameToStore;
            }

            if($school->save()){
                return redirect('/customize')->with('success', 'Updated Successfully');
            }else{
                return redirect('/customize')->with('error', 'Update Failed');
            }
        }else{
            return redirect('/customize')->with('error', 'No File Attached!');
        }
    }
}