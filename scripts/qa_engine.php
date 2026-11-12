<?php
declare(strict_types=1);
// Usage: php scripts/qa_engine.php decisions.json [--commit] [--db=NAME]
//   Default is a dry run (transaction rolled back). --db targets another database (e.g. a test copy).
//   Requires LIB to point at app/lib.php when run outside the app tree (default: ../app/lib.php).
$lib = getenv('HCM_LIB') ?: dirname(__DIR__) . '/app/lib.php';
require $lib;
require __DIR__ . '/lib_qa_engine.php';
$args = array_slice($argv, 1);
$file = null; $commit = false; $dbName = null;
foreach ($args as $a) {
    if ($a === '--commit') $commit = true;
    elseif (str_starts_with($a, '--db=')) $dbName = substr($a, 5);
    else $file = $a;
}
if (!$file || !is_file($file)) { fwrite(STDERR, "Usage: php scripts/qa_engine.php decisions.json [--commit] [--db=NAME]\n"); exit(2); }
if ($dbName !== null && !preg_match('/^[a-z0-9_]{1,64}$/', $dbName)) { fwrite(STDERR, "bad db name\n"); exit(2); }
$pdo = $dbName === null ? db() : new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string)cfg('db_host', '127.0.0.1'), (int)cfg('db_port', 3306), $dbName),
    (string)cfg('db_user', ''), (string)cfg('db_pass', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$items = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($items) || !array_is_list($items)) { fwrite(STDERR, "decisions must be a JSON list\n"); exit(2); }
$sum = qa_apply_all($pdo, $items, $commit, time());
$mode = $sum['error'] ? 'ROLLED BACK (errors)' : ($commit ? 'COMMITTED' : 'DRY-RUN');
echo $mode . ' ' . json_encode(array_map(fn($v) => ['n' => count($v), 'ids' => $v], $sum), JSON_UNESCAPED_UNICODE) . "\n";
exit($sum['error'] ? 1 : 0);
