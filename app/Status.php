<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    //The table to be used
    protected $table = "status";
    public $timestamps = false;
    
    /**Display the office */ 
    public function office(){
        return $this->hasOne('App\Office','id', 'office_id');
    }
    /**Display transaction details */
    public function transaction(){
        return $this->hasOne('App\Transaction', 'Barcode', 'barcode_value')->with('status');
    }
    /**Display originating office details - For Free Flow */
    public function originating_office_details(){
        return $this->hasOne('App\Office','id', 'originating_office');
    }
    /**Display Forwarding Account */
    public function forwarder(){
        return $this->hasOne('App\User', 'id', 'forwarded_by');
    }
    /**Display Receiving Account */
    public function receiver(){
        return $this->hasOne('App\User', 'id', 'received_by');
    }

    /**Display child */
    public function child(){
        return $this->hasMany('App\Status', 'originating_office' , 'office_id');
    }
}
