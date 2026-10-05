<?php
require __DIR__ . '/../../api/db.php';
echo json_encode(['driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'evaluations'=>$pdo->query('SELECT COUNT(*) FROM evaluations')->fetchColumn(),'timed'=>$pdo->query('SELECT COUNT(*) FROM evaluations WHERE behavior_meta IS NOT NULL')->fetchColumn()]), PHP_EOL;
