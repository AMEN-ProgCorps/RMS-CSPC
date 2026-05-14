@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
    <!-- Bootstrap DatePicker Css -->
    <link href="{{asset('/plugins/bootstrap-datepicker/css/bootstrap-datepicker.css')}}" rel="stylesheet" />
    <!-- JQuery DataTable Css -->
    <link href="{{asset('/plugins/jquery-datatable/skin/bootstrap/css/dataTables.bootstrap.css')}}" rel="stylesheet">
@endsection

@section('content')
    <div class="container-fluid">
        <div class="col-xs-9 col-sm-9 col-md-6 col-lg-6">
            <div class="row clearfix">
                <div class="card">
                    <div class="header"><h2>GET DISPOSITION LIST</h2></div>
                    <div class="body">
                        <form action="/reports/disposition/list" method="GET">
                            <div class="row clearfix">
                                @if (count($offices)>0)
                                    <div class="col-xs-12">
                                        <h4 class="card-inside-title">Office: </h4>
                                        <div class="form-group">
                                            <div class="form-line">
                                                <select id="offices" class="form-control show-tick" name="office_id" title="Select Office:">
                                                    @foreach ($offices as $office)
                                                        <option value="{!!$office->id!!}">{!!$office->description!!}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                                {{-- <div class="col-xs-6">
                                    <h2 class="card-inside-title">Date: </h2>
                                    <div class="input-daterange input-group" id="bs_datepicker_range_container">
                                        <div class="form_group">
                                            <div class="form-line">
                                                <input type="text" class="form-control" placeholder="" name="date" required>
                                            </div>
                                        </div>
                                        <span class="input-group-addon">to</span>
                                        <div class="form-line">
                                            <input type="text" class="form-control" placeholder="Date end..." name="to" id="to" required>
                                        </div>
                                    </div>
                                </div> --}}
                                <div class="col-sm-12 align-right">
                                    <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                                        <i class="material-icons">search</i>
                                        <span>GET DISPOSITION DETAILS</span>
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

    <!-- Jquery DataTable Plugin Js -->
    <script src="{{asset('/plugins/jquery-datatable/jquery.dataTables.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/skin/bootstrap/js/dataTables.bootstrap.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/dataTables.buttons.min.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/buttons.flash.min.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/jszip.min.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/pdfmake.min.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/vfs_fonts.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/buttons.html5.min.js')}}"></script>
    <script src="{{asset('/plugins/jquery-datatable/extensions/export/buttons.print.min.js')}}"></script>
    
    <!-- Custom Js -->
    <script src="{{asset('/js/pages/forms/basic-form-elements.js')}}"></script>
    <script src="{{asset('/js/pages/tables/jquery-datatable.js')}}"></script>
@endsection