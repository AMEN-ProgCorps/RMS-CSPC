@extends('layouts.app')

@section('css_files')
    <!-- JQuery DataTable Css -->
    <link href="{{asset('/plugins/jquery-datatable/skin/bootstrap/css/dataTables.bootstrap.css')}}" rel="stylesheet" />
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
    <!-- Animation Css -->
    <link href="{{asset('/plugins/animate-css/animate.css')}}" rel="stylesheet" />
    <!-- Multi Select Css -->
    <link href="{{asset('/plugins/multi-select/css/multi-select.css')}}" rel="stylesheet">
@endsection

@section('content')
    <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
        <div class="card">
            <div class="body">
                <ol class="breadcrumb">
                    <li><a href="/records/categories/">CATEGORIES</a></li>
                    <li class="active"><a href="javascript:void(0);">{!!$category->code!!}) {!!$category->description!!}</a></li>
                </ol>
                {{-- <div class="align-right button-demo">
                    <button type="button" class="btn btn-sm bg-light-blue waves-effect" data-toggle="modal" data-target="#addModal" 
                        data-category_id="{!!$category->id!!}" data-internal="{!!$category->isInternal!!}">
                        <i class="material-icons">add</i>
                        <span>ADD NEW RECORD</span>
                    </button>
                </div> --}}
                @if(count($category->records)>0)
                    <div class="table-responsive">
                        <table id="transaction_table" class="table table-bordered table-striped table-hover js-basic-example dataTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Originating Office</th>
                                    <th>Description</th>
                                    <th>Start Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($records as $index=>$record)
                                    <tr>
                                        <th>{!!$index+1!!}</th>
                                        <td>
                                            @if ($category->type != 3)
                                                {!!$record->originating_office->Code!!}
                                            @else
                                                From External Office
                                            @endif
                                        </td>
                                        <td>{!!$record->description!!}</td>
                                        <td>{!!$record->start_date!!}</td>
                                        <td>
                                            @php
                                                if($record->status == 1){
                                                    echo("ACTIVE");
                                                }else if($record->status == 2){
                                                    echo("STORAGE");
                                                }else if($record->status == 3){
                                                    echo("FOR DISPOSAL");
                                                }else if($record->status == 4){
                                                    echo("DISPOSED");
                                                }else{
                                                    echo("N/A");
                                                }
                                            @endphp
                                        </td>
                                        <td class="align-center">
                                            <a href="/records/{!!$record->id!!}">
                                                MORE...
                                            </a>
                                            {{--
                                            <a href="/records/{!!$record->id!!}">
                                                <i class="material-icons" style="width: 12%; height: 12%"/>visibility</i>
                                            </a>
                                            <a href="/records/download/{!!$record->id!!}">
                                                <i class="material-icons" style="width: 12%; height: 12%"/>file_download</i>
                                            </a>
                                            @if(Auth::user()->office == 1 || $record->office_id == Auth::user()->office)
                                                <a href="/records/{!!$record->id!!}/edit">
                                                    <i class="material-icons" style="width: 12%; height: 12%"/>mode_edit</i>
                                                </a>
                                                <a href="#" data-toggle="modal" data-target="#deleteModal" data-id="{!!$record->id!!}" data-code="{!!$record->category->code!!}"
                                                        data-category="{!!$record->category->description!!}" data-description="{!!$record->description!!}">
                                                    <i class="material-icons" style="width: 12%; height: 12%"/>delete</i>
                                                </a>  
                                            @endif --}}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <h2>NO RECORDS</h2>
                @endif
            </div>
        </div>
    </div>
    <!--Add Modal-->
    {{-- <div class="modal fade" id="addModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">ADD NEW RECORD</h4>
                </div>
                <div class="modal-body">
                    <form action="{{ action('RecordsController@store') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="col-sm-6">
                            <h5 class="card-inside-title">Type: </h5>
                            <div class="form-group">
                                <div class="form-line">
                                    <div class="demo-radio-button">
                                        <input class="form-control" type="text" value="{!!$category->isInternal ? 'Internal' : 'External' !!}" disabled>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-12">
                            <h5 class="card-inside-title">Code: </h5>
                            <div class="form-group">
                                <div class="form-line">
                                    <input class="form-control" type="text" value="{!!$category->code!!}) {!!$category->description!!}" disabled>
                                </div>
                            </div>
                        </div>
                        @if (Auth::user()->office == 1)
                            <div class="col-sm-6" style="{!! $category->isInternal ? 'display:block' : 'display:none' !!}">
                                <h5 class="card-inside-title">Originating Office: </h5>
                                <div class="form-group">
                                    <div class="form-line">
                                        <select id="offices" class="form-control show-tick" name="office_id" title="Select Office:" data-live-search="true" required>
                                            @foreach ($category->offices as $office)
                                                <option value="{{$office->id}}">{!!$office->description!!}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="col-sm-6" style="{!! $category->isInternal ? 'display:block' : 'display:none' !!}">
                                <h5 class="card-inside-title">Originating Office: </h5>
                                <div class="form-group">
                                    <div class="form-line">
                                        <select id="offices" class="form-control show-tick" name="office_id" title="Select Office:" data-live-search="true" required>
                                            @foreach ($category->offices as $office)
                                                @if(Auth::user()->office == $office->id)
                                                    <option value="{{$office->id}}" selected>{!!$office->description!!}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                        @endif
                        <div class="col-sm-12">
                            <h5 class="card-inside-title">CF to Office(s):</h5>
                            <div class="form-group">
                                <div class="form-line">
                                    <select id="optgroup" class="ms" multiple="multiple" name="office_ids[]" required>
                                        @foreach ($offices as $office)
                                            @if ($office->id != Auth::user()->office)
                                                <option value="{{$office->id}}">{!!$office->description!!}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-12">
                            <h5 class="card-inside-title">Description: </h5>
                            <div class="form-group">
                                <div class="form-line">
                                    <textarea rows="4" class="form-control no-resize disabled" name="description" id="description" required></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <h5 class="card-inside-title">Upload File:</h5>
                            <div class="form-group">
                                <div class="form-line">
                                    <input type="file" name="file" id="file">
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-12">
                            <input type="hidden" class="form-control" name="category_id" id="category_id" value="{!!$category->id!!}">
                            <input type="hidden" class="form-control" name="isInternal" id="isInternal" value="{!!$category->isInternal!!}">
                        </div>
                        <div class="align-right button-demo col-sm-12">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-blue">CREATE</button>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                </div>
            </div>
        </div>
    </div> --}}
    <!--End Add Modal-->
    <!--Delete Modal-->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">DO YOU WANT TO DELETE THE FOLLOWING RECORD?</h4>
                </div>
                <div class="modal-body">
                    <form action="{{ action('RecordsController@destroy', 'delete') }}" method="POST">
                        @method('delete')
                        @csrf
                        <div class="input-group">
                            <h6 class="card-inside-title">Code: </h6>
                            <h5 id="code"></h5>
                        </div>
                        <div class="input-group">
                            <h6 class="card-inside-title">Category: </h6>
                            <h5 id="category"></h5>
                        </div>
                        <div class="input-group">
                            <h6 class="card-inside-title">Description: </h6>
                            <textarea rows="4" class="form-control no-resize disabled" name="description" id="description" disabled></textarea>
                        </div>
                        <input type="hidden" id="id" name="id">
                        <div class="align-right button-demo">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">DELETE RECORD</button>
                            <br>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!--End Delete Modal-->
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

    <script src="{{asset('/js/pages/forms/advanced-form-elements.js')}}"></script>

    <script>
        $('#deleteModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var code = button.data('code')
            var category = button.data('category')
            var description = button.data('description')
            var id = button.data('id')
            var modal = $(this)

            modal.find('.modal-body #code').text(code);
            modal.find('.modal-body #category').text(category);
            modal.find('.modal-body #description').text(description);
            modal.find('.modal-body #id').val(id)
        })
        $('#viewModal').on('show.bs.modal', function (event) {
            //clear offices ul
            $("#offices_view").empty();
            
            var button = $(event.relatedTarget)
            var code = button.data('code')
            var description = button.data('description')
            var upload_date = button.data('upload_date')
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
            modal.find('.modal-body #upload_date').text(upload_date);
            modal.find('.modal-body #start_date').text(start_date);
            modal.find('.modal-body #originating_office').text(originating_office);
        })
        
    </script>
@endsection