@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
    <!-- Animation Css -->
    <link href="{{asset('/plugins/animate-css/animate.css')}}" rel="stylesheet" />
    <!-- Multi Select Css -->
    <link href="{{asset('/plugins/multi-select/css/multi-select.css')}}" rel="stylesheet">
    <!-- Bootstrap DatePicker Css -->
    <link href="{{asset('/plugins/bootstrap-datepicker/css/bootstrap-datepicker.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
        <div class="card">
            <div class="body">
                <div class="header"><h4>UPDATE RECORD</h4></div>
                <div class="row clearfix">
                    <br>
                    <form action="{{ action('RecordsController@update', $record->id) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        @method('put')
                        <div class="col-sm-12">
                            <h4 class="card-inside-title">Code: </h4>
                            <div class="form-group">
                                <div class="form-line">
                                    <select id="code" class="form-control show-tick" name="category_id" title="Select Record Code:">
                                        @if(count($categories)>0)
                                            @foreach($categories as $category)
                                                @include('inc.category_select', $category)
                                            @endforeach
                                        @endif
                                    </select>
                                </div>
                            </div>
                        </div>                 
                        <div class="col-sm-6" style="display:block" id="originating_office">
                            <h4 class="card-inside-title">Originating Office: </h4>
                            <div class="form-group">
                                <div class="form-line">
                                    <select id="offices" class="form-control show-tick" name="office_id" title="Select Office:">
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-12">
                            <h4 class="card-inside-title">CF to Office(s):</h4>
                            <div class="form-group">
                                <div class="form-line">
                                    <select id="optgroup" class="ms" multiple="multiple" name="office_ids[]">
                                        @if(count($offices)>0)
                                            @foreach($offices as $office)
                                                @if(Auth::user()->office != $office->id)   
                                                    @if (in_array($office->id, $office_ids))
                                                        <option value="{{$office->id}}" selected>{!!$office->description!!}</option>
                                                    @else
                                                        <option value="{{$office->id}}">{!!$office->description!!}</option>
                                                    @endif
                                                @endif
                                            @endforeach
                                        @endif
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-12">
                            <h4 class="card-inside-title">Description: </h4>
                            <div class="form-group">
                                <div class="form-line">
                                    <textarea rows="4" class="form-control no-resize disabled" name="description" id="description">{!!$record->description!!}</textarea>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <h4 class="card-inside-title">Start Date: </h4>
                            <div class="form-group">
                                <div id="bs_datepicker_container">
                                    @php
                                        $date = explode('-', $record->start_date);
                                    @endphp
                                    <input type="text" class="form-control" placeholder="Please choose a date..." name="start_date" value="{{$date[1]."/".$date[2]."/".$date[0]}}" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <h4 class="card-inside-title">Upload File:</h4>
                            <div>
                                <input type="file" name="file" id="file" value="{!!$record->upload_location!!}">
                                <h6>(Set BLANK if you don't intend to replace existing file)</h6>
                            </div>
                        </div>
                        <div class="col-sm-12 align-right">
                            <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                                <i class="material-icons">update</i>
                                <span>UPDATE RECORD</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
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
    <!-- Autosize Plugin Js -->
    <script src="{{asset('/plugins/autosize/autosize.js')}}"></script>
    <!-- Moment Plugin Js -->
    <script src="{{asset('/plugins/momentjs/moment.js')}}"></script>
    <!-- Bootstrap Material Datetime Picker Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-material-datetimepicker/js/bootstrap-material-datetimepicker.js')}}"></script>
    <!-- Bootstrap Datepicker Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-datepicker/js/bootstrap-datepicker.js')}}"></script>

    <script src="{{asset('/js/pages/forms/basic-form-elements.js')}}"></script>
    <script src="{{asset('/js/pages/forms/advanced-form-elements.js')}}"></script>
    
    <script>
        function onloadFunction(){
            $('#code').selectpicker('val', {!!$record->category->id!!});
            $('#code').selectpicker('refresh');
            
            //display the originating_office div depending of the type of record category
            var originating_office = document.getElementById('originating_office')
            if({!!$record->category->type!!} != 3){
                originating_office.style.display = "block"
                
                //display the office
                var category_id = $('#code').val();
                $.get('/get-office-per-category/'+category_id, function(data){
                    $.each(data, function (i, item) {
                        var option = document.createElement("option");
                        option.text = item.description;
                        option.value = item.id;

                        var select = document.getElementById("offices");
                        select.appendChild(option);
                    });
                    $('#offices').selectpicker('val', {!!$record->office_id!!});
                    $('#offices').selectpicker('refresh');
                })
            }else{
                originating_office.style.display = "none"
            }

            //document.getElementById("start_date").value = {!!$record->start_date!!}; 
        }
        //display originating office base on type
        $('#code').on('change', function(e){
            var category_id = e.target.value;
            
            $.get('/get-category/'+category_id, function(data){
                var display_div = data['type'] == 3 ? false:true;
                        
                //show or hide originating_office div
                var originating_office = document.getElementById('originating_office')
                if(display_div){
                    originating_office.style.display = "block"
                }else{
                    originating_office.style.display = "none"
                }
            })
        });
        
        //display offices base on record category
        $('#code').on('change', function(e){
            //remove select contents
            $('#offices option').each(function(index, option){
                $(option).remove();
            });
            $('#offices').selectpicker('refresh');

            var category_id = e.target.value;
            $.get('/get-office-per-category/'+category_id, function(data){
                $.each(data, function (i, item) {
                    var option = document.createElement("option");
                    option.text = item.description;
                    option.value = item.id;

                    var select = document.getElementById("offices");
                    select.appendChild(option);
                });
                $('#offices').selectpicker('refresh');
            })
        })
    </script>
@endsection