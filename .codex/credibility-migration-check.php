<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../api/db.php';
require __DIR__ . '/../api/schema_migrations.php';
$tables=['users','evaluations','evaluation_responses'];
$before=[];
foreach($tables as $table) $before[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
ensureEvaluationBehaviorMetadataSchema($pdo);
ensureEvaluationCredibilitySchema($pdo);
ensureEvaluationCredibilitySchema($pdo);
foreach($tables as $table) {
 $after=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
 if(count($before[$table])!==count($after)) throw new RuntimeException('Row count changed in '.$table);
 foreach($before[$table] as $i=>$row) {
  if($row!==array_intersect_key($after[$i],$row)) throw new RuntimeException('Original fields changed in '.$table);
 }
}
$migration=null;
foreach(getNaapSchemaMigrationRegistry() as $m) if($m['id']==='evaluation_credibility_review_v1') $migration=checkNaapSchemaMigration($pdo,$m);
if(($migration['status']??'')!=='applied') throw new RuntimeException('Migration verification failed.');
$result=['status'=>'PASS','migration'=>'evaluation_credibility_review_v1','repeatSafe'=>true,'originalRowsAndFieldsPreserved'=>array_map('count',$before),'decisions'=>$pdo->query('SELECT credibility_status,COUNT(*) AS total FROM evaluations GROUP BY credibility_status')->fetchAll(),'historicalTimingAbsent'=>$pdo->query('SELECT COUNT(*) FROM evaluations WHERE behavior_meta IS NULL AND behavior_score IS NULL')->fetchColumn()];
file_put_contents(__DIR__.'/credibility-migration-result.json',json_encode($result,JSON_PRETTY_PRINT));
echo json_encode($result,JSON_PRETTY_PRINT),PHP_EOL;
