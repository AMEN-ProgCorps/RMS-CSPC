<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

/*
    NOTE: if a route has the same starting value: 
    i.e "/records/offices" and "/records/offices/{office_id}
    the longer string should be written below
*/

//Routes for Authentication 
Auth::routes();
Route::get('/logout', 'Auth\LoginController@logout')->name('logout' ); //fix logout issue

//Password
Route::resource('passwords','PasswordsController');
Route::post('/password_reset', "PasswordsController@reset");

//Routes for transactions
Route::get('/',"TransactionsController@index");
Route::get('/list_transaction/{type}', "TransactionsController@list");
Route::get('/search_transactions',"TransactionsController@search");
Route::post('/get_transactions', "TransactionsController@get_transactions");
Route::get('/show_transaction/{id}', "TransactionsController@show");
Route::post('/forwarded_transaction', "TransactionsController@forwarded");
Route::get('/forward_transaction/{freeFlow}/{transaction_id}/{current_flow}', "TransactionsController@forward");
Route::post('/received_transaction', "TransactionsController@received");
Route::get('/receive_transaction', "TransactionsController@receive");
Route::post('/returned_transaction', "TransactionsController@returned");
Route::get('/return_transaction/{transaction_id}', "TransactionsController@return");
Route::get('/new_transaction/{transaction_type}', "TransactionsController@create");
Route::post('/store_transaction', "TransactionsController@store");
Route::post('/delete_transaction', "TransactionsController@destroy");
Route::post('/update_transaction', "TransactionsController@update");
Route::post('/complete_transaction', "TransactionsController@complete");
Route::post('/add_action', "TransactionsController@add_action");
Route::post('/delete_status', "TransactionsController@delete_status");
Route::post('/add_control_no', "TransactionsController@add_control_no");
Route::post('/update_control_no', "TransactionsController@update_control_no");
Route::post('/delete_control_no', "TransactionsController@delete_control_no");
//Memo
Route::get('/memo/download/{id}', 'TransactionsController@memo_download');
Route::post('/memo/upload', 'TransactionsController@memo_upload');
Route::post('/memo/delete', 'TransactionsController@memo_delete');

//Print Transactions
Route::get('/print_barcode/{id}/{orientation}', "TransactionsController@print_barcode");
Route::get('/print_transactions', "TransactionsController@print_transactions");

//Routes for Transaction Flow
Route::resource('flows','FlowsController');

//Routes for CF
Route::resource('transaction_cf','TransactionsCFController');

//Routes for Reports
Route::get('/reports', "ReportsController@list");
Route::get('/reports/memos', 'ReportsController@get_memos');
Route::get('/reports/memos/list', 'ReportsController@list_memos');
Route::get('/reports/disposition', 'ReportsController@get_disposition');
Route::get('/reports/disposition/list', 'ReportsController@list_disposition');
Route::get('/reports/disposition/list/print', 'ReportsController@print_disposition');

//Routes for office
Route::resource('offices','OfficesController');

//Routes for users
Route::resource('users','UsersController');
Route::post('/update_username', 'UsersController@update_username');
Route::post('/disable_user', 'UsersController@disable_user');

//Routes for record category
Route::resource('categories','CategoryController');

//Routes for records
Route::get('/records/search', 'RecordsController@search');
Route::post('/records/get_records', 'RecordsController@get_records');
Route::get('/records/offices/','RecordsController@listOffice');
Route::get('/records/offices/{office_id}/categories','RecordsController@listOfficeCategories');
Route::get('/records/offices/{office_id}/categories/{category_id}','RecordsController@listOfficeCategoriesRecords');
Route::get('/records/categories/', 'CategoryController@index');
Route::get('/records/categories/{category_id}', 'RecordsController@listCategoriesRecords');
Route::get('/records/download/{id}', 'RecordsController@download');
Route::resource('records','RecordsController');

//Routes for Customization
Route::get('/customize', 'SchoolsController@show');
Route::post('/school/edit', 'SchoolsController@update');
Route::post('/school/edit/photo', 'SchoolsController@update_photo');

//ajax calls
Route::get('/get-office-per-category/{category_id}', 'AjaxController@getOfficePerCategory');
Route::get('/get-category-internal/{isInternal}', 'AjaxController@getCategoryInternal');
Route::get('/get-category-per-office', 'AjaxController@getCategoryPerOffice');
Route::get('/get-category/{id}', 'AjaxController@getCategory');
Route::get('/get-transaction-flow/{id}', 'AjaxController@getTransactionFlow');

/** Tests*/
/*
Route::get('/test/{barcode}', function($barcode){
    //return $barcode;
    //$status = App\Status::where('flow',0)->with('child')->get();
    //$status = App\Status::child($id)->get();
    $office_id = 31;
    $status = App\Status::where('flow', 0)->with(['child'=>function($query) use ($barcode, $office_id){
        $query->where('barcode_value', $barcode)->with('child');
            //->where('office_id', $office_id);
    }])->get();
    return $status;
});
*/