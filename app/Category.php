<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Auth;
use lluminate\Database\Eloquent\Collection;

class Category extends Model
{
    //The table to be used
    protected $table = "categories";

    use SoftDeletes; //apply softdelete

    /**
     * Category Type:
     * 1 => Internal
     * 2 => To External
     * 3 => From External
     */

    //Get list of offices that uses the record category
    public function offices(){
        return $this->belongsToMany('App\Office','office_category', 'category_id', 'office_id')->orderBy('description','asc');
    }

    public function child(){
        return $this->hasMany('App\Category','parent_id')->orderBy('code','asc');
    }

    public function childRecursive(){
        return $this->child()->with('childRecursive');
    }

    public function records(){
        return $this->hasMany('App\Record', 'category_id', 'id')->orderBy('upload_date', 'desc');
    }

}