<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/lib.php';
$opt=getopt('', ['run','max-calls:','subject:']);
$run=isset($opt['run']);$max=max(1,min(10,(int)($opt['max-calls']??2)));
$sub=(string)($opt['subject']??'');
if($sub!==''&&!isset(SUBJECTS[$sub]))exit("Unknown subject\n");
$rows=q("SELECT subject,topic,target_count FROM question_topics ".($sub?"WHERE subject=? ":"")."ORDER BY subject,id",$sub?[$sub]:[])->fetchAll();
$calls=0;$saved=0;
foreach($rows as $r){
 $id=$r['subject'];$topic=$r['topic'];
 $n=(int)q("SELECT COUNT(*) FROM question_bank WHERE subject=? AND topic=? AND status='approved'",[$id,$topic])->fetchColumn();
 if($n>=(int)$r['target_count'])continue;
 echo "$id | ".mb_substr($topic,0,55)." | $n/{$r['target_count']}\n";
 if(!$run)continue;
 if($calls>=$max)break;
 $old=q("SELECT q FROM question_bank WHERE subject=? AND topic=? ORDER BY id DESC LIMIT 80",[$id,$topic])->fetchAll(PDO::FETCH_COLUMN);
 $avoid="\nAvoid questions already used: ".implode("; ",array_map(fn($x)=>mb_substr($x,0,100),$old));
 try{
  $model=(string)cfg('quiz_model','claude-sonnet-5-5');
  $txt=ask_claude($model,quiz_system(),[['role'=>'user','content'=>quiz_prompt($id,$topic,setting('focus'),quiz_context($id).$avoid,20)]],12000,90,'medium');
  $a=strpos($txt,'{');$b=strrpos($txt,'}');$data=($a!==false&&$b!==false)?json_decode(substr($txt,$a,$b-$a+1),true):null;
  $items=[];
  foreach(($data['questions']??[]) as $x){
   if(!is_array($x)||!is_string($x['q']??null)||!is_array($x['options']??null)||!is_int($x['answer']??null))continue;
   $opts=array_values($x['options']);$q=trim($x['q']);
   if(count($opts)<3||count($opts)>4||count(array_filter($opts,'is_string'))!==count($opts)||$x['answer']<0||$x['answer']>=count($opts)||$q==='')continue;
   $duplicate=false;foreach(array_merge($old,array_column($items,'q')) as $prev)if(quiz_same($q,$prev)){$duplicate=true;break;}
   if(!$duplicate)$items[]=['q'=>$q,'options'=>$opts,'answer'=>$x['answer'],'explain'=>(string)($x['explain']??''),'hint'=>(string)($x['hint']??'')];
  }
  $calls++;
  if(!$items)continue;
  $txt=ask_claude($model,quiz_system(),[['role'=>'user','content'=>quiz_verify_prompt($id,$items)]],6000,90,'medium');
  $a=strpos($txt,'{');$b=strrpos($txt,'}');$v=($a!==false&&$b!==false)?json_decode(substr($txt,$a,$b-$a+1),true):null;
  if(!is_array($v['bad']??null)){echo "  verification unavailable; skipped\n";continue;}
  foreach($items as $i=>$x){
   if(in_array($i,array_map('intval',$v['bad']),true))continue;
   q("INSERT IGNORE INTO question_bank(subject,topic,q,options,answer,`explain`,hint,fingerprint,status,created_at) VALUES (?,?,?,?,?,?,?,?,'approved',?)",
    [$id,$topic,$x['q'],json_encode($x['options'],JSON_UNESCAPED_UNICODE),$x['answer'],$x['explain'],$x['hint'],hash('sha256',quiz_norm($x['q'])),time()]);
   $saved+=q("SELECT ROW_COUNT()")->fetchColumn()>0?1:0;
  }
 }catch(Throwable $e){error_log('bank fill: '.$e->getMessage());echo "  batch failed\n";}
}
echo "Calls: $calls; saved: $saved\n";
