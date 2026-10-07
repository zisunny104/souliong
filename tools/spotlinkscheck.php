<?php
require_once __DIR__ . '/../api/spotlib.php';
$valid = [['url'=>'https://example.com/a?x=1&y=2','icon'=>'link'],['url'=>'https://instagram.com/a','icon'=>'instagram'],['url'=>'https://instagram.com/b','icon'=>'instagram']];
if (spot_links_validate($valid) !== $valid || spot_links_validate([]) !== [] || spot_links_validate([['url'=>'https://example.com']])[0]['icon'] !== 'link') throw new RuntimeException('Valid links failed');
$invalid = [null, ['url'=>'https://example.com'], [['url'=>'javascript:alert(1)']], [['url'=>'https://user:pass@example.com']], [['url'=>'https://example.com','icon'=>'fa-link onclick=alert(1)']], [['url'=>'https://example.com','icon'=>[]]], [['url'=>"https://example.com\n"]], array_fill(0,31,$valid[0])];
foreach ($invalid as $value) { $rejected=false; try { spot_links_validate($value); } catch (InvalidArgumentException $e) { $rejected=true; } if (!$rejected) throw new RuntimeException('Unsafe links accepted'); }
echo "點位連結：合法網址、多筆同類、清空、預設圖示及 8 種非法輸入通過\n";
