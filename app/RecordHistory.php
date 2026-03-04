<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class RecordHistory extends Model
{
    //The table to be used
    protected $table = "record_history";
    public $timestamps = false;

    public function user_name(){
        return $this->hasOne('App\User','id', 'user');
    }
}
    