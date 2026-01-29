<?php
// 1. 環境変数の取得と整理
$ocr_key = trim(getenv('OCR_KEY'));
$ocr_endpoint = trim(getenv('OCR_ENDPOINT'));

// エンドポイントの形式を正しく整形
$ocr_endpoint = rtrim($ocr_endpoint, '/') . '/';

$results = [];
$debug_info = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmp_name) {
        if (empty($tmp_name)) continue;
        
        $fileData = file_get_contents($tmp_name);
        
        // API URL (2023-07-31 は安定版)
        $url = $ocr_endpoint . "formrecognizer/documentModels/prebuilt-receipt:analyze?api-version=2023-07-31";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        
        // 【ここが重要】32文字の短キー (7365...) の場合はこっちのヘッダーを使う
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $ocr_key,
            'Content-Type: application/octet-stream'
        ]);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        $resBody = substr($response, $header_size);
        curl_close($ch);

        if ($httpCode === 202) {
            // 解析IDまたはLocationを取得
            if (preg_match('/Operation-Location:\s*(.*)\r/i', $headers, $matches)) {
                $resultUrl = trim($matches[1]);

                for ($i = 0; $i < 15; $i++) {
                    sleep(2);
                    $ch_res = curl_init($resultUrl);
                    curl_setopt($ch_res, CURLOPT_HTTPHEADER, [
                        'Ocp-Apim-Subscription-Key: ' . $ocr_key // 短キー用
                    ]);
                    curl_setopt($ch_res, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch_res, CURLOPT_SSL_VERIFYPEER, false);
                    $finalResBody = curl_exec($ch_res);
                    curl_close($ch_res);

                    $data = json_decode($finalResBody, true);
                    if (isset($data['status']) && $data['status'] === 'succeeded') {
                        $doc = $data['analyzeResult']['documents'][0]['fields'] ?? [];
                        $results[] = [
                            'merchant' => $doc['MerchantName']['valueString'] ?? '店舗名不明',
                            'date' => $doc['TransactionDate']['valueDate'] ?? '日付不明',
                            'total' => $doc['Total']['valueCurrency']['amount'] ?? ($doc['Total']['valueNumber'] ?? 0)
                        ];
                        break;
                    }
                }
            }
        } else {
            $errorDetail = json_decode($resBody, true);
            $debug_info = "HTTP $httpCode: " . ($errorDetail['error']['message'] ?? '認証エラー。キーが正しいか確認してください。');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>解析結果</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f0f2f5; }
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-left: 5px solid #00a82d; margin-bottom: 15px; }
        .error-msg { background: #fab1a0; padding: 20px; border-radius: 10px; color: #c0392b; }
        .success-text { color: #00a82d; font-weight: bold; }
    </style>
</head>
<body>
    <h2>🏪 レシート解析結果</h2>
    
    <?php if (empty($results)): ?>
        <div class="error-msg">
            <p><strong>解析に失敗しました。</strong></p>
            <p>診断情報: <?php echo htmlspecialchars($debug_info); ?></p>
            <hr>
            <p>【現在の設定】</p>
            <p>Endpoint: <?php echo htmlspecialchars($ocr_endpoint); ?></p>
            <p>Key（先頭4文字）: <?php echo htmlspecialchars(substr($ocr_key, 0, 4)); ?>...</p>
        </div>
    <?php else: ?>
        <p class="success-text">スキャンが完了しました！</p>
        <?php foreach ($results as $res): ?>
            <div class="card">
                <p><strong>店舗名:</strong> <?php echo htmlspecialchars($res['merchant']); ?></p>
                <p><strong>利用日:</strong> <?php echo htmlspecialchars($res['date']); ?></p>
                <p style="color:#d63031; font-size:1.2em;"><strong>合計金額:</strong> ¥<?php echo number_format($res['total']); ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <br><a href="index.php">← 戻る</a>
</body>
</html>
