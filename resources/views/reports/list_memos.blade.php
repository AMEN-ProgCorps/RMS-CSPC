@extends('layouts.app')

@section('css_files')
    <!-- JQuery DataTable Css -->
    <link href="{{asset('/plugins/jquery-datatable/skin/bootstrap/css/dataTables.bootstrap.css')}}" rel="stylesheet">
@endsection

@section('content')
    <div class="row clearfix">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
            <div class="card">
                <div class="header">
                    <h2>
                        List of Memorandum {{$type == 1 ? "From ":"For "}} {{$office->description}}
                    </h2>
                </div>
                <div class="body">
                    {{-- <div class="align-right">
                        <a role="button" class="btn btn-sm bg-light-blue btn-primary waves-effect" href="#" data-toggle="modal" data-target="#printModal">
                            <i class="material-icons">print</i>
                            <span>PRINT...</span>
                        </a>
                    </div> --}}
                    <br>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover js-basic-example dataTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>From</th>
                                    <th>Description</th>
                                    <th>Start Date</th>
                                    <th>Download</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if (count($records)>0)
                                    @foreach ($records as $index => $record)
                                        <tr>
                                            <td>{!!$index+1!!}</td>
                                            <td>{!!$record->originating_office->description!!}</td>
                                            <td>{!!$record->description!!}</td>
                                            <td>{!!$record->start_date!!}</td>
                                            <td class="align-center">
                                                <a href="/records/download/{!!$record->id!!}">
                                                    <i class="material-icons" style="width: 12%; height: 12%"/>file_download</i>
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!--print Modal-->
    <div class="modal fade" id="printModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">PRINT RECORDS?</h4>
                </div>
                <div class="modal-body">
                    <div class="row clearfix">
                        <form target="_blank" action="/reports/disposition/list/print" method="GET">
                            @csrf
                            <div class="col-xs-12">
                                <input type="hidden" name="type" value="2">
                                <input type="hidden" name="from" value="{!!$from!!}">
                                <input type="hidden" name="to" value="{!!$to!!}">
                            </div>
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> PRINT </button>
                                <br>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="modal-footer">
                </div>
            </div>
        </div>
    </div>
    <!--End print Modal-->
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