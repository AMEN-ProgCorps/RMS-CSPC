<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    //The table to be used
    protected $table = "barcoded_documents";
    protected $primaryKey = 'id'; //'Barcode';
    //protected $keyType = 'string';
    public $timestamps = false;

    /**Display Actual Transaction Flow */
    public function status(){
        return $this->hasMany('App\Status', 'barcode_value', 'Barcode')->orderBy('flow', 'asc');
    }

    /**Display CF */
    public function copy_furnished(){
        return $this->hasMany('App\TransactionCF', 'barcode_value', 'Barcode');
    }

    public function records(){
        return $this->hasMany('App\Record', 'transaction_id', 'id');
    }

    /**Display the originating office */
    public function office(){
        return $this->belongsTo('App\Office', 'requestorid', 'id');
    }

    /**Display the current office for !FreeFlow*/
    public function current_location(){
        return $this->belongsTo('App\Office', 'current_office', 'id');
    }

    /**Display Listed Transaction Flow */
    public function path(){
        return $this->hasMany('App\TransactionFlow', 'nature_id', 'nature_id')->orderBy('chrono_order', 'asc');
    }

    /**Display Flow Details */
    public function path_type(){
        return $this->hasOne('App\TransactionNature', 'Nature_id', 'nature_id');
    }
}