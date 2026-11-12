<?php
declare(strict_types=1);
// ChatGPT creates a JSON batch in the chat and uploads it over authorized SSH.
// The importer never approves questions: Claude + Gemini QA is required.
require dirname(__DIR__).'/app/lib.php';
$path=$argv[1]??'';
if($path===''||!is_file($path)||!str_starts_with(realpath($path)?:'', '/tmp/')){fwrite(STDERR,"Usage: php import_chatgpt_questions.php /tmp/batch.json\n");exit(2);}
$payload=json_decode(file_get_contents($path),true);
if(!is_array($payload)||!is_array($payload['questions']??null)){fwrite(STDERR,"Invalid batch\n");exit(2);}
$subject=(string)($payload['subject']??'');$topic=trim((string)($payload['topic']??''));
if(!isset(SUBJECTS[$subject])||$topic===''||mb_strlen($topic)>255){fwrite(STDERR,"Invalid subject/topic\n");exit(2);}
if(count($payload['questions'])>100){fwrite(STDERR,"Max 100 questions per batch\n");exit(2);}
$valid=0;$skipped=0;
foreach($payload['questions'] as $x){
 if(!is_array($x)||!is_string($x['q']??null)||!is_array($x['options']??null)||!is_int($x['answer']??null)){$skipped++;continue;}
 $qtext=trim($x['q']);$options=array_values($x['options']);$ans=$x['answer'];
 if($qtext===''||mb_strlen($qtext)>500||count($options)<3||count($options)>4||count(array_filter($options,'is_string'))!==count($options)||$ans<0||$ans>=count($options)){$skipped++;continue;}
 $fp=hash('sha256',quiz_norm($qtext));
 q("INSERT IGNORE INTO question_bank(subject,topic,q,options,answer,`explain`,hint,fingerprint,status,created_at) VALUES (?,?,?,?,?,?,?,?,'pending',?)",
 [$subject,$topic,$qtext,json_encode($options,JSON_UNESCAPED_UNICODE),$ans,mb_substr((string)($x['explain']??''),0,400),mb_substr((string)($x['hint']??''),0,300),$fp,time()]);
 $valid++;
}
echo "Imported candidates: $valid; invalid: $skipped; all newly inserted remain pending dual QA.\n";
