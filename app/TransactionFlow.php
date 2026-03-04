<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TransactionFlow extends Model
{
    //The table to be used
    protected $table = "flow";
    
    public $timestamps = false;
    
    /**Display the office */ 
    public function office(){
        return $this->hasOne('App\Office','id', 'office_id');
    }
}
