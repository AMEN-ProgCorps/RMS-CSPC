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
                        LIST OF TRANSACTION FLOW
                    </h2>
                </div>
                <div class="body">
                    <table cellborder="1" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>
                                    <h5>Warning! Updating and Deleting a Transaction Flow will affect on-going Transactions that is using it!</h5>
                                </th>
                                <th>
                                    <div class="align-right button-demo">
                                        <a class="btn btn-sm bg-light-blue waves-effect" href="/flows/create">
                                            <i class="material-icons">add</i>
                                            <span>ADD TRANSACTION FLOW</span>
                                        </a>
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
                                    <th style="width: 5%">#</th>
                                    <th>Description</th>
                                    <th style="width: 15%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if(count($transaction_natures)>0)
                                    @foreach ($transaction_natures as $index=>$transaction_nature)
                                            <td>{!!$index+1!!}</td>
                                            <td>{!!$transaction_nature->description!!}</td>
                                            <td class="align-center">
                                                {{-- <a href="/flows/{!!$transaction_nature->Nature_id!!}">
                                                    View
                                                </a> --}}
                                                <a href="#" data-toggle="modal" data-target="#listedPathModal" 
                                                    data-id="{!!$transaction_nature->Nature_id!!}" data-description="{!!$transaction_nature->description!!}">
                                                    View
                                                </a>
                                                |
                                                <a href="/flows/{!!$transaction_nature->Nature_id!!}/edit">
                                                    Edit
                                                </a>
                                                |
                                                <a href="#" data-toggle="modal" data-target="#deleteModal" 
                                                    data-id="{!!$transaction_nature->Nature_id!!}" data-description="{!!$transaction_nature->description!!}">
                                                    Delete
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td>NO TRANSACTION FLOW CREATED</td><td></td><td></td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- Delete Modal --}}
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">DO YOU WANT TO DELETE THE FOLLOWING TRANSACTION FLOW?</h4>
                </div>
                <div class="modal-body">
                    <form action="{{ action('FlowsController@destroy', 'delete') }}" method="POST">
                        @method('delete')
                        @csrf
                        <div class="row clearfix">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <div class="form-line">
                                        <h5 id="description"></h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="id" name="id">
                        <div class="align-right button-demo">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">DELETE TRANSACTION FLOW</button>
                            <br>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    {{-- End Delete Modal --}}

    {{-- Show Listed Path Modal --}}
    <div class="modal fade" id="listedPathModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel"></h4>
                </div>
                <div class="modal-body">
                    <div class="col-sm-12">
                        <div class="form-group">
                            <h4 class="card-inside-title" id="description"></h4>
                            <div class="form-line">
                                <ul class="list-group" id="list">
                                </ul>
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
    {{-- End Show Listed Path Modal --}}
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
        $('#deleteModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var id = button.data('id')
            var description = button.data('description')
            var modal = $(this)
            modal.find('.modal-body #id').val(id);
            modal.find('.modal-body #description').text(description);
        })

        $('#listedPathModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var id = button.data('id')
            var description = button.data('description');
            var modal = $(this)

            $("#list").empty(); 
            $.get('/get-transaction-flow/'+id, function(data){
                $.each(data, function (i, item) {
                    var ul = document.getElementById("list");
                    var li = document.createElement("li");
                    li.setAttribute("class", "list-group-item");
                    var office = item['office']['description'];
                    li.appendChild(document.createTextNode(i+1+') '+office));
                    ul.appendChild(li);
                });
            })

            modal.find('.modal-body #description').text(description);
        })

    </script>
@endsection