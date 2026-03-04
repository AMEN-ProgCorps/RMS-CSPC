@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="col-lg-6 col-md-6 col-sm-12 col-xs-12">
        <div class="card">
            <div class="header">
                <h2>
                    RETURN TRANSACTION
                </h2>
            </div>
            <div class="body">
                <form action="/returned_transaction" method="POST">
                    @csrf
                    <div class="row clearfix">
                        <div class="col-sm-12">
                            <h2 class="card-inside-title">Return to: </h2>
                            <div class="form-group">
                                <div class="form-line">
                                    <h2 type="text" class="form-control">{!!$transaction->status[count($transaction->status)-2]->office->description!!}</h2>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row clearfix">
                        <div class="col-sm-12">
                            <h2 class="card-inside-title">Notes: </h2>
                            <div class="form-group">
                                <div class="form-line">
                                    <textarea rows="4" class="form-control no-resize" name="notes"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" value="{!!$transaction->id!!}" name="transaction_id">
                    <input type="hidden" value="{!!$office_id!!}" name="office_id">
                    <div class="align-right button-demo">
                        <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                            <i class="material-icons">arrow_back</i>
                            <span>RETURN TRANSACTION</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('js_files')
    <!-- Select Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-select/js/bootstrap-select.js')}}"></script>
@endsection