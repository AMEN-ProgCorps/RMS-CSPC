<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Office extends Model
{
    //The table to be used
    protected $table = "office";
    //apply softdelete
    use SoftDeletes;

    //Get a list of Records that the Office has a Copy Furnish
    public function copy_furnish(){
        return $this->belongsToMany('App\Record','office_records', 'office_id', 'record_id');
    }

    //Get a list of Transaction Nature available to the office
    public function transaction_nature(){
        return $this->hasMany('App\TransactionNature', 'office_id')->with('transaction_flow');
    }

    //Get a list of Record Categories available per Office
    public function record_categories(){
        return $this->belongsToMany('App\Category','office_category', 'office_id', 'category_id')->orderBy('code','asc');
    }
}