<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/lib.php';
$dir=$argv[1]??'';
if(!is_dir($dir)||!is_file("$dir/questions_blind.json"))exit("Usage: php reconcile_cli_qa.php <pilot-dir>\n");
function parse_review(string $path): ?array {
 if(!is_file($path)||filesize($path)<5)return null;
 $s=trim(file_get_contents($path));$s=preg_replace('/^\x60\x60\x60(?:json)?\s*|\s*\x60\x60\x60$/u','',$s);
 $v=json_decode($s,true);return is_array($v)&&array_is_list($v)?$v:null;
}
$questions=json_decode(file_get_contents("$dir/questions_blind.json"),true);
if(!is_array($questions)||!$questions)exit("Invalid blind batch\n");
$expected=array_column($questions,'id');sort($expected);
$reviews=[];
foreach(['claude'] as $name){
 $rows=parse_review("$dir/{$name}_review.json");
 if($rows===null){echo "$name: no valid review JSON; fail closed\n";exit(3);}
 $byId=[];
 foreach($rows as $r){
  $id=$r['question_id']??null;
  if(!is_int($id)||isset($byId[$id])||!in_array($r['verdict']??null,['pass','fail','uncertain'],true)||!is_int($r['predicted_answer']??null)||!is_string($r['reason']??null))exit("Invalid $name result\n");
  $byId[$id]=$r;
 }
 $actual=array_keys($byId);sort($actual);
 if($actual!==$expected)exit("Incomplete $name results\n");
 $reviews[$name]=$byId;
}
$pdo=db();$pdo->beginTransaction();
try{
 $approved=0;$held=0;
 foreach($expected as $id){
  $r=q('SELECT * FROM question_bank WHERE id=? FOR UPDATE',[$id])->fetch();
  if(!$r||$r['status']!=='pending')throw new RuntimeException("Question $id not pending");
  $blind=null;foreach($questions as $x)if((int)$x['id']===$id){$blind=$x;break;}
  if(!$blind||$blind['q']!==$r['q']||json_encode($blind['options'],JSON_UNESCAPED_UNICODE)!==json_encode(json_decode($r['options'],true),JSON_UNESCAPED_UNICODE))throw new RuntimeException("Question changed: $id");
  foreach($reviews as $name=>$results){
   $v=$results[$id];
   q('INSERT INTO question_qa_reviews(question_id,reviewer,verdict,predicted_answer,reason,model,reviewed_at) VALUES (?,?,?,?,?,?,?)',[$id,$name,$v['verdict'],$v['predicted_answer'],mb_substr($v['reason'],0,1000),$name.'-cli',time()]);
  }
  $c=$reviews['claude'][$id];
  $pass=$c['verdict']==='pass'&&$c['predicted_answer']===(int)$r['answer'];
  q('UPDATE question_bank SET status=?, approved_by=? WHERE id=?',[$pass?'approved':'needs_review',$pass?'claude_qa':null,$id]);
  $pass?$approved++:$held++;
 }
 $pdo->commit();echo "approved=$approved needs_review=$held\n";
}catch(Throwable $e){$pdo->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(2);}
