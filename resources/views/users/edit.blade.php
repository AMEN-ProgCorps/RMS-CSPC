@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="signup-box">
        <div class="card">
            <div class="body">
                <div class="header"><h4>UPDATE USER INFO</h4>
                    <div class ="header-dropdown m-r--5">
                        <a class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#usernameupdate">
                            <i class="material-icons">face</i>
                            <span>UPDATE USERNAME</span>
                        </a>
                        @if (Auth::user()->office == 1) {{-- for Records Office Users only --}}
                            <a class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#resetModal">
                                <i class="material-icons">refresh</i>
                                <span>RESET PASSWORD</span>
                            </a>
                        @endif
                    </div>
                </div>
                <form id="sign_up" action="{{action('UsersController@update', $user->id)}}" method="POST">
                    @method('patch')
                    @csrf
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">face</i>
                        </span>
                        <div class="form-line">
                            <h5>{!!$user->uname!!}</h5>
                        </div>
                        
                    </div>
                    @if (Auth::user()->priv == 'Standard User')
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_balance</i>
                            </span>
                            <div class="form-line">
                                <h5>{!!$user->assigned_to['description']!!}</h5>
                            </div>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_box</i>
                            </span>
                            <div class="form-line">
                                <h5>Basic User</h5>
                            </div>
                        </div>
                    @endif
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">person</i>
                        </span>
                        <div class="form-line">
                            <input type="text" class="form-control" name="fname" value="{!!$user->fname!!}" placeholder="First Name" required autofocus>
                        </div>
                        <div class="form-line">
                            <input type="text" class="form-control" name="mi" value="{!!$user->mi!!}" placeholder="Middle Initial" >
                        </div>
                        <div class="form-line">
                            <input type="text" class="form-control" name="lname" value="{!!$user->lname!!}" placeholder="Last Name" required>
                        </div>
                    </div>
                    @if (Auth::user()->priv != 'Standard User')
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_balance</i>
                            </span>
                            <select id="optgroup" class="form-control show-tick" name="office_id">
                                @foreach($offices as $office)
                                    @if($office->id != $user->office)
                                        <option value="{{$office->id}}">{{$office->description}}</option>
                                    @else
                                        <option value="{{$office->id}}" selected>{{$office->description}}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_box</i>
                            </span>
                            <select id="optgroup" class="form-control show-tick" name="priv">
                                <option value="" disabled>User Type</option>
                                @if ($user->priv == "Standard User")
                                    <option value="Standard User" selected>Basic User</option>
                                    <option value="Admin" >Admin</option>
                                @else
                                    <option value="Standard User">Basic User</option>
                                    <option value="Admin" selected>Admin</option>
                                @endif
                            </select>
                        </div>                   
                    @endif      
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">email</i>
                        </span>
                        <div class="form-line">
                            <input type="email" class="form-control" name="emailAdd" value="{!!$user->emailAdd!!}" placeholder="Email Address" required>
                        </div>
                    </div>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">phone_android</i>
                        </span>
                        <div class="form-line">
                            <input type="text" class="form-control" name="CPno" value="{!!$user->CPno!!}" placeholder="Mobile Number" required>
                        </div>
                    </div>
                    <div class="align-right button-demo">
                        <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                            <i class="material-icons">update</i>
                            <span>UPDATE USER INFO</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    {{-- Password Reset Modal --}}
    @if (Auth::user()->office == 1)
        <div class="modal fade" id="resetModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">DO YOU WANT TO RESET THE PASSWORD OF THE GIVEN USER?</h4>
                    </div>
                    <div class="modal-body">
                        <form action="/password_reset" method="POST">
                            @csrf
                            <input type="hidden" id="id" name="id" value="{!!$user->id!!}">
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect">RESET PASSWORD</button>
                                <br>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif
    {{-- End Password Reset Modal --}}
    
    {{-- Update Username Modal --}}
    <div class="modal fade" id="usernameupdate" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">UPDATE USERNAME</h4>
                </div>
                <div class="modal-body">
                    <form action="/update_username" method="POST">
                        @csrf
                        <input type="hidden" id="id" name="id" value="{!!$user->id!!}">
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">face</i>
                            </span>
                            <div class="form-line">
                                <input type="text" class="form-control" name="uname" id="uname" placeholder="Username" value="{!!$user->uname!!}" required>
                            </div>
                        </div>
                        <div class="align-right">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">UPDATE USERNAME</button>
                            <br>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    {{-- End Update Username  Modal --}}
@endsection

@section('js_files')
    <!-- Select Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-select/js/bootstrap-select.js')}}"></script>
@endsection