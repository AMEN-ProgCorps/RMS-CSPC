@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="signup-box">
        <div class="card">
            <div class="body">
                <div class="header"><h4>ADD NEW USER</h4></div>
                <form id="sign_up" action="{{action('UsersController@store')}}" method="POST">
                    @csrf
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">person</i>
                        </span>
                        <div class="form-line">
                            <input type="text" class="form-control" name="fname" placeholder="First Name" required autofocus>
                        </div>
                        <div class="form-line">
                            <input type="text" class="form-control" name="mi" placeholder="Middle Initial" autofocus>
                        </div>
                        <div class="form-line">
                            <input type="text" class="form-control" name="lname" placeholder="Last Name" required autofocus>
                        </div>
                    </div>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">face</i>
                        </span>
                        <div class="form-line">
                            <input type="text" class="form-control" name="uname" placeholder="Username" required>
                        </div>
                    </div>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">account_balance</i>
                        </span>
                        <select id="optgroup" class="form-control show-tick" name="office_id">
                            <option value="" disabled selected>Select Office</option>
                            @foreach($offices as $office)
                                <option value="{{$office->id}}">{{$office->description}}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">account_box</i>
                        </span>
                        <select id="optgroup" class="form-control show-tick" name="priv">
                            <option value="" disabled selected>User Type</option>
                            <option value="Standard User">Basic User</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>                     
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">email</i>
                        </span>
                        <div class="form-line">
                            <input type="email" class="form-control" name="emailAdd" placeholder="Email Address" required>
                        </div>
                    </div>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">phone_android</i>
                        </span>
                        <div class="form-line">
                            <input type="text" class="form-control" name="CPno" placeholder="Mobile Number" required>
                        </div>
                    </div>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">lock</i>
                        </span>
                        <div class="form-line">
                            <input type="password" class="form-control" name="password" minlength="6" placeholder="Password" required>
                        </div>
                    </div>
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="material-icons">lock</i>
                        </span>
                        <div class="form-line">
                            <input type="password" class="form-control" name="password_confirmation" minlength="6" placeholder="Confirm Password" required>
                        </div>
                    </div>
                    <div class="align-right button-demo">
                        <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                            <i class="material-icons">save</i>
                            <span>CREATE USER</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('js_files')
    <!-- Select Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-select/js/bootstrap-select.js')}}"></script>
@endsection