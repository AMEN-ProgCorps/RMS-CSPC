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
                        LIST OF OFFICES
                    </h2>
                </div>
                <div class="body">
                    <table cellborder="1" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>
                                    <h5>An Office can only be deleted if it doesn't have any Transactions and/or Records!</h5>
                                </th>
                                <th>
                                    <div class="align-right button-demo">
                                        <button type="button" class="btn btn-sm bg-light-blue waves-effect" data-toggle="modal" data-target="#addModal" >
                                            <i class="material-icons">add</i>
                                            <span>ADD NEW OFFICE</span>
                                        </button>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                    </table>
                    <br>
                    <div class="table-responsive">
                        <table id="transaction_table" class="table table-bordered table-striped table-hover js-basic-example dataTable">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if(count($offices)>0)
                                    @foreach ($offices as $office)
                                        <tr>
                                            <td>{!!$office->Code!!}</td>
                                            <td>{!!$office->description!!}</td>
                                            <td class="align-center">
                                                <a href="#" data-toggle="modal" data-target="#editModal" data-code="{!!$office->Code!!}" 
                                                    data-description="{!!$office->description!!}" data-office_id="{!!$office->id!!}">
                                                    Edit
                                                </a>
                                                |
                                                <a href="#" data-toggle="modal" data-target="#deleteModal" data-code="{!!$office->Code!!}" 
                                                    data-description="{!!$office->description!!}" data-office_id="{!!$office->id!!}">
                                                    Delete
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td>NO OFFICE CREATED</td><td></td><td></td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
 
    <!--Add Modal-->
    <div class="modal fade" id="addModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">ADD NEW OFFICE</h4>
                </div>
                <div class="modal-body">
                    <form action="{{ action('OfficesController@store') }}" method="POST">
                        @csrf
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">code</i>
                            </span>
                            <div class="form-line">
                                <input type="text" class="form-control" name="code" placeholder="Office Code" required autofocus>
                            </div>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_balance</i>
                            </span>
                            <div class="form-line">
                                <input type="text" class="form-control" name="description" placeholder="Office Name" required autofocus>
                            </div>
                        </div>
                        <div class="align-right button-demo">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">CREATE OFFICE</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!--End Add Modal-->
    <!--Edit Modal-->
    <div class="modal fade" id="editModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">UPDATE OFFICE INFORMATION</h4>
                </div>
                <div class="modal-body">
                    <form action="{{ action('OfficesController@update', 'update') }}" method="POST">
                        @method('patch')
                        @csrf
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">code</i>
                            </span>
                            <div class="form-line">
                                <input type="text" class="form-control" name="code" id="code" placeholder="Office Code">
                            </div>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_balance</i>
                            </span>
                            <div class="form-line">
                                <input type="text" class="form-control" name="description" id="description" placeholder="Office Description">
                            </div>
                        </div>
                        <input type="hidden" id="id" name="id">
                        <div class="align-right button-demo">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">UPDATE OFFICE</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!--End Edit Modal-->
    <!--Delete Modal-->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">DO YOU WANT TO DELETE THE FOLLOWING OFFICE?</h4>
                </div>
                <div class="modal-body">
                    <form action="{{ action('OfficesController@destroy', 'delete') }}" method="POST">
                        @method('delete')
                        @csrf
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">person</i>
                            </span>
                            <h5 id="code"></h5>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_balance</i>
                            </span>
                            <h5 id="description"></h5>
                        </div>
                        <input type="hidden" id="id" name="id">
                        <div class="align-right button-demo">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">DELETE OFFICE</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!--End Delete Modal-->
@endsection

@section('js_files')
    <!-- Select Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-select/js/bootstrap-select.js')}}"></script>

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

    <script>
        $('#editModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var code = button.data('code')
            var description = button.data('description')
            var office_id = button.data('office_id')
            var modal = $(this)
            //modal.find('.modal-title').text('New message to ' + name)
            //modal.find('.modal-body #name').val(name)
            modal.find('.modal-body #code').val(code);
            modal.find('.modal-body #description').val(description);
            modal.find('.modal-body #id').val(office_id);
        })
        $('#deleteModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var code = button.data('code')
            var description = button.data('description')
            var office_id = button.data('office_id')
            var modal = $(this)
            //modal.find('.modal-title').text('New message to ' + name)
            //modal.find('.modal-body #name').val(name)
            modal.find('.modal-body #code').text(code);
            modal.find('.modal-body #description').text(description);
            modal.find('.modal-body #id').val(office_id);
        })
    </script>
@endsection