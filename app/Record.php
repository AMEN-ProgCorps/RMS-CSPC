<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Record extends Model
{
    public $timestamps = false;

    public function transaction(){
        return $this->belongsTo('App\Transaction', 'transaction_id');
    }

    /**Return Offices with CF of the Record */
    public function offices(){
        return $this->belongsToMany('App\Office','office_records', 'record_id', 'office_id');
    }

    public function originating_office(){
        return $this->hasOne('App\Office','id', 'office_id');
    }
    
    /**
     * Category Type:
     * 1 => Internal
     * 2 => To External
     * 3 => From External
     */
    
    public function category(){
        return $this->belongsTo('App\Category', 'category_id');
    }

    /**History Status
     * 1 => Created By
     * 2 => Edited By
     */    
    public function history(){
        return $this->hasMany('App\RecordHistory', 'record_id', 'id');
    }


    /**
     * Set the status of a record
     * 1 => Active
     * 2 => Storage
     * 3 => For Disposal
     * 4 => Disposed
     * to get value use record->status
     */

    /**This is a function that will pre-process the value of the attribute status */
    public function getStatusAttribute($value){
        if($value == 4){
            return 4;
        }

        if($this->category->years_active == NULL && $this->category->years_storage == NULL){
            return NULL;
        }else{
            $active = $this->category->years_active;
            $storage = $this->category->years_storage;

            $dt =  Carbon::now();
            $diff = $dt->diffInDays($this->start_date);

            if($diff/365.25 > $active + $storage){
                return 3;
            }else if($diff/365.25 < $active){
                return 1;
            }else if($diff/365.25 > $active && $diff/365.25 < $active + $storage){
                return 2;
            }
        }
    }
}