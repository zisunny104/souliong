<?php
/**
 * 投稿軸關卡：upload.php 與 newspot.php（contributor 模式）共用，不各寫一份。
 *
 * 投稿軸跟點位軸（edit_spots）是兩條分開的權限：能不能「免碼投稿」看 bypass_code，
 * 能不能改點位看 edit_spots，兩者互不蘊含。這裡不驗 CSRF——投稿者是匿名的，憑投稿代碼
 * 與 owner／ctoken 這類 bearer 秘密辨識，不是登入身分。
 */
require_once __DIR__ . '/auth.php';

/** 這個請求聲稱的投稿者身分（owner／ctoken 兩個 bearer 秘密），由請求解析一次。 */
final class Contributor {
    private function __construct(
        private string $owner,     // 匿名裝置 token（明文，只在這個物件裡，不落地）
        private string $ctoken     // 有 PIN 的投稿者跨裝置 token
    ) {}

    public static function fromRequest(): Contributor {
        return new Contributor((string)($_POST['owner'] ?? ''), (string)($_POST['ctoken'] ?? ''));
    }

    /** 存進投稿記錄的 owner_hash；沒送 owner 為 null。 */
    public function ownerHash(): ?string { return $this->owner !== '' ? hash('sha256', $this->owner) : null; }
    /** 對外可見的假名投稿者 ID（contrib_id）；沒送 ctoken 為 null。 */
    public function contribId(): ?string { return $this->ctoken !== '' ? contrib_id_of($this->ctoken) : null; }
    /** 存進投稿記錄供跨裝置驗證本人的 contrib_hash（不外流）；沒送 ctoken 為 null。 */
    public function contribHash(): ?string { return $this->ctoken !== '' ? contrib_hash_of($this->ctoken) : null; }
    /** 有穩定身分（ctoken）：CC BY 姓名標示只對有穩定身分的投稿者開放。 */
    public function hasIdentity(): bool { return $this->ctoken !== ''; }

    /** 這筆記錄是不是這位投稿者本人的（owner 或 ctoken 任一相符）。管理者代編代刪不走這裡，走 Auth。 */
    public function owns(array $record): bool {
        $ownerStored   = (string)($record['owner_hash'] ?? '');
        $contribStored = (string)($record['contrib_hash'] ?? '');
        $ownerOk   = $this->owner !== '' && $ownerStored !== '' && hash_equals($ownerStored, hash('sha256', $this->owner));
        $contribOk = $this->ctoken !== '' && $contribStored !== '' && hash_equals($contribStored, contrib_hash_of($this->ctoken));
        return $ownerOk || $contribOk;
    }
}

/**
 * 投稿把關：依序 停權名單 → bypass_code（具備者不需要碼）→ 這張地圖有沒有開放投稿 → 投稿代碼（計一次使用）。
 * 任一關失敗直接 403 結束；通過回傳解析好的 Contributor。
 * $what 只用在缺碼時的訊息（「上傳」「建立地點」）。
 */
function contrib_gate(array $cfg, string $project, string $what = '上傳'): Contributor {
    $who = Contributor::fromRequest();
    if (is_blocked($cfg, $project, $who->ownerHash(), $who->contribId())) {
        json_out(['error' => '此身分已被主辦者停權，無法繼續投稿'], 403);
    }
    if (Auth::can($cfg, $project, 'bypass_code')) return $who;
    if (!contrib_open($cfg, $project)) {
        json_out(['error' => '這張地圖目前未開放投稿'], 403);
    }
    // 能不能投稿完全看投稿代碼（codes.json，各自可設到期／次數）：有效碼一組都沒有＝未開放；有碼就一定要附碼，
    // 這裡順便計一次使用（建點跟上傳是等價的寫入行為，限次的碼不能無限用）。
    $given = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
    if (!code_check($cfg, $project, $given, true)) {
        json_out(['error' => '需要正確的投稿代碼才能' . $what . '（碼可能已到期或用完次數）'], 403);
    }
    return $who;
}
