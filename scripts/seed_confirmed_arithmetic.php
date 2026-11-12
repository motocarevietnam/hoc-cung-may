<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/lib.php';
// Deterministic arithmetic seed: no AI cost; each answer is computed in PHP.
// Only lessons with an explicit taught confirmation are eligible.
$topics=[
 'Bài 22. Phép cộng các số có nhiều chữ số'=>'add',
 'Bài 23. Phép trừ các số có nhiều chữ số'=>'subtract',
 'Bài 24. Tính chất giao hoán và kết hợp của phép cộng'=>'properties',
];
foreach($topics as $topic=>$kind){
 $taught=(int)q("SELECT COUNT(*) FROM school_lesson_plan p JOIN school_lesson_confirmations c ON c.school_day=p.school_day AND c.period_no=p.period_no WHERE p.subject=? AND p.planned_content=? AND c.status='taught'",['Toán',$topic])->fetchColumn();
 if(!$taught){echo "SKIP not taught $topic\n";continue;}
 for($i=0;$i<100;$i++){
  $a=100000+(($i*7919+1337)%600000);$b=10000+(($i*3571+739)%190000);
  if($kind==='add'){$correct=$a+$b;$qtext="Tính tổng ".number_format($a,0,',',' ')." + ".number_format($b,0,',',' ').".";}
  elseif($kind==='subtract'){$a+=$b;$correct=$a-$b;$qtext="Tính hiệu ".number_format($a,0,',',' ')." − ".number_format($b,0,',',' ').".";}
  else{
   $c=1000+(($i*197+41)%9000);
   $correct=$a+$b+$c;
   $qtext=match($i%3){
    0=>"Tính nhanh: (".number_format($a,0,',',' ')." + ".number_format($b,0,',',' ').") + ".number_format($c,0,',',' ')." = ?",
    1=>"Tính nhanh: ".number_format($a,0,',',' ')." + (".number_format($b,0,',',' ')." + ".number_format($c,0,',',' ').") = ?",
    default=>"Tính bằng cách nhóm số thuận tiện: ".number_format($a,0,',',' ')." + ".number_format($c,0,',',' ')." + ".number_format($b,0,',',' ')." = ?"
   };
  }
  $wrong=[$correct+1000,$correct-1000,$correct+10000];
  $values=[$correct,...$wrong];$shift=$i%4;
  for($j=0;$j<$shift;$j++)array_unshift($values,array_pop($values));
  $opts=array_map(fn($v)=>number_format($v,0,',',' '),$values);
  $fp=hash('sha256',quiz_norm($qtext));
  q("INSERT IGNORE INTO question_bank(subject,topic,q,options,answer,`explain`,hint,fingerprint,status,created_at) VALUES (?,?,?,?,?,?,?,?,'approved',?)",
  ['toan',$topic,$qtext,json_encode($opts,JSON_UNESCAPED_UNICODE),$shift,
   $kind==='properties'?'Dùng tính chất giao hoán và kết hợp để nhóm các số rồi cộng.':($kind==='add'?'Cộng các chữ số theo từng hàng, nhớ khi tổng lớn hơn 9.':'Trừ các chữ số theo từng hàng, mượn khi cần.'),
   'Đặt tính theo hàng đơn vị, chục, trăm, nghìn rồi tính cẩn thận.',$fp,time()]);
 }
 echo "Seeded $topic\n";
}
foreach(q("SELECT topic,COUNT(*) n FROM question_bank WHERE subject='toan' AND topic<>'' GROUP BY topic")->fetchAll() as $x)echo $x['topic'].": ".$x['n']."\n";
