@extends('layouts.app')

@section('css_files')
    <!-- Bootstrap Select Css -->
    <link href="{{asset('/plugins/bootstrap-select/css/bootstrap-select.css')}}" rel="stylesheet" />
@endsection

@section('content')
    <div class="container-fluid">
        <div class="col-xs-12 col-sm-12 col-md-8 col-lg-8">
            <div class="row clearfix">
                <div class="card">
                    <div class="header"><h2>ADD NEW TRANSACTION FLOW<h2></div>
                    <div class="body">
                        <form action="{{action('FlowsController@store')}}" method="POST">
                            @csrf
                            <div class="row clearfix">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <div class="form-line">
                                            <input type="text" class="form-control" name="description" placeholder="Transaction Description" required>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <table id="mainTable" class="table table-stripped">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Office</th>
                                                <th>
                                                    <button id="addRow" type="button" class="btn btn-default waves-effect">
                                                        <i class="material-icons">add</i>
                                                    </button>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>1</td>
                                                <td><input type="text" name="office_ids[]" class="form-control" value="{!!Auth::user()->assigned_to['description']!!}" disabled></td>
                                                <td></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="col-sm-12 align-right">
                                    <button type="submit" class="btn btn-sm bg-light-blue waves-effect">
                                        <i class="material-icons">save</i>
                                        <span>CREATE TRANSACTION FLOW</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js_files')
    <!-- Select Plugin Js -->
    <script src="{{asset('/plugins/bootstrap-select/js/bootstrap-select.js')}}"></script>
    
    <script>
        var count = 1;
        $('#addRow').click(function(){
            count++
            var html = '<tr>'+
            '<td>'+count+'</td>'+
            '<td><select id="optgroup" class="form-control show-tick" name="office_ids[]">'+
                '<option value="" disabled selected> Select Office</option>'+
                '@foreach($offices as $office)'+
                    '<option value="{{$office->id}}">{!!$office->description!!}</option>'+
                '@endforeach'+
            '</select></td>'+
            '<td><a href="#" class="remove"><i class="material-icons">clear</i></a></td>'+
            '</tr>';
            $('tbody').append(html);
        })
        $(document).on('click', '.remove', function() {
            $(this).parents('tr').remove();
        });
        /*
        $('#getValues').click(function(){
            var values = [];
            $('input[name="name"]').each(function(i, elem){
                values.push($(elem).val());
            });
            alert(values.join(', '));
        });
        */
    </script>
@endsection