@extends('layouts.app')

@section('css_files')
    <!-- Multi Select Css -->
    <link href="{{asset('/plugins/multi-select/css/multi-select.css')}}" rel="stylesheet">
    <!-- Bootstrap Select Css -->
    <link href="{{asset('plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="row clearfix">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
            <div class="card">
                <div class="header">
                    <h2>
                        FUNCTIONAL CATEGORIES OF RECORDS
                    </h2>
                </div>
                <div class="body">
                    <div class="align-right button-demo">
                        <button type="button" class="btn btn-sm bg-light-blue waves-effect" data-toggle="modal" data-target="#addModal" 
                            data-parent_offices= ''>
                            <i class="material-icons">add</i>
                            <span>ADD NEW RECORD CATEGORY / OFFICE</span>
                        </button>
                    </div>
                    <br>
                    @if(count($categories)>0)
                        <ul>
                            @foreach ($categories as $category)
                                @include('inc.category',$category)
                            @endforeach
                        </ul>
                        <div class="align-right">
                            {{$categories->links()}}
                        </div>
                    @else
                        <h3>NO RECORD CATEGORY</h3>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <!--Add Modal-->
    <div class="modal fade" id="addModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">ADD NEW RECORD CATEGORY / OFFICE</h4>
                </div>
                <div class="modal-body">
                    <form action="{{action('CategoryController@store')}}" method="POST">
                        @csrf
                        <div class="row clearfix">
                            <div class="col-md-12">
                                <h5 class="card-inside-title">Select Office(s):</h5>
                                <select id="optgroup" class="ms" multiple="multiple" name="office_ids[]">
                                    @foreach($offices as $office)
                                        <option value="{!!$office->id!!}">{!!$office->description!!}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <h5 class="card-inside-title">Code:</h5>
                                <div class="form-group">
                                    <div class="form-line">
                                        <input type="text" class="form-control" name="code" placeholder="Record Code" required>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6" id="isInternal_div">
                                <h5 class="card-inside-title">Type: </h5>
                                <div class="form-group">
                                    <select id="type" class="form-control show-tick" name="type" required>
                                        <option value="1">Internal</option>
                                        <option value="2">To External</option>
                                        <option value="3">From External</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <h5 class="card-inside-title">Description:</h5>
                                <div class="form-group">
                                    <div class="form-line">
                                        <textarea rows="2" class="form-control no-resize" placeholder="Record Description" name="description"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-12" id="isPermanent_div">
                                <h5 class="card-inside-title">is Permanent?</h5>
                                <div class="form-group">
                                    <div class="form-line">
                                        <div class="switch">
                                            <label>No<input type="checkbox" name="isPermanent" id="isPermanent" onclick="displayFunction()" checked><span class="lever switch-col-cyan"></span>Yes</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-3" id="active" style="display:none">
                                <h5 class="card-inside-title">Active:</h5>
                                <div class="form-group">
                                    <div class="input-group spinner" data-trigger="spinner">
                                        <div class="form-line">
                                            <input type="text" class="form-control text-center" value="0" data-rule="currency" id="years_active" name="years_active" data-min="0.0" data-precision="1" data-step="0.1">
                                        </div>
                                        <span class="input-group-addon">
                                            <a href="javascript:;" class="spin-up" data-spin="up"><i class="glyphicon glyphicon-chevron-up"></i></a>
                                            <a href="javascript:;" class="spin-down" data-spin="down"><i class="glyphicon glyphicon-chevron-down"></i></a>
                                        </span>
                                    </div>
                                </div>
                            </div> 
                            <div class="col-sm-3" id="storage" style="display:none">
                                <h5 class="card-inside-title">Storage:</h5>
                                <div class="form-group">
                                    <div class="input-group spinner" data-trigger="spinner">
                                        <div class="form-line">
                                            <input type="text" class="form-control text-center" value="0" data-rule="currency" id="years_storage" name="years_storage" data-min="0.0" data-precision="1" data-step="0.1">
                                        </div>
                                        <span class="input-group-addon">
                                            <a href="javascript:;" class="spin-up" data-spin="up"><i class="glyphicon glyphicon-chevron-up"></i></a>
                                            <a href="javascript:;" class="spin-down" data-spin="down"><i class="glyphicon glyphicon-chevron-down"></i></a>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" class="form-control" name="parent_id" id="parent_id">
                        <div class="align-right button-demo">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-blue">CREATE</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!--End Add Modal-->
    <!--Delete Modal-->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="defaultModalLabel">DO YOU WANT TO DELETE THE FOLLOWING RECORD CATEGORY?</h4>
                </div>
                <div class="modal-body">
                    <form action="{{ action('CategoryController@destroy', 'delete') }}" method="POST">
                        @method('delete')
                        @csrf
                        <div class="row clearfix">
                            <div class="col-md-6">
                                <h5 class="card-inside-title">Code</h5>
                                <h4 id="code"></h4>
                            </div>
                            <div class="col-md-12">
                                <h5 class="card-inside-title">Description</h5>
                                <h4 id="description"></h4>
                            </div>
                        </div>
                        <input type="hidden" id="id" name="id">
                        <br><br>
                        <div class="align-right">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">DELETE RECORD CATEGORY</button>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    
                </div>
            </div>
        </div>
    </div>
    <!--End Delete Modal-->
@endsection

@section('js_files')
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
    <!-- Jquery Spinner Plugin Js -->
    <script src="{{asset('/plugins/jquery-spinner/js/jquery.spinner.js')}}"></script>
    
    <script src="{{asset('/js/pages/forms/advanced-form-elements.js')}}"></script>
    <script src="{{asset('/js/pages/ui/tooltips-popovers.js')}}"></script>

    <script>
        $('#addModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var parent_id = button.data('parent_id')
            var parent_offices = button.data('parent_offices')
            var parent_offices_id=[];            
            for (index = 0; index < parent_offices.length; index++) {
                parent_offices_id.push(parent_offices[index]['id'].toString());
            }
            var modal = $(this)
            modal.find('.modal-body #optgroup').multiSelect('deselect_all')
            modal.find('.modal-body #parent_id').val(parent_id);
            modal.find('.modal-body #optgroup').multiSelect('select', parent_offices_id)

            //display isPermanent and isInternal base on parent_id
            var isPermanent_div = document.getElementById("isPermanent_div");
            var isInternal_div = document.getElementById("isInternal_div");
            if (!parent_id){
                isPermanent_div.style.display = 'none';
                isInternal_div.style.display = 'none';
            } else {
                isPermanent_div.style.display = 'block';
                isInternal_div.style.display = 'block';
            }
        })
        $('#deleteModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var id = button.data('id')
            var code = button.data('code')
            var description = button.data('description')

            var modal = $(this)
            modal.find('.modal-body #id').val(id);
            modal.find('.modal-body #code').text(code);
            modal.find('.modal-body #description').text(description);
        })
        function displayFunction() {
            // Get the checkbox
            var checkBox = document.getElementById("isPermanent");
            // Get the div
            var active = document.getElementById("active");
            var storage = document.getElementById("storage");

            // If the checkbox is checked, display the div
            if (checkBox.checked == false){
                active.style.display = "block";
                storage.style.display = "block";
            } else {
                active.style.display = "none";
                storage.style.display = "none";
            }
        } 
    </script>
@endsection