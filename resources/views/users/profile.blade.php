@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="row clearfix">
        <div class="col-xs-12 col-sm-12 col-md-8 col-lg-8">
            <div class="card">
                <div class="header">
                    <h2>Profile</h2>
                </div>
                <div class="body">
                    <form>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">person</i>
                            </span>
                            <div>
                                <h5>{!!$user->fname!!}</h5>
                            </div>
                            <div>
                                <h5>{!!$user->mi!!}</h5>
                            </div>
                            <div>
                                <h5>{!!$user->lname!!}</h5>
                            </div>
                            <div class="form-line"></div>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">face</i>
                            </span>
                            <div class="form-line">
                                <h5>{!!$user->uname!!}</h5>
                            </div>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_box</i>
                            </span>
                            <h5>{!!$user->priv == 'Standard User' ? 'Basic User' : 'Admin'!!}</h5>
                        </div> 
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">account_balance</i>
                            </span>
                            <h5>{!!$user->assigned_to['description']!!}</h5>
                        </div>                   
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">email</i>
                            </span>
                            <div class="form-line">
                                <h5>{!!$user->emailAdd!!}</h5>
                            </div>
                        </div>
                        <div class="input-group">
                            <span class="input-group-addon">
                                <i class="material-icons">phone_android</i>
                            </span>
                            <div class="form-line">
                                <h5>{!!$user->CPno!!}</h5>
                            </div>
                        </div>
                        <div class="align-right button-demo">
                            <a class="btn btn-sm bg-light-blue waves-effect" href="/users/{!!$user->id!!}/edit">
                                <i class="material-icons">edit</i>
                                <span>EDIT PROFILE</span>
                            </a>
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