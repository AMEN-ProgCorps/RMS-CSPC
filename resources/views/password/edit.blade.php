@extends('layouts.app')

@section('css_files')
@endsection

@section('content')
    <div class="row clearfix">
        <div class="col-xs-12 col-sm-12 col-md-8 col-lg-8">
            <div class="card">
                <div class="header">
                    <h2>UPDATE PASSWORD</h2>
                </div>
                <div class="body">
                    <form action="{{action('PasswordsController@update', $user->id)}}" method="POST">
                        @method('put')
                        @csrf
                        <div class="form-group form-float">
                            <div class="form-line">
                                <input type="password" name="password" class="form-control" required>
                                <label class="form-label">Password</label>
                            </div>
                        </div>
                        <div class="form-group form-float">
                            <div class="form-line">
                                <input type="password" name="new_password_1" class="form-control" required>
                                <label class="form-label">New Password</label>
                            </div>
                        </div>
                        <div class="form-group form-float">
                            <div class="form-line">
                                <input type="password" name="new_password_2" class="form-control" required>
                                <label class="form-label">Retype New Password</label>
                            </div>
                        </div>
                        <div class="align-right button-demo">
                            <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                                <i class="material-icons">update</i>
                                <span>UPDATE PASSWORD</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js_files')
    <!-- Select Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-select/js/bootstrap-select.js')}}"></script>
@endsection