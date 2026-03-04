<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Disposition List</title>

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
    <table class="header">
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
    <div class="align-center">
        <h3>INVENTORY OF RECORDS FOR DISPOSAL</h3>
        <p>Office: {!!$office->description!!}</p>
    </div>
    <br>
    <table class="result-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Category</th>
                <th>Description</th>
                <th>Year</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @if(count($records)>0)
                @foreach ($records as $index => $record)
                    <tr>
                        <td class="align-center">{!!$index+1!!}</td>
                        <td>{!!$record->category->code!!}) {!!$record->category->description!!}</td>
                        <td>                    
                            <textarea style="height:{{ substr_count( $record->description, "\n" )+1 }}em;">{{$record->description}}
                            </textarea>
                        </td>
                        <td class="align-center">{!!date('Y', strtotime($record->start_date))!!}</td>
                        <td></td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td>NO RECORDS</td><td></td><td></td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>