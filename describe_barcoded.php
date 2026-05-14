<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=rms','root','root');
$stmt = $pdo->query('DESCRIBE barcoded_documents');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) echo $r['Field']."\n";