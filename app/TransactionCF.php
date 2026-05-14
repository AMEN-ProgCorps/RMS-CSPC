<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TransactionCF extends Model
{
    //The table to be used
    protected $table = "transaction_cf";
    public $timestamps = false;

    /**Display the office */ 
    public function office(){
        return $this->hasOne('App\Office','id', 'office_id');
    }

    /**Display transaction details */
    public function transaction(){
        return $this->hasOne('App\Transaction', 'Barcode', 'barcode_value')->with('status');
    }
    
    /**Display Receiving Account */
    public function receiver(){
        return $this->hasOne('App\User', 'id', 'received_by');
    }
}
