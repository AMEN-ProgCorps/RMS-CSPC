<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=rms','root','root');
$sql = "select * from `status` where `office_id` is null and `date_in` is not null and `date_out` is null and `status` is null and `date_in` between '2025-12-05 00:00:00' and '2026-02-05 11:40:01' and exists (select * from `barcoded_documents` where `status`.`barcode_value` = `barcoded_documents`.`Barcode` and `completed` = 0) order by `date_in` desc";
try{
    $stmt = $pdo->query($sql);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) print_r($r);
    echo "OK\n";
}catch(PDOException $e){
    echo $e->getMessage(),"\n";
}
