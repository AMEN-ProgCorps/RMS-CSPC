@extends('layouts.app')

@section('css_files')
    <!-- JQuery DataTable Css -->
    <link href="{{asset('/plugins/jquery-datatable/skin/bootstrap/css/dataTables.bootstrap.css')}}" rel="stylesheet">
@endsection

@section('content')
    <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
        <div class="card">
            <div class="body">
                @if(count($offices)>0)
                    <div class="table-responsive">
                        <table id="transaction_table" class="table table-bordered table-striped table-hover js-basic-example dataTable">
                            <thead>
                                <tr>
                                    <th>OFFICES</th>
                                </tr>
                            </thead>
                            <!--
                            <tfoot>
                                <tr>
                                    <th>OFFICES</th>
                                </tr>
                            </tfoot>
                            -->
                            <tbody>
                                @foreach ($offices as $office)
                                    <tr>
                                        <td><a href="/records/offices/{!!$office->id!!}/categories">{!!$office->description!!}</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <h2>NO OFFICE</h2>
                @endif
            </div>
        </div>
    </div>
@endsection

@section('js_files')
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
    <script src="{{asset('/js/pages/tables/jquery-datatable.js')}}"></script>
@endsection