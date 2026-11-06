<?php
// Dedicated disposable test DB only; run after conversation_api.py and before the browser test.
declare(strict_types=1);
require __DIR__ . '/../app/lib.php';
require __DIR__ . '/../app/conversation.php';
if (!str_starts_with((string)cfg('db_name'), 'may_issue47_test')) throw new RuntimeException('Use the dedicated test DB');
$row = companion_row();
if (!$row) throw new RuntimeException('Run the API test first');
$id = $row['conv_id'];
$s = json_decode($row['state'], true);
$s['recaps'][] = ['day'=>'2000-01-01','text'=>'old fixture evidence','source'=>'child_report'];
$s['schedule_changes'][] = ['day'=>'2000-01-01','text'=>'old timetable correction','source'=>'child_report'];
q('UPDATE companion_sessions SET state = ?, updated_at = ? WHERE conv_id = ?', [json_encode($s,JSON_UNESCAPED_UNICODE),time(),$id]);
q("INSERT INTO messages (conv_id, role, content, created_at) VALUES (?, 'user', 'expired fixture', 1)",[$id]);
q("INSERT INTO companion_events (conv_id, nonce, created_at) VALUES (?, 'old-fixture-nonce', 1)",[$id]);
$r=companion_expire(strtotime('-30 days'));
if (isset($r['error'])) throw new RuntimeException($r['error']);
if (q('SELECT 1 FROM messages WHERE conv_id = ? AND created_at = 1',[$id])->fetchColumn()) throw new RuntimeException('Old message retained');
if (q("SELECT 1 FROM companion_events WHERE conv_id = ? AND nonce = 'old-fixture-nonce'",[$id])->fetchColumn()) throw new RuntimeException('Old nonce retained');
$s=json_decode(companion_row((int)$id)['state'],true);
if ($s['recaps'] || $s['schedule_changes']) throw new RuntimeException('Old structured memory retained');
if (!q('SELECT 1 FROM messages WHERE conv_id = ?',[$id])->fetchColumn()) throw new RuntimeException('Fresh history removed');
echo "PASS retention: active conversation expires old messages, structured memory and retry keys, preserving fresh history\n";
