<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Barcode</title>
    <style>
        .align-right{
            text-align: right
        }

        div{
            margin: 0;
            padding: 0;
            border: 0;
        }
 
        p {
            text-align: center;
            margin: 0;
            padding: 0pt;
            font-size: 11px;
            font-family: 'Times New Roman', Times, serif
        }
     </style>

     @if($orientation == 'landscape')
        <style>
            html{
            margin:40px 100px
        }
        </style>
     @else
        <style>
            html{
                margin:40px 60px
            }
        </style>
     @endif

</head>

<body>
    <div class="align-right">
        @php
            //change false to true for the value to be displayed
            //echo '<img src="data:image/png;base64,' . DNS1D::getBarcodePNG($transaction->Barcode, "C128A",1.75,25,array(1,1,1), true) . '" alt="barcode"   />';
            // echo '<img src="data:image/png;base64,' . DNS1D::getBarcodePNG($transaction->Barcode, "C128A",1.5,13,array(1,1,1), false) . '" alt="barcode"   />';
            echo '<img src="data:image/png;base64,' . DNS1D::getBarcodePNG($transaction->Barcode, 'C39',1.3,15) . '" alt="barcode"   />';
        @endphp
    </div>
    @if ($orientation == 'landscape')
        <p><span style="display:inline-block; width: 700px;"></span>{{$transaction->Barcode}}</p>
    @else
        <p><span style="display:inline-block; width: 430px;"></span>{{$transaction->Barcode}}</p>
    @endif
    
</body>
</html>