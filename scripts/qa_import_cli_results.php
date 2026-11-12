<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/lib.php';
// Import two independent, blinded CLI JSON reviews. Both files are required.
// Usage: php scripts/qa_import_cli_results.php /path/claude.json /path/gemini.json
if($argc!==3){fwrite(STDERR,"Usage: php scripts/qa_import_cli_results.php claude.json gemini.json\n");exit(2);}
function read_review(string $path): array {
 if(!is_file($path))throw new RuntimeException('Review file missing');
 $raw=trim(file_get_contents($path));
 $raw=preg_replace('/^\x60\x60\x60(?:json)?\s*/i','',$raw);
 $raw=preg_replace('/\s*\x60\x60\x60$/','',$raw);
 $items=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
 if(!is_array($items)||!array_is_list($items))throw new RuntimeException('Invalid review array');
 $map=[];
 foreach($items as $x){
  if(!is_array($x)||!is_int($x['question_id']??null)||!in_array($x['verdict']??null,['pass','fail','uncertain'],true)||!is_int($x['predicted_answer']??null)||$x['predicted_answer']<0||$x['predicted_answer']>3||isset($map[$x['question_id']]))throw new RuntimeException('Invalid or duplicate review');
  $map[$x['question_id']]=$x;
 }
 return $map;
}
try{
 $claude=read_review($argv[1]);$gemini=read_review($argv[2]);
 if(!$claude||array_keys($claude)!==array_keys($gemini))throw new RuntimeException('Question sets differ');
 $pdo=db();$pdo->beginTransaction();$approved=0;$held=0;
 foreach($claude as $id=>$c){
  $g=$gemini[$id];
  $row=q('SELECT id,status,answer,options FROM question_bank WHERE id=? FOR UPDATE',[$id])->fetch();
  if(!$row||$row['status']!=='pending')throw new RuntimeException("Question $id is not pending");
  $opts=json_decode($row['options'],true);
  if(!is_array($opts)||!isset($opts[(int)$row['answer']]))throw new RuntimeException('Invalid options');
  foreach(['claude'=>$c,'gemini'=>$g] as $reviewer=>$v){
   q("INSERT INTO question_qa_reviews(question_id,reviewer,verdict,predicted_answer,reason,model,reviewed_at) VALUES (?,?,?,?,?,?,?)",
   [$id,$reviewer,$v['verdict'],$v['predicted_answer'],mb_substr((string)($v['reason']??''),0,1000),'cli',time()]);
  }
  $pass=$c['verdict']==='pass'&&$g['verdict']==='pass'&&$c['predicted_answer']===(int)$row['answer']&&$g['predicted_answer']===(int)$row['answer'];
  q('UPDATE question_bank SET status=? WHERE id=?',[$pass?'approved':'needs_review',$id]);
  $pass?$approved++:$held++;
 }
 $pdo->commit();echo "Approved $approved; needs_review $held\n";
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1);}
