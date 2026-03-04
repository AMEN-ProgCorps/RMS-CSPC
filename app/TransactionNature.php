<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TransactionNature extends Model
{
    //The table to be used
    protected $table = "nature_of_transaction";
    //primary_key
    protected $primaryKey = 'Nature_id';

    public $timestamps = false;

    //One to Many Relationship
    public function transaction_flow(){
        return $this->hasMany('App\TransactionFlow', 'nature_id', 'Nature_id')->orderBy('chrono_order','asc')->with('office');
    }
}
