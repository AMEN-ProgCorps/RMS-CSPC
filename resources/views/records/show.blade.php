@extends('layouts.app')

@section('css_files')
@endsection

@section('content')
    <!-- Tabs With Icon Title -->
    <div class="row clearfix">
        <div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
            <div class="card">
                <div class="header">
                    <h2>
                        Record Details
                    </h2>
                </div>
                <div class="body">
                    <!-- Nav tabs -->
                    <ul class="nav nav-tabs" role="tablist">
                        <li role="presentation" class="active">
                            <a href="#home_with_icon_title" data-toggle="tab">
                                <i class="material-icons">info</i> INFORMATION
                            </a>
                        </li>
                        <li role="presentation">
                            <a href="#profile_with_icon_title" data-toggle="tab">
                                <i class="material-icons">history</i> HISTORY
                            </a>
                        </li>
                    </ul>

                    <!-- Tab panes -->
                    <div class="tab-content">
                        <div role="tabpanel" class="tab-pane fade in active" id="home_with_icon_title">
                            <br>
                            <div class="row clearfix">
                                <div class="col-sm-12">
                                    <h5 class="card-inside-title">Code: </h5>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <h5>{!! $record->category->code.') '.$record->category->description!!}</h5>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <h5 class="card-inside-title">Start Date: </h5>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <h5>{!!$record->start_date!!}</h5>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6"> {{-- style="{!! $record->isInternal ? 'display:block' : 'display:none' !!}"> --}}
                                    <h4 class="card-inside-title">Originating Office: </h4>
                                    <div class="form-group">
                                        <div class="form-line">
                                            @php
                                                //if the record comes from external office
                                                if($record->category->type == 3){
                                                    $originating_office = $record->category->description;
                                                }else{
                                                    $originating_office = $record->originating_office->description;
                                                }
                                            @endphp
                                            <h5>{!!$originating_office!!}</h5>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <h5 class="card-inside-title">CF to Office(s):</h5>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <ul class="list-group" id="offices_view">
                                                @if (count($record->offices)>0)
                                                    @foreach($record->offices as $office)
                                                        <li class="list-group-item">{!!$office->description!!}</li>
                                                    @endforeach
                                                @endif
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-12">
                                    <h5 class="card-inside-title">Description: </h5>
                                    <div class="form-group">
                                        <div class="form-line">
                                            <textarea id="description" rows="4" class="form-control no-resize disabled" disabled>{!!$record->description!!}</textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-12 align-right">
                                    @if(Auth::user()->office == 1 || $record->office_id == Auth::user()->office)
                                        <a href="/records/{!!$record->id!!}/edit" class="btn btn-sm bg-light-blue waves-effect" type="button">
                                            <i class="material-icons"/>update</i>
                                            <span>UPDATE RECORD</span>
                                        </a>
                                        <a a href="#" data-toggle="modal" data-target="#deleteModal" data-id="{!!$record->id!!}" data-code="{!!$record->category->code!!}"
                                            data-category="{!!$record->category->description!!}" data-description="{!!$record->description!!}" class="btn btn-sm bg-light-blue waves-effect" type="button">
                                            <i class="material-icons"/>delete</i>
                                            <span>DELETE RECORD</span>
                                        </a>
                                    @endif
                                    <a href="/records/download/{!!$record->id!!}" class="btn btn-sm bg-light-blue waves-effect" type="button">
                                        <i class="material-icons"/>file_download</i>
                                        <span>DOWNLOAD FILE</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div role="tabpanel" class="tab-pane fade" id="profile_with_icon_title">
                            <br>
                            <div class="table-responsive">
                                <table id="transaction_table" class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Status</th>
                                            <th>Office</th>
                                            <th>User</th>
                                            <th>Date</th>
                                            <th>Description</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @if(count($record->history)>0)
                                            @foreach ($record->history as $history)
                                                <tr>
                                                    <td>{!!$history->count!!}</td>
                                                    <td>
                                                        @if ($history->status == 1)
                                                            CREATED
                                                        @else
                                                            UPDATED
                                                        @endif
                                                    </td>
                                                    <td>{!!$history->user_name->assigned_to->Code!!}</td>
                                                    <td>{!!$history->user_name['lname'].", ".$history->user_name['fname']." ".$history->user_name['mname']!!}</td>
                                                    <td>{!!$history->date!!}</td>
                                                    <td>{!!$history->description!!}</td>
                                                </tr>
                                            @endforeach
                                        @else
                                            <tr>
                                                <td>NO HISTORY RECORDED</td><td></td><td></td>
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
    <!-- #END# Tabs With Icon Title -->
    @if(Auth::user()->office == 1 || $record->office_id == Auth::user()->office)
        <!--Delete Modal-->
        <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="defaultModalLabel">DO YOU WANT TO DELETE THE FOLLOWING RECORD?</h4>
                    </div>
                    <div class="modal-body">
                        <form action="{{ action('RecordsController@destroy', 'delete') }}" method="POST">
                            @method('delete')
                            @csrf
                            <div class="input-group">
                                <h6 class="card-inside-title">Code: </h6>
                                <h5 id="code"></h5>
                            </div>
                            <div class="input-group">
                                <h6 class="card-inside-title">Category: </h6>
                                <h5 id="category"></h5>
                            </div>
                            <div class="input-group">
                                <h6 class="card-inside-title">Description: </h6>
                                <textarea rows="4" class="form-control no-resize disabled" name="description" id="description" disabled></textarea>
                            </div>
                            <input type="hidden" id="id" name="id">
                            <div class="align-right button-demo">
                                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                                <button type="submit" class="btn btn-link waves-effect">DELETE RECORD</button>
                                <br>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <!--End Delete Modal-->
    @endif
@endsection

@section('js_files')
    <script>
        $('#deleteModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var code = button.data('code')
            var category = button.data('category')
            var description = button.data('description')
            var id = button.data('id')
            var modal = $(this)

            modal.find('.modal-body #code').text(code);
            modal.find('.modal-body #category').text(category);
            modal.find('.modal-body #description').text(description);
            modal.find('.modal-body #id').val(id)
        })
    </script>
@endsection