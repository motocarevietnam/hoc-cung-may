<?php
// Run in the disposable test copy created by prepare_integration.py.
declare(strict_types=1);
require __DIR__ . '/../app/lib.php';
require __DIR__ . '/../app/conversation_logic.php';
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS {$label}\n"; }
$day = '2026-11-06';
$schedule = ['grid' => ['periods' => ['08:00','09:00','10:00'], 'cells' => ['5' => ['Toán','Toán','Science']]]];
$expected = companion_expected($schedule, $day);
check(count($expected) === 2 && $expected[0]['subject'] === 'toan', 'grid deduplicates actual configured subjects');
check(companion_expected(['timetable' => ['5' => 'Sáng: Toán (2 tiết), Tiếng Việt. Chiều: Science']], $day)[2]['subject'] === 'csci', 'legacy timetable remains supported');
check(companion_expected(['timetable' => []], $day) === [], 'missing timetable does not invent subjects');
$s = companion_initial($day);
[$s,$reply] = companion_reduce($s,['type'=>'recap'],$expected);
check($s['phase']==='SCHOOL_RECAP' && str_contains($reply,'dự kiến') && $s['recaps']===[], 'timetable is not evidence of learning');
[$s,$reply,$e] = companion_reduce($s,['type'=>'text','text'=>'Hôm nay cô dạy tổng và hiệu'],$expected);
check($s['phase']==='KNOWLEDGE_RECALL' && $e['source']==='child_report' && !$e['verified'], 'recall stores child evidence without a grade');
[$s,$reply,$e] = companion_reduce($s,['type'=>'text','text'=>'Em tìm số bé trước'],$expected);
check($s['phase']==='MISSION_OFFER' && $s['active_card']===null, 'recall offers rather than auto starts a test');
foreach (['Mình hơi mệt rồi','Mình muốn nghỉ','đi ngủ thôi'] as $text) {
 [$rest,$reply,$e]=companion_reduce($s,['type'=>'text','text'=>$text],$expected);
 check($rest['phase']==='REST' && $e===null,'rest respected: '.$text);
}
[$direct]=companion_reduce(companion_initial($day),['type'=>'text','text'=>'Làm bài luôn'],$expected);
check($direct['phase']==='MISSION_OFFER' && !$direct['recaps'], 'direct learning requires no recap');
check(companion_intent('Mình không mệt, làm bài luôn')==='direct','negated fatigue does not hide a direct learning request');
[$free]=companion_reduce($s,['type'=>'text','text'=>'Mình không muốn học, chỉ muốn tâm sự'],$expected);
check($free['phase']==='FREE_CHAT','declining learning returns to listening without another task offer');
[$story,$reply,$e]=companion_reduce(companion_initial($day),['type'=>'text','text'=>'Hôm nay cô khen mình môn Toán'],$expected);
check($story['phase']==='SCHOOL_RECAP' && $story['selected']['subject']==='toan' && $e===null,'natural school story does not invent a lesson');
[$reported,$reply,$e]=companion_reduce(companion_initial($day),['type'=>'text','text'=>'Hôm nay cô dạy Toán bài tổng và hiệu'],$expected);
check($reported['phase']==='KNOWLEDGE_RECALL' && $e['subject']==='toan','natural lesson report enters recall without a required chip');
[$changed,$reply,$e]=companion_reduce($s,['type'=>'text','text'=>'Hôm nay đổi tiết, nghỉ học môn này'],$expected);
check($changed['selected']===null && $e===null && $changed['schedule_changes'][0]['source']==='child_report', 'schedule change clears the assumption');
[$next]=companion_reduce($s,['type'=>'recap'],$expected);
check($next['selected']['subject']==='csci','reopening recap does not ask the same scheduled subject again');
$s['active_card']=['view'=>'quiz','subject'=>'toan','ref'=>42];
$next=companion_day($s,'2026-11-07');
check($next['recaps']===[] && $next['asked']===[] && $next['active_card']['ref']===42,'new day resets recap while preserving unfinished work');
$prompt=companion_system($s,$expected,'Kem');
check(str_contains($prompt,'Không chấm điểm hội thoại') && str_contains($prompt,'Không chào lại'),'companion prompt protects recall and continuity');
echo "Conversation policy checks passed.\n";
