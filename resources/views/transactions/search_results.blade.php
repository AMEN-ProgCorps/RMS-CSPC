{{-- List Transactions Originating from the Office --}}
@extends('layouts.app')

@section('css_files')
    <!-- JQuery DataTable Css -->
    <link href="{{asset('/plugins/jquery-datatable/skin/bootstrap/css/dataTables.bootstrap.css')}}" rel="stylesheet">
    <!-- Bootstrap DatePicker Css -->
    <link href="{{asset('/plugins/bootstrap-datepicker/css/bootstrap-datepicker.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="row clearfix">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
            <div class="card">
                <div class="header">
                    <h2>
                        Search Results
                    </h2>
                </div>
                <div class="body">
                    <div class="table-responsive">
                        <table id="transaction_table" class="table table-bordered table-striped table-hover js-basic-example dataTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>From</th>
                                    <th>Date Created</th>
                                    <th>Control #</th>
                                    <th>Particulars</th>
                                    <th>Current Location</th>
                                    <th>Remarks</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @if (count($transactions)>0)
                                    @foreach ($transactions as $i => $transaction)
                                        <tr>
                                            <td>{!!$i+1!!}</td>
                                            <td>{!!$transaction->office->Code!!}</td>
                                            <td>{!!$transaction->Date_created!!}</td>
                                            <td>{!!$transaction->control_no!!}</td>
                                            <td>
                                                {!!$transaction->description!!}
                                            </td>
                                            @if($transaction->freeFlow)
                                                <td>N/A</td>
                                            @else
                                                <td>{!!$transaction->current_location->Code!!}</td>
                                            @endif
                                            <td>{!!$transaction->Remarks!!}</td>
                                            <td><a href="/show_transaction/{!!$transaction->id!!}">View</a></td>
                                        </tr>
                                    @endforeach 
                                @else
                                    <tr>
                                        <td>NO TRANSACTIONS</td><td></td><td></td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
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