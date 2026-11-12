<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/lib.php';
// Dry run by default. Run bounded audits with --run --limit=5.
$opt=getopt('', ['run','limit:','subject:']);
$run=isset($opt['run']);$limit=max(1,min(20,(int)($opt['limit']??5)));
$subject=(string)($opt['subject']??'');
if($subject!==''&&!isset(SUBJECTS[$subject]))exit("Invalid subject\n");
$pdo=db();
$pdo->exec("CREATE TABLE IF NOT EXISTS question_qa_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, question_id BIGINT UNSIGNED NOT NULL,
 reviewer VARCHAR(20) NOT NULL, verdict VARCHAR(24) NOT NULL, predicted_answer INT NULL,
 reason TEXT NOT NULL, model VARCHAR(80) NOT NULL, reviewed_at INT UNSIGNED NOT NULL,
 KEY idx_qa_question(question_id,reviewer,reviewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$rows=q("SELECT b.* FROM question_bank b WHERE b.status IN ('approved','pending')
 AND NOT EXISTS(SELECT 1 FROM question_qa_reviews v WHERE v.question_id=b.id)
 ".($subject!==''?" AND b.subject=? ":"")." ORDER BY b.id ASC LIMIT $limit",$subject!==''?[$subject]:[])->fetchAll();
function qa_parse(string $txt): ?array {
 $a=strpos($txt,'{');$b=strrpos($txt,'}');
 if($a===false||$b===false)return null;
 $d=json_decode(substr($txt,$a,$b-$a+1),true);
 if(!is_array($d)||!in_array($d['verdict']??null,['pass','fail','uncertain'],true))return null;
 if(!is_int($d['answer']??null))return null;
 return $d;
}
function qa_prompt(array $r): string {
 return "You are an independent primary-grade question examiner. Solve this question FROM SCRATCH, without trusting the provided marked answer. Verify unambiguous wording, one correct option, age appropriateness and mathematical/linguistic accuracy. Return ONLY JSON {\"verdict\":\"pass|fail|uncertain\",\"answer\":0,\"reason\":\"brief explanation\"}. Answer is zero-based. A pass means the question itself is valid; do not compare with the marked answer, which is deliberately withheld. Subject: ".SUBJECTS[$r['subject']]['name'].". Topic: ".$r['topic'].". Question: ".$r['q']." Options: ".$r['options'];
}
function qa_gemini(string $prompt): ?array {
 $key=(string)cfg('gemini_api_key','');if(!$key)return null;
 $model=(string)cfg('gemini_qa_model','gemini-2.5-flash');
 $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
 $body=['contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],'generationConfig'=>['temperature'=>0,'responseMimeType'=>'application/json']];
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE)]);
 $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($code!==200||!is_string($raw))return null;
 $data=json_decode($raw,true);
 $txt=$data['candidates'][0]['content']['parts'][0]['text']??'';
 return is_string($txt)?qa_parse($txt):null;
}
foreach($rows as $r){
 echo "Question #".$r['id']." ".mb_substr($r['q'],0,65)."\n";
 if(!$run)continue;
 $prompt=qa_prompt($r);$results=[];
 foreach(['gemini','claude'] as $reviewer){
  try {
   $v=$reviewer==='gemini'?qa_gemini($prompt):qa_parse(ask_claude((string)cfg('quiz_model','claude-sonnet-5-5'),'Independently solve and validate questions; JSON only.',[['role'=>'user','content'=>$prompt]],2000,60,'low'));
  }catch(Throwable $e){$v=null;error_log('question QA '.$reviewer.': '.$e->getMessage());}
  $verdict=$v['verdict']??'unavailable';$answer=$v['answer']??null;$reason=mb_substr((string)($v['reason']??'API unavailable or invalid response'),0,1000);
  q("INSERT INTO question_qa_reviews(question_id,reviewer,verdict,predicted_answer,reason,model,reviewed_at) VALUES (?,?,?,?,?,?,?)",[$r['id'],$reviewer,$verdict,$answer,$reason,$reviewer==='gemini'?(string)cfg('gemini_qa_model','gemini-2.5-flash'):(string)cfg('quiz_model','claude-sonnet-5-5'),time()]);
  $results[$reviewer]=['verdict'=>$verdict,'answer'=>$answer];
 }
 $pass=$results['gemini']['verdict']==='pass' && $results['claude']['verdict']==='pass' &&
       $results['gemini']['answer']===(int)$r['answer'] && $results['claude']['answer']===(int)$r['answer'];
 q("UPDATE question_bank SET status=? WHERE id=?",[$pass?'approved':'needs_review',$r['id']]);
 echo $pass?"  dual QA PASS\n":"  needs human review\n";
}
echo "Processed ".count($rows)." questions".($run?'':' (dry run)')."\n";
