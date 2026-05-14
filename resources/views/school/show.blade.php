@extends('layouts.app')

@section('css_files')
@endsection

@section('content')
    {{-- Example Tab --}}
    <div class="row">
        <div class="col-xs-12 col-sm-12 col-md-8 col-lg-8">
            <div class="card">
                <div class="header">
                    <h2>Customize</h2>
                </div>
                <div class="body">
                    {{-- Nav tabs  --}}
                    <ul class="nav nav-tabs tab-nav-right" role="tablist">
                        <li role="presentation" class="active"><a href="#home" data-toggle="tab">School Info</a></li>
                        <li role="presentation"><a href="#logo" data-toggle="tab">Logo</a></li>
                        <li role="presentation"><a href="#background_1" data-toggle="tab">Log-in Background</a></li>
                        <li role="presentation"><a href="#background_2" data-toggle="tab">Sidebar Background</a></li>
                    </ul>

                    {{-- Tab panes --}}
                    <div class="tab-content">
                        <div role="tabpanel" class="tab-pane fade in active" id="home">
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">school</i>
                                </span>
                                <div class="form-line">
                                    <h5>{!!$school->name!!}</h5>
                                </div>
                            </div>
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">code</i>
                                </span>
                                <div class="form-line">
                                    <h5>{!!$school->code!!}</h5>
                                </div>
                            </div>
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">location_on</i>
                                </span>
                                <div class="form-line">
                                    <h5>{!!$school->address!!}</h5>
                                </div>
                            </div>
                            <div class="align-right">
                                <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#updateModal"
                                    data-name="{!!$school->name!!}" data-code="{!!$school->code!!}" data-address="{!!$school->address!!}">
                                    <i class="material-icons">update</i>
                                    <span>UPDATE SCHOOL INFO</span>
                                </a>
                            </div>
                        </div>
                        <div role="tabpanel" class="tab-pane fade" id="logo">
                            <div class="align-center">
                                <img src="{!! asset($school->logo) !!}" alt="">
                                <h6>Suggested Size: 150px X 150px</h6>
                                <h6>Suggested File Type: PNG</h6>
                            </div>
                            <br>
                            <div class="align-right">
                                <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#updateLogo">
                                    <i class="material-icons">update</i>
                                    <span>UPDATE SCHOOL LOGO</span>
                                </a>
                            </div>
                        </div>
                        <div role="tabpanel" class="tab-pane fade" id="background_1">
                            <div class="align-center">
                                <img src="{!!asset($school->background_1)!!}" width="95%" height="95%" alt="">
                            </div>
                            <br>
                            <div class="align-right">
                                <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#updateBackground1">
                                    <i class="material-icons">update</i>
                                    <span>UPDATE LOGIN BACKGROUND</span>
                                </a>
                            </div>
                        </div>
                        <div role="tabpanel" class="tab-pane fade" id="background_2">
                            <div class="align-center">
                                <img src="{!!asset($school->background_2)!!}" width="50%" height="50%" alt="">
                                <h6>Suggested Size: 300px X 135px</h6>
                                <h6>Suggested File Type: JPG</h6>
                            </div>
                            <br>
                            <div class="align-right">
                                <a role="button" class="btn btn-sm bg-light-blue waves-effect" href="#" data-toggle="modal" data-target="#updateBackground2">
                                    <i class="material-icons">update</i>
                                    <span>UPDATE SIDEBAR BACKGROUND</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- #END# Example Tab --}}

    {{-- Update Modal --}}
    <div class="modal fade" id="updateModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">UPDATE SCHOOL INFO</h4>
                </div>
                <div class="modal-body">
                    <div class="row clearfix">
                        <form action="/school/edit" method="POST">
                            @csrf
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">school</i>
                                </span>
                                <div class="form-line">
                                    <input type="text" class="form-control" name="name" placeholder="School Name" value="{!!$school->name!!}" id="name" required autofocus>
                                </div>
                            </div>
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">code</i>
                                </span>
                                <div class="form-line">
                                    <input type="text" class="form-control" name="code" placeholder="School Code" id="code" required>
                                </div>
                            </div>
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">location_on</i>
                                </span>
                                <div class="form-line">
                                    <input type="text" class="form-control" name="address" placeholder="School Address" id="address" required>
                                </div>
                            </div>
                            <input type="hidden" name="id" value="1">
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> UPDATE </button>
                                <br>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- End Update Modal --}}

    {{-- Update Logo Modal --}}
    <div class="modal fade" id="updateLogo" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">UPDATE SCHOOL LOGO</h4>
                </div>
                <div class="modal-body">
                    <div class="row clearfix">
                        <form action="/school/edit/photo" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">collections</i>
                                </span>
                                <div class="form-line">
                                    <input type="file" name="file" id="file" required autofocus>
                                </div>
                            </div>
                            <input type="hidden" name="type" value="1">
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> UPDATE </button>
                                <br>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- End Update Logo Modal --}}

    {{-- Update Login Background Modal --}}
    <div class="modal fade" id="updateBackground1" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">UPDATE LOGIN BACKGROUND</h4>
                </div>
                <div class="modal-body">
                    <div class="row clearfix">
                        <form action="/school/edit/photo" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">collections</i>
                                </span>
                                <div class="form-line">
                                    <input type="file" name="file" id="file" required autofocus>
                                </div>
                            </div>
                            <input type="hidden" name="type" value="2">
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> UPDATE </button>
                                <br>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- End Update Login Background Modal --}}

    {{-- Update SideBar Background Modal --}}
    <div class="modal fade" id="updateBackground2" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">UPDATE SIDEBAR BACKGROUND</h4>
                </div>
                <div class="modal-body">
                    <div class="row clearfix">
                        <form action="/school/edit/photo" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="input-group">
                                <span class="input-group-addon">
                                    <i class="material-icons">collections</i>
                                </span>
                                <div class="form-line">
                                    <input type="file" name="file" id="file" required autofocus>
                                </div>
                            </div>
                            <input type="hidden" name="type" value="3">
                            <div class="align-right">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect"> UPDATE </button>
                                <br>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- End Update Sidebar Background Modal --}}
@endsection

@section('js_files')
    <script>
        $('#updateModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var name = button.data('name')
            var code = button.data('code')
            var address = button.data('address')
            var modal = $(this)

            modal.find('.modal-body #name').val(name);
            modal.find('.modal-body #code').val(code);
            modal.find('.modal-body #address').val(address);
        })
    </script>
@endsection