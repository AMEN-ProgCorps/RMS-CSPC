@extends('layouts.app')

@section('css_files')
    <!-- JQuery DataTable Css -->
    <link href="{{asset('/plugins/jquery-datatable/skin/bootstrap/css/dataTables.bootstrap.css')}}" rel="stylesheet">
    <!-- Bootstrap DatePicker Css -->
    <link href="{{asset('/plugins/bootstrap-datepicker/css/bootstrap-datepicker.css')}}" rel="stylesheet" />
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <!-- Tabs With Icon Title -->
    <div class="row clearfix">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
            <div class="card">
                <div class="header">
                    <h2>
                        Records Disposition Status for the {!!$office->description!!}
                    </h2>
                </div>
                <div class="body">
                    <!-- Nav tabs -->
                    <ul class="nav nav-tabs" role="tablist">
                        <li role="presentation" class="active">
                            <a href="#home_with_icon_title" data-toggle="tab">
                                <i class="material-icons">folder</i> ACTIVE
                            </a>
                        </li>
                        <li role="presentation">
                            <a href="#profile_with_icon_title" data-toggle="tab">
                                <i class="material-icons">storage</i> STORAGE
                            </a>
                        </li>
                        <li role="presentation">
                            <a href="#messages_with_icon_title" data-toggle="tab">
                                <i class="material-icons">delete</i> FOR DISPOSAL
                            </a>
                        </li>
                    </ul>

                    <!-- Tab panes -->
                    <div class="tab-content">
                        <div role="tabpanel" class="tab-pane fade in active" id="home_with_icon_title">
                            <br>
                            {{-- Active --}}
                            <div class="table-responsive">
                                <table id="transaction_table" class="table table-bordered table-striped table-hover js-basic-example dataTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Category</th>
                                            <th>Description</th>
                                            <th>Start Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @if(count($records_active)>0)
                                            @foreach ($records_active as $index => $record)
                                                <tr>
                                                    <td style="width: 5%">{!!$index+1!!}</td>
                                                    <td>{!!$record->category->code!!}) {!!$record->category->description!!}</td>
                                                    <td>
                                                        <div class="form-group">
                                                            <textarea rows="{{ substr_count( $record->description, "\n" )+1 }}" class="form-control no-resize" readonly>{!!$record->description!!}</textarea>
                                                        </div>
                                                    </td>
                                                    <td style="width: 10%">{!!$record->start_date!!}</td>
                                                    <td class="align-center" style="width: 5%">
                                                        <a href="#" data-toggle="modal" data-target="#viewModal" data-code="{!!$record->category->code!!}) {!!$record->category->description!!}" 
                                                            data-start_date="{!!$record->start_date!!}" data-description="{!!$record->description!!}" data-offices="{{$record->offices}}" data-originating_office="{{$record->originating_office->description}}">
                                                            View
                                                        </a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @else
                                            <tr>
                                                <td>NO RECORDS</td><td></td><td></td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div role="tabpanel" class="tab-pane fade" id="profile_with_icon_title">
                            <br>
                            {{-- Storage --}}
                            <div class="table-responsive">
                                <table id="transaction_table" class="table table-bordered table-striped table-hover js-basic-example dataTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Category</th>
                                            <th>Description</th>
                                            <th>Start Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @if(count($records_storage)>0)
                                            @foreach ($records_storage as $index => $record)
                                                <tr>
                                                    <td style="width: 5%">{!!$index+1!!}</td>
                                                    <td>{!!$record->category->code!!}) {!!$record->category->description!!}</td>
                                                    <td>
                                                        <div class="form-group">
                                                            <textarea rows="{{ substr_count( $record->description, "\n" )+1 }}" class="form-control no-resize" readonly>{!!$record->description!!}</textarea>
                                                        </div>
                                                    </td>
                                                    <td style="width: 10%">{!!$record->start_date!!}</td>
                                                    <td class="align-center" style="width: 5%">
                                                        <a href="#" data-toggle="modal" data-target="#viewModal" data-code="{!!$record->category->code!!}) {!!$record->category->description!!}" 
                                                            data-start_date="{!!$record->start_date!!}" data-description="{!!$record->description!!}" data-offices="{{$record->offices}}" data-originating_office="{{$record->originating_office->description}}">
                                                            View
                                                        </a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @else
                                            <tr>
                                                <td>NO RECORDS</td><td></td><td></td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div role="tabpanel" class="tab-pane fade" id="messages_with_icon_title">
                            {{-- Disposal --}}
                            @if (count($records_disposal)>0)
                                <div class="align-right">
                                    <a role="button" class="btn btn-sm bg-light-blue btn-primary waves-effect" href="#" data-toggle="modal" data-target="#printModal">
                                        <i class="material-icons">print</i>
                                        <span>PRINT...</span>
                                    </a>
                                </div>
                            @endif
                            <br>
                            <div class="table-responsive">
                                <table id="transaction_table" class="table table-bordered table-striped table-hover js-basic-example dataTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Category</th>
                                            <th>Description</th>
                                            <th>Start Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @if(count($records_disposal)>0)
                                            @foreach ($records_disposal as $index => $record)
                                                <tr>
                                                    <td style="width: 5%">{!!$index+1!!}</td>
                                                    <td>{!!$record->category->code!!}) {!!$record->category->description!!}</td>
                                                    <td>
                                                        <div class="form-group">
                                                            <textarea rows="{{ substr_count( $record->description, "\n" )+1 }}" class="form-control no-resize" readonly>{!!$record->description!!}</textarea>
                                                        </div>
                                                    </td>
                                                    <td style="width: 10%">{!!$record->start_date!!}</td>
                                                    <td class="align-center" style="width: 5%">
                                                        <a href="#" data-toggle="modal" data-target="#viewModal" data-code="{!!$record->category->code!!}) {!!$record->category->description!!}" 
                                                            data-start_date="{!!$record->start_date!!}" data-description="{!!$record->description!!}" data-offices="{{$record->offices}}" data-originating_office="{{$record->originating_office->description}}">
                                                            View
                                                        </a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @else
                                            <tr>
                                                <td>NO RECORDS</td><td></td><td></td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- #END# Tabs With Icon Title -->

    <!--View Modal-->
    <div class="modal fade" id="viewModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">RECORD DETAILS</h4>
                </div>
                <div class="modal-body">
                    <div class="col-sm-12">
                        <h5 class="card-inside-title">Code: </h5>
                        <div class="form-group">
                            <div class="form-line">
                                <h5 id="code"></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <h5 class="card-inside-title">Start Date: </h5>
                        <div class="form-group">
                            <div class="form-line">
                                <h5 id="start_date"></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <h5 class="card-inside-title">Office Uploader: </h5>
                        <div class="form-group">
                            <div class="form-line">
                                <h5 id="originating_office"></h5>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-12">
                        <h5 class="card-inside-title">CF to Office(s):</h5>
                        <div class="form-group">
                            <div class="form-line">
                                <ul class="list-group" id="offices_view">
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-12">
                        <h5 class="card-inside-title">Description: </h5>
                        <div class="form-group">
                            <div class="form-line">
                                <textarea id="description" rows="4" class="form-control no-resize disabled" disabled></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="align-right button-demo col-sm-12">
                        <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!--End View Modal-->

    <!--print Modal-->
    <div class="modal fade" id="printModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">PRINT RECORDS FOR DISPOSAL?</h4>
                </div>
                <div class="modal-body">
                    <div class="row clearfix">
                        <form target="_blank" action="/reports/disposition/list/print" method="GET">
                            @csrf
                            <div class="row clearfix">
                                <div class="col-xs-12">
                                    <input type="hidden" name="office_id" value="{!! $office->id !!}">
                                </div>
                                <div class="col-xs-12">
                                    <h5 class="card-inside-title">Orientation: </h5>
                                    <select id="orientation" class="form-control show-tick" name="orientation" required>
                                        <option value="portrait" selected>Portrait</option>
                                        <option value="landscape">Landscape</option>
                                    </select>
                                    <br ><br > <br >
                                </div>
                                <div class="col-xs-12 align-right">
                                    <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                    <button type="submit" class="btn btn-link waves-effect"> PRINT </button>
                                </div>
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
    
    <script>
        $('#printModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var barcode = button.data('barcode')
            var modal = $(this)

            //modal.find('.modal-body #particulars').text(particulars);
            //modal.find('.modal-body #barcode').val(barcode);
        })
        $('#viewModal').on('show.bs.modal', function (event) {
            //clear offices ul
            $("#offices_view").empty();
            
            var button = $(event.relatedTarget)
            var code = button.data('code')
            var description = button.data('description')
            var start_date = button.data('start_date')
            var originating_office = button.data('originating_office')
            var offices = button.data('offices')

            //populate ul with data
            var li = [];
            $.each(offices, function(i, item) {
                li.push('<li class="list-group-item">'+item.description+'</li>');
            });
            $('#offices_view').append(li.join(''));
            
            var modal = $(this)
            modal.find('.modal-body #code').text(code);
            modal.find('.modal-body #description').text(description);
            modal.find('.modal-body #start_date').text(start_date);
            modal.find('.modal-body #originating_office').text(originating_office);
            //Originating Office is the office uploader
        })
    </script>
@endsection