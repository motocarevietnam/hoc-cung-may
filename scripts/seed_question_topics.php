<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/lib.php';
$map=['Toán'=>'toan','Tiếng Việt'=>'tv','Math'=>'cmath','English ET'=>'ceng','English LT'=>'ceng','Science'=>'csci'];
foreach($map as $school=>$subject){
 foreach(q('SELECT DISTINCT planned_content FROM school_lesson_plan WHERE subject=?',[$school])->fetchAll() as $row){
  $topic=mb_substr(trim(preg_replace('/\\s+/u',' ',(string)$row['planned_content'])),0,255);
  if($topic!=='')q("INSERT IGNORE INTO question_topics(subject,topic,source,target_count,created_at) VALUES (?,?,'school_plan',100,?)",[$subject,$topic,time()]);
 }
}
$topic='Bài 13. Làm tròn số đến hàng trăm nghìn';
for($i=0;$i<100;$i++){
 $number=100000+(($i*7919+11317)%800000);
 $correct=intdiv($number+50000,100000)*100000;
 $qtext=match($i%4){
  0=>"Làm tròn số ".number_format($number,0,',',' ')." đến hàng trăm nghìn được số nào?",
  1=>"Một thư viện có ".number_format($number,0,',',' ')." cuốn sách. Làm tròn đến hàng trăm nghìn, thư viện có khoảng bao nhiêu cuốn?",
  2=>"Dân số một thị trấn là ".number_format($number,0,',',' ')." người. Số gần đúng đến hàng trăm nghìn là bao nhiêu?",
  default=>"Số ".number_format($number,0,',',' ')." được làm tròn đến hàng trăm nghìn. Chọn kết quả đúng."
 };
 $opts=array_map(fn($n)=>number_format($n,0,',',' '),[$correct,$correct+100000,max(0,$correct-100000),$correct+200000]);
 $shift=$i%4;
 for($j=0;$j<$shift;$j++)array_unshift($opts,array_pop($opts));
 $fp=hash('sha256',quiz_norm($qtext));
 q("INSERT IGNORE INTO question_bank(subject,topic,q,options,answer,`explain`,hint,fingerprint,status,created_at) VALUES (?,?,?,?,?,?,?,?,'pending',?)",
 ['toan',$topic,$qtext,json_encode($opts,JSON_UNESCAPED_UNICODE),$shift,
 'Xét chữ số hàng chục nghìn: từ 5 trở lên thì tăng hàng trăm nghìn lên 1, nhỏ hơn 5 thì giữ nguyên.',
 'Quan sát chữ số ở hàng chục nghìn.',$fp,time()]);
}
echo "Catalog by subject:\\n";
foreach(q('SELECT subject,COUNT(*) n FROM question_topics GROUP BY subject')->fetchAll() as $row)echo $row['subject'].': '.$row['n']."\\n";
echo "Approved bank by subject:\\n";
foreach(q("SELECT subject,COUNT(*) n FROM question_bank WHERE status='approved' GROUP BY subject")->fetchAll() as $row)echo $row['subject'].': '.$row['n']."\\n";
