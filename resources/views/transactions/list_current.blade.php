{{-- List Current Transactions for the Office --}}
@extends('layouts.app')

@section('css_files')
@endsection

@section('content')
    @if(count($status) > 0)
        @foreach ($status as $s)
            <div class="col-lg-6 col-md-6 col-sm-12 col-xs-12">
                <div class="card">
                    <div class="body">
                        <div class="row clearfix">
                            <div class="col-sm-6">
                                <h2 class="card-inside-title">Date Created: </h2>
                                <div class="form-group">
                                    <div class="form-line disabled">
                                        <h2 type="text" class="form-control">{!!$s->transaction->Date_created!!}</h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <h2 class="card-inside-title">Created By: </h2>
                                <div class="form-group">
                                    <div class="form-line disabled">
                                        <h2 type="text" class="form-control">{!!$s->transaction->office->Code!!}</h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <h2 class="card-inside-title">Received From: </h2>
                                <div class="form-group">
                                    <div class="form-line disabled">
                                        <h2 type="text" class="form-control">{!!$s->originating_office_details->Code!!}</h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <h2 class="card-inside-title">Received Date: </h2>
                                <div class="form-group">
                                    <div class="form-line disabled">
                                        <h2 type="text" class="form-control">{!!$s->date_in!!}</h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-12">
                                <h2 class="card-inside-title">Particulars: </h2>
                                <div class="form-group">
                                    <textarea rows="4" class="form-control no-resize disabled" readonly>{!!$s->transaction->description!!}</textarea>
                                </div>
                            </div>
                            <div class="col-sm-12">
                                <h2 class="card-inside-title">Notes From {!!$s->originating_office_details->Code!!}: </h2>
                                <div class="form-group">
                                    <textarea rows="4" class="form-control no-resize disabled" readonly>{!!$s->notes!!}</textarea>
                                </div>
                            </div>
                            <br>
                            <div class="align-right">
                                <a href="/show_transaction/{!!$s->transaction->id!!}" class="btn btm-sm bg-blue waves-effect" role="button">
                                    <i class="material-icons">info</i>
                                    <span>VIEW TRANSACTION</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    @else
        <h2>No Current Transaction</h2>
    @endif
    
    <!--Add Action Modal for free flow only-->
    <div class="modal fade" id="addActionModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    {{-- <h4 class="modal-title" id="defaultModalLabel">ADD ACTION</h4> --}}
                </div>
                <div class="modal-body">
                    <form action="/add_action" method="POST">
                        @csrf
                        <div class="row clearfix">
                            <div class="col-md-12">
                                <h4 class="card-inside-title">Action: </h4>
                                <div class="form-group">
                                    <div class="form-line"><textarea rows="4" class="form-control no-resize" id="action" name="action" required></textarea></div>
                                </div>
                            </div>
                            <input type="hidden" name="barcode" id="barcode">
                            <input type="hidden" name="flow" id="flow">
                        </div>
                        <div class="align-right">
                            <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">CLOSE</button>
                            <button type="submit" class="btn btn-link waves-effect">ADD ACTION</button>
                            <br>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                </div>
            </div>
        </div>
    </div>
    <!--End Modal--> 
@endsection

@section('js_files')
    <script>
        $('#addActionModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget)
            var barcode = button.data('barcode')
            var flow = button.data('flow')
            var modal = $(this)

            modal.find('.modal-body #barcode').val(barcode);
            modal.find('.modal-body #flow').val(flow);
        })
    </script>
    <script>
        //refresh every 1 minute
        setTimeout(function(){
            location = ''
        },60000)
    </script>
@endsection