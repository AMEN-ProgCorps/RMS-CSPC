@extends('layouts.app')

@section('css_files')
@endsection

@section('content')
    <div class="container-fluid">
        <div class="col-xs-12 col-sm-12 col-md-8 col-lg-8">
            <div class="row clearfix">
                <div class="card">
                    <div class="header">
                        <h4>VIEW TRANSACTION</h4>
                    </div>
                    <div class="body">
                        <form action="/show_transaction/barcode" method="GET">
                            <div class="input-group input-group-lg">
                                <div class="form-line">
                                    <input name="barcode" type="text" class="form-control" placeholder="Document Barcode" required autofocus>
                                </div>
                            </div>
                            <div class="align-right button-demo">
                                <button class="btn btn-sm btn-primary waves-effect" type="submit"><span>VIEW</span></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js_files')
@endsection