@extends('layouts.app')

@section('css_files')
@endsection

@section('content')
    <div class="col-lg-12 col-lg-12 col-md-8 col-lg-8">
        <div class="card">
            <div class="body">
                <div class="row clearfix">
                    <div class="col-sm-12">
                        <h2 class="card-inside-title">Transaction Description: </h2>
                        <div class="form-group">
                            <div class="form-line disabled">
                                <h4>{!!$transaction_nature->description!!}</h4>
                            </div>
                        </div>
                    </div>
                    <div>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>OFFICE</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if(count($transaction_nature->transaction_flow)>0)
                                    @foreach ($transaction_nature->transaction_flow as $transaction_flow)
                                        <tr>
                                            <td scope="row">{{$transaction_flow->chrono_order}}</td>
                                            <td>{{$transaction_flow->office->description}}</td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js_files')
@endsection