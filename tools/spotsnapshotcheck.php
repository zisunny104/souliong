<?php
// 隔離快照：同次頁面／社群卡重用資料；新請求仍讀到後續編輯。
require_once dirname(__DIR__) . '/api/socialcardlib.php';
$dir = sys_get_temp_dir() . '/souliong-snapshot-' . bin2hex(random_bytes(5));
mkdir($dir . '/test', 0775, true);
$cfg = ['projects_dir' => $dir];
$spot = ['kind'=>'spot','id'=>'aabbccdd11223344','num'=>1,'title'=>'地點','lat'=>24,'lon'=>120,'content'=>[['kind'=>'text','comment'=>'原內容']]];
$entry = ['kind'=>'text','id'=>'entry1','item_num'=>1,'name'=>'作者','comment'=>'原投稿'];
$rows = [$spot,$entry];
file_put_contents($dir.'/test/spots.jsonl',json_encode($spot)."\n");
file_put_contents($dir.'/test/entries.jsonl',json_encode($entry)."\n");
try {
    $edit=['kind'=>'spot','id'=>'11223344aabbccdd','edit_of'=>$spot['id'],'lat'=>25,'created_at'=>'2026-10-26T12:00:00Z'];
    file_put_contents($dir.'/test/spots.jsonl',json_encode($edit)."\n",FILE_APPEND);
    if (spot_effective_by_ref($cfg,'test','1',$rows)['lat']!==24) throw new Exception('快照混入後續寫入');
    if (spot_effective_by_ref($cfg,'test',$spot['id'],$rows)['lat']!==24) throw new Exception('ID 快照混入後續寫入');
    if (spot_effective_by_ref($cfg,'test','1')['lat']!==25) throw new Exception('新讀取沿用過期快照');
    file_put_contents($dir.'/test/entries.jsonl','');
    if (souliong_og_resolve_entry($cfg,'test','entry1',$rows)['comment']!=='原投稿') throw new Exception('投稿未沿用快照');
    if (souliong_og_resolve_entry($cfg,'test','entry1')!==null) throw new Exception('刪除投稿仍沿用舊資料');
    $data=souliong_social_data($cfg,'test',['title'=>'專案'],'entry1','','zh_TW',$rows);
    if (($data['author']??null)!=='作者'||($data['spotId']??null)!==$spot['id']) throw new Exception('社群資料未共用快照');
    echo "PASS: 編號／ID／投稿／社群資料共用快照，新讀取仍反映編輯與刪除\n";
} finally {
    unlink($dir.'/test/spots.jsonl');unlink($dir.'/test/entries.jsonl');rmdir($dir.'/test');rmdir($dir);
}
