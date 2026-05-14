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
                    <h2>LIST OF USERS</h2>
                </div>
                <div class="body"><table cellborder="1" style="width: 100%;">
                    <table cellborder="1" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>
                                    <h5>A User can only be deleted if he/she didn't create any Transactions and/or Records!</h5>
                                </th>
                                <th>
                                    <div class ="align-right">
                                        <a class="btn btn-sm bg-light-blue waves-effect" href="/users/create">
                                            <i class="material-icons">add</i>
                                            <span>ADD NEW USER</span>
                                        </a>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                    </table>
                    <br>
                    {{-- Nav tabs  --}}
                    <ul class="nav nav-tabs tab-nav-right" role="tablist">
                        <li role="presentation" class="active"><a href="#active" data-toggle="tab">Active</a></li>
                        <li role="presentation"><a href="#disabled" data-toggle="tab">Disabled</a></li>
                    </ul>

                    {{-- Tab panes --}}
                    <div class="tab-content">
                        <div role="tabpanel" class="tab-pane fade in active" id="active">
                            <div class="table-responsive">
                                <table id="transaction_table" class="table table-bordered table-striped table-hover js-basic-example dataTable">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Office</th>
                                            <th>Type</th>
                                            <th style="width: 12%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @if(count($users_active)>0)
                                            @foreach ($users_active as $user)
                                                    @if(!$user->fname)
                                                        <td>{!!$user->name!!}</td>        
                                                    @else
                                                        <td>{!!$user->lname!!}, {!!$user->fname!!} {!!$user->mi!!}</td>     
                                                    @endif
                                                    <td>{!!$user->uname!!}</td>
                                                    <td>{!!$user->emailAdd!!}</td>
                                                    <td>{!!$user->assigned_to['description']!!}</td>
                                                    <td>{!! $user->priv == 'Standard User' ? 'Basic User' : 'Admin' !!}</td>
                                                    <td class="align-center">
                                                        <a href="#" data-toggle="modal" data-target="#viewModal" data-name="{!!$user->name!!}"
                                                            data-uname="{!!$user->uname!!}" data-email="{!!$user->emailAdd!!}" data-office="{!!$user->assigned_to['description']!!}" 
                                                            data-cp="{!!$user->CPno!!}" data-fname="{!!$user->fname!!}" data-mi="{!!$user->mi!!}" data-lname="{!!$user->lname!!}">
                                                            View
                                                        </a>
                                                        @if( $user->priv != 'SuperAdmin')
                                                            |
                                                            <a href="/users/{!!$user->id!!}/edit">
                                                                Edit
                                                            </a>
                                                            <br>
                                                            <a href="#" data-toggle="modal" data-target="#deleteModal" data-id="{!!$user->id!!}" data-name="{!!$user->name!!}"
                                                                    data-uname="{!!$user->uname!!}" data-email="{!!$user->emailAdd!!}" data-office="{!!$user->assigned_to['description']!!}" 
                                                                    data-cp="{!!$user->CPno!!}" data-fname="{!!$user->fname!!}" data-mi="{!!$user->mi!!}" data-lname="{!!$user->lname!!}">
                                                                Delete
                                                            </a>
                                                            |
                                                            <a href="#" data-toggle="modal" data-target="#disableModal" data-id="{!!$user->id!!}" data-name="{!!$user->name!!}"
                                                                    data-uname="{!!$user->uname!!}" data-email="{!!$user->emailAdd!!}" data-office="{!!$user->assigned_to['description']!!}" 
                                                                    data-cp="{!!$user->CPno!!}" data-fname="{!!$user->fname!!}" data-mi="{!!$user->mi!!}" data-lname="{!!$user->lname!!}">
                                                                Disable
                                                            </a>
                                                        @endif
                                                        @if ( $user->priv == 'SuperAdmin' && Auth::user()->priv == 'SuperAdmin')
                                                            |
                                                            <a href="/users/{!!$user->id!!}/edit">
                                                                Edit
                                                            </a>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @else
                                            <tr>
                                                <td>NO ACTIVE USERS</td><td></td><td></td><td></td><td></td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div role="tabpanel" class="tab-pane fade" id="disabled">
                            <div class="table-responsive">
                                <table id="transaction_table" class="table table-bordered table-striped table-hover js-basic-example dataTable">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Office</th>
                                            <th style="width: 12%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @if(count($users_disabled)>0)
                                            @foreach ($users_disabled as $user)
                                                    @if(!$user->fname)
                                                        <td>{!!$user->name!!}</td>        
                                                    @else
                                                        <td>{!!$user->lname!!}, {!!$user->fname!!} {!!$user->mi!!}</td>     
                                                    @endif
                                                    <td>{!!$user->uname!!}</td>
                                                    <td>{!!$user->emailAdd!!}</td>
                                                    <td>{!!$user->assigned_to['description']!!}</td>
                                                    <td class="align-center">
                                                        <a href="#" data-toggle="modal" data-target="#viewModal" data-name="{!!$user->name!!}"
                                                            data-uname="{!!$user->uname!!}" data-email="{!!$user->emailAdd!!}" data-office="{!!$user->assigned_to['description']!!}" 
                                                            data-cp="{!!$user->CPno!!}" data-fname="{!!$user->fname!!}" data-mi="{!!$user->mi!!}" data-lname="{!!$user->lname!!}">
                                                            View
                                                        </a>
                                                        |
                                                        <a href="/users/{!!$user->id!!}/edit">
                                                            Edit
                                                        </a>
                                                        <br>
                                                        <a href="#" data-toggle="modal" data-target="#deleteModal" data-id="{!!$user->id!!}" data-name="{!!$user->name!!}"
                                                                data-uname="{!!$user->uname!!}" data-email="{!!$user->emailAdd!!}" data-office="{!!$user->assigned_to['description']!!}" 
                                                                data-cp="{!!$user->CPno!!}" data-fname="{!!$user->fname!!}" data-mi="{!!$user->mi!!}" data-lname="{!!$user->lname!!}">
                                                            Delete
                                                        </a>
                                                        |
                                                        <a href="#" data-toggle="modal" data-target="#activateModal" data-id="{!!$user->id!!}" data-name="{!!$user->name!!}"
                                                                data-uname="{!!$user->uname!!}" data-email="{!!$user->emailAdd!!}" data-office="{!!$user->assigned_to['description']!!}" 
                                                                data-cp="{!!$user->CPno!!}" data-fname="{!!$user->fname!!}" data-mi="{!!$user->mi!!}" data-lname="{!!$user->lname!!}">
                                                            Activate
                                                        </a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @else
                                            <tr>
                                                <td>NO DISABLED USERS</td><td></td><td></td><td></td><td></td>
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
    <!--View Modal-->
    <div class="modal fade" id="viewModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">User Information</h4>
                </div>
                <div class="modal-body">
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">person</i>
                        </span>
                        <h5 id="name"></h5>
                    </div>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">face</i>
                        </span>
                        <h5 id="uname"></h5>
                    </div>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">account_balance</i>
                        </span>
                        <h5 id="office"></h5>
                    </div>               
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">email</i>
                        </span>
                        <h5 id="email"></h5>
                    </div>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">phone_android</i>
                        </span>
                        <h5 id="cp"></h5>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                </div>
            </div>
        </div>
    </div>
    <!--End View Modal-->
    <!--Delete Modal-->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">DO YOU WANT TO DELETE THE FOLLOWING USER?</h4>
                </div>
                <div class="modal-body">
                    <form action="{{ action('UsersController@destroy', 'delete') }}" method="POST">
                        @method('delete')
                        @csrf
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">person</i>
                            </span>
                            <h5 id="name"></h5>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">face</i>
                            </span>
                            <h5 id="uname"></h5>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_balance</i>
                            </span>
                            <h5 id="office"></h5>
                        </div>               
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">email</i>
                            </span>
                            <h5 id="email"></h5>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">phone_android</i>
                            </span>
                            <h5 id="cp"></h5>
                        </div>
                        <input type="hidden" id="id" name="id">
                        <div class="align-right">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">DELETE USER</button>
                            <br>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!--End Delete Modal-->
    <!--Disable Modal-->
    <div class="modal fade" id="disableModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">DO YOU WANT TO DISABLE THE FOLLOWING USER?</h4>
                </div>
                <div class="modal-body">
                    <form action="/disable_user" method="POST">
                        @csrf
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">person</i>
                            </span>
                            <h5 id="name"></h5>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">face</i>
                            </span>
                            <h5 id="uname"></h5>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_balance</i>
                            </span>
                            <h5 id="office"></h5>
                        </div>               
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">email</i>
                            </span>
                            <h5 id="email"></h5>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">phone_android</i>
                            </span>
                            <h5 id="cp"></h5>
                        </div>
                        <input type="hidden" id="id" name="id">
                        <input type="hidden" id="disabled" name="disabled" value="1">
                        <div class="align-right">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">DISABLE USER</button>
                            <br>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!--End Disable Modal-->
    <!--Activate Modal-->
    <div class="modal fade" id="activateModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">DO YOU WANT TO ACTIVATE THE FOLLOWING USER?</h4>
                </div>
                <div class="modal-body">
                    <form action="/disable_user" method="POST">
                        @csrf
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">person</i>
                            </span>
                            <h5 id="name"></h5>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">face</i>
                            </span>
                            <h5 id="uname"></h5>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_balance</i>
                            </span>
                            <h5 id="office"></h5>
                        </div>               
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">email</i>
                            </span>
                            <h5 id="email"></h5>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">phone_android</i>
                            </span>
                            <h5 id="cp"></h5>
                        </div>
                        <input type="hidden" id="id" name="id">
                        <input type="hidden" id="disabled" name="disabled" value="0">
                        <div class="align-right">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">ACTIVATE USER</button>
                            <br>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!--End Activate Modal-->
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
        $('#viewModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var name = button.data('name')
            var uname = button.data('uname')
            var fname = button.data('fname')
            var mi = button.data('mi')
            var lname = button.data('lname')
            var office = button.data('office')
            var email = button.data('email')
            var cp = button.data('cp')
            var modal = $(this)

            if(!fname){
                modal.find('.modal-body #name').text(name);
            }else{
                modal.find('.modal-body #name').text(lname+', '+fname+' '+mi);
            }

            modal.find('.modal-body #uname').text(uname);
            modal.find('.modal-body #office').text(office);
            modal.find('.modal-body #email').text(email);
            modal.find('.modal-body #cp').text(cp);
        })
        $('#deleteModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var name = button.data('name')
            var uname = button.data('uname')
            var fname = button.data('fname')
            var mi = button.data('mi')
            var lname = button.data('lname')
            var office = button.data('office')
            var email = button.data('email')
            var cp = button.data('cp')
            var id = button.data('id')
            var modal = $(this)

            if(!fname){
                modal.find('.modal-body #name').text(name);
            }else{
                modal.find('.modal-body #name').text(lname+', '+fname+' '+mi);
            }

            modal.find('.modal-body #uname').text(uname);
            modal.find('.modal-body #office').text(office);
            modal.find('.modal-body #email').text(email);
            modal.find('.modal-body #cp').text(cp);
            modal.find('.modal-body #id').val(id);
        })
        $('#disableModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var name = button.data('name')
            var uname = button.data('uname')
            var fname = button.data('fname')
            var mi = button.data('mi')
            var lname = button.data('lname')
            var office = button.data('office')
            var email = button.data('email')
            var cp = button.data('cp')
            var id = button.data('id')
            var modal = $(this)

            if(!fname){
                modal.find('.modal-body #name').text(name);
            }else{
                modal.find('.modal-body #name').text(lname+', '+fname+' '+mi);
            }

            modal.find('.modal-body #uname').text(uname);
            modal.find('.modal-body #office').text(office);
            modal.find('.modal-body #email').text(email);
            modal.find('.modal-body #cp').text(cp);
            modal.find('.modal-body #id').val(id);
        })
        $('#activateModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var name = button.data('name')
            var uname = button.data('uname')
            var fname = button.data('fname')
            var mi = button.data('mi')
            var lname = button.data('lname')
            var office = button.data('office')
            var email = button.data('email')
            var cp = button.data('cp')
            var id = button.data('id')
            var modal = $(this)

            if(!fname){
                modal.find('.modal-body #name').text(name);
            }else{
                modal.find('.modal-body #name').text(lname+', '+fname+' '+mi);
            }

            modal.find('.modal-body #uname').text(uname);
            modal.find('.modal-body #office').text(office);
            modal.find('.modal-body #email').text(email);
            modal.find('.modal-body #cp').text(cp);
            modal.find('.modal-body #id').val(id);
        })
    </script>
@endsection