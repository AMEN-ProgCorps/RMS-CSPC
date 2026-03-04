@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
    <!-- Animation Css -->
    <link href="{{asset('/plugins/animate-css/animate.css')}}" rel="stylesheet" />
    <!-- Multi Select Css -->
    <link href="{{asset('/plugins/multi-select/css/multi-select.css')}}" rel="stylesheet">
    <!-- Bootstrap DatePicker Css -->
    <link href="{{asset('/plugins/bootstrap-datepicker/css/bootstrap-datepicker.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="col-xs-12 col-sm-12 col-md-8 col-lg-8">
        <div class="row clearfix">
            <div class="card">
                <div class="header"><h2>SEARCH TRANSACTIONS</h2></div>
                <div class="body">
                    <form action="/get_transactions" method="POST">
                        @csrf
                        <div class="row clearfix">
                            <div class="col-md-4">
                                <h2 class="card-inside-title">Control #: </h2>
                                <div class="form-group">
                                    <div class="form-line">
                                        <input name='control_no' type="text" class="form-control" placeholder="YYYY-MM-#" autofocus>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-12">
                                <h5 class="card-inside-title">Barcode: </h5>
                                <div class="form-group">
                                    <div class="form-line">
                                        <input name="barcode" type="text" class="form-control" placeholder="Document Barcode" >
                                    </div>
                                </div>
                            </div>
                            @if (Auth::user()->priv != "Standard User" || Auth::user()->office == 1)
                                <div class="col-sm-12">
                                    <h4 class="card-inside-title">Originating Office: </h4>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <select id="offices" class="form-control show-tick" name="office_id" title="Select Office:">
                                                <option value="" selected>---BLANK---</option>
                                                @if (count($offices)>0)
                                                    @foreach ($offices as $office)
                                                        <option value="{!!$office->id!!}">{!!$office->description!!}</option>
                                                    @endforeach
                                                @endif
                                            </select>
                                        </div>
                                    </div>
                                </div> 
                            @else
                                <div class="col-sm-12">
                                    <h4 class="card-inside-title">Originating Office: </h4>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <select id="offices" class="form-control show-tick" name="office_id" title="Select Office:">
                                                <option value="" selected>---BLANK---</option>
                                                @if (count($offices)>0)
                                                    @foreach ($offices as $office)
                                                        @if ($office->id == Auth::user()->office)
                                                            <option value="{!!$office->id!!}">{!!$office->description!!}</option>
                                                        @endif
                                                    @endforeach
                                                @endif
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            @endif
                            <div class="col-sm-12">
                                <h5 class="card-inside-title">Particulars: </h5>
                                <div class="form-group">
                                    <div class="form-line">
                                        <textarea name="description" rows="2" class="form-control no-resize disabled"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xs-12">
                                <h2 class="card-inside-title">Date Created: </h2>
                                <div class="input-daterange input-group" id="bs_datepicker_range_container">
                                    <div class="form-line">
                                        <input type="text" class="form-control" placeholder="Date start..." name="from" id="from" >
                                    </div>
                                    <span class="input-group-addon">to</span>
                                    <div class="form-line">
                                        <input type="text" class="form-control" placeholder="Date end..." name="to" id="to" >
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-12 align-right">
                                <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                                    <i class="material-icons">search</i>
                                    <span>SEARCH TRANSACTIONS</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js_files')
    <!-- Bootstrap Colorpicker Js -->
    <script src="{{asset('/plugins/bootstrap-colorpicker/js/bootstrap-colorpicker.js')}}"></script>
    <!-- Dropzone Plugin Js -->
    <script src="{{asset('/plugins/dropzone/dropzone.js')}}"></script>
    <!-- Input Mask Plugin Js -->
    <script src="{{asset('/plugins/jquery-inputmask/jquery.inputmask.bundle.js')}}"></script>
    <!-- Multi Select Plugin Js -->
    <script src="{{asset('/plugins/multi-select/js/jquery.multi-select.js')}}"></script>
    <!-- Select Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-select/js/bootstrap-select.js')}}"></script>
    <!-- Autosize Plugin Js -->
    <script src="{{asset('/plugins/autosize/autosize.js')}}"></script>
    <!-- Moment Plugin Js -->
    <script src="{{asset('/plugins/momentjs/moment.js')}}"></script>
    <!-- Bootstrap Material Datetime Picker Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-material-datetimepicker/js/bootstrap-material-datetimepicker.js')}}"></script>
    <!-- Bootstrap Datepicker Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-datepicker/js/bootstrap-datepicker.js')}}"></script>

    <script src="{{asset('/js/pages/forms/basic-form-elements.js')}}"></script>
    <script src="{{asset('/js/pages/forms/advanced-form-elements.js')}}"></script>
@endsection