<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Transactions</title>

    <style>
        .result-table {
            font-family: arial, sans-serif;
            font-size: 11px;
            border-collapse: collapse;
            width: 100%;
        }

        .result-table td, 
        .result-table th {
        border: 1px solid #dddddd;
        padding: 5px;
        margin: 0;
        }

        .align-center{
            text-align: center
        }

        .align-left{
            text-align: left
        }

        p, h3 { 
            margin: 1;
            font-size: 14px;
        }

        .line {
            border-color: #84affe;
            background: #84affe;
            height: 1px; 
        }

        textarea {
            border-color:white;
        }
    </style>
</head>
<body>
    <table>
        <tr>
            <th><img src="{!!$school->logo!!}" alt="{!!$school->code!!} LOGO" height="70" width="70"/></th>
            <th class="align-left">
                <p>Republic of the Philippines</p>
                <p>{!!$school->name!!}</p>
                <p>{!!$school->address!!}</p>
            </th>
        </tr>
    </table>
    <hr class="line" color="blue">
    
    <h3>Transactions Created by the {!!Auth::user()->assigned_to['description']!!}</h3>
    <p>From: {!!$from!!}</p>
    <p>To: {!!$to!!}</p>
    <br>
    <table class="result-table">
        <tr>
            <th class="align-center" style="width: 3%">#</th>
            <th class="align-center" style="width: 13%">Date Created</th>
            <th class="align-center">Particulars</th>
            <th class="align-center" style="width: 10%">Current Location</th>
            <th class="align-center" style="width: 10%">Remarks</th>
            <th class="align-center" style="width: 10%">Barcode Value</th>
        </tr>
        @if (count($transactions)>0)
            @foreach ($transactions as $i => $transaction)
                <tr>
                    @php
                        $count = substr_count( $transaction->description, "\n" );
                        if($count > 4){
                            $newline = substr_count( $transaction->description, "\n" ) + 2;
                        }else{
                            $newline = 4;
                        }
                    @endphp
                    <th>{!!$i+1!!}</th>
                    <td>{!!$transaction->Date_created!!}</td>
                    <td>
                        <textarea style="height:{{ $newline }}em;">{{$transaction->description}}
                        </textarea>
                    </td>
                    @if($transaction->freeFlow)
                        <td>N/A</td>
                    @else
                        <td>{!!$transaction->current_location->Code!!}</td>
                    @endif
                    <td>
                        @if ($transaction->completed)
                            Completed
                        @else
                            On-going
                        @endif
                    </td>
                    <td>{!!$transaction->Barcode!!}</td>
                </tr>
            @endforeach 
        @else
            <tr>
                <td>NO TRANSACTIONS</td><td></td><td></td>
            </tr>
        @endif
    </table>
</body>
</html>