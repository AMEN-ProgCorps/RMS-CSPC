@extends('layouts.app')

@section('css_files')
@endsection

@section('content')
    <div class="container-fluid">
        <div class="col-xs-12 col-sm-12 col-md-8 col-lg-8">
            <div class="row clearfix">
                <div class="card">
                    <div class="header">
                        <h4>RECEIVE TRANSACTION</h4>
                    </div>
                    <div class="body">
                        <form action="/received_transaction" method="POST">
                            @csrf
                            <div class="row clearfix">
                                <div class="col-sm-12">
                                    <h2 class="card-inside-title">Copy Furnished?</h2>
                                    <div class="switch">
                                        <label>No<input type="checkbox" name="copyFurnished" onclick="displayFunction()"><span class="lever switch-col-cyan"></span>Yes</label>
                                    </div>
                                </div>
                                <div class="input-group input-group-lg">
                                    <div class="col-sm-12">
                                        <div class="form-line">
                                            <input name="barcode" type="text" class="form-control" placeholder="Document Barcode" required autofocus>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-12 align-right">
                                    <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                                        <i class="material-icons">file_download</i>
                                        <span>RECEIVE TRANSACTION</span>
                                    </button>
                                </div>
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