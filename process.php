<?php
// 1. 获取环境变量
$ocr_key = getenv('OCR_KEY');
$ocr_endpoint = getenv('OCR_ENDPOINT');
$ocr_endpoint = rtrim($ocr_endpoint, '/') . '/';

$results = [];
$debug_info = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmp_name) {
        if (empty($tmp_name)) continue;
        $fileData = file_get_contents($tmp_name);

        // 拼接 API 地址
        $url = $ocr_endpoint . "formrecognizer/documentModels/prebuilt-receipt:analyze?api-version=2023-07-31";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/octet-stream',
            'Ocp-Apim-Subscription-Key: ' . $ocr_key
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        curl_close($ch);

        if ($httpCode !== 202) {
            $debug_info = "API连接失败。HTTP代码: " . $httpCode . " (如果是401说明Key错了, 404说明Endpoint错了)";
            continue;
        }

        if (preg_match('/apim-request-id:\s*([\w-]+)/i', $headers, $matches)) {
            $requestId = trim($matches[1]);
            $resultUrl = $ocr_endpoint . "formrecognizer/documentModels/prebuilt-receipt/analyzeResults/" . $requestId . "?api-version=2023-07-31";

            // 轮询结果
            for ($i = 0; $i < 10; $i++) {
                sleep(2);
                $ch = curl_init($resultUrl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $ocr_key]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $resBody = curl_exec($ch);
                curl_close($ch);

                $data = json_decode($resBody, true);
                if (isset($data['status']) && $data['status'] === 'succeeded') {
                    $doc = $data['analyzeResult']['documents'][0]['fields'] ?? [];
                    $results[] = [
                        'merchant' => $doc['MerchantName']['valueString'] ?? '店舗名不明',
                        'date' => $doc['TransactionDate']['valueDate'] ?? '日付不明',
                        'total' => $doc['Total']['valueCurrency']['amount'] ?? 0
                    ];
                    break;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>スキャン結果</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f0f2f5; }
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-left: 5px solid #00a82d; margin-bottom: 10px; }
        .error-box { background: #fab1a0; padding: 15px; border-radius: 8px; color: #c0392b; }
    </style>
</head>
<body>
    <h2>🏪 スキャン結果</h2>
    <?php if (empty($results)): ?>
        <div class="error-box">
            <p><strong>解析に失敗しました。</strong></p>
            <p>診断情報: <?php echo $debug_info ?: "AIからの応答がありません。画像が不鮮明か、Keyの設定を確認してください。"; ?></p>
            <hr>
            <p>現在の設定 (確認用):</p>
            <p>Endpoint: <?php echo htmlspecialchars($ocr_endpoint); ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($results as $res): ?>
            <div class="card">
                <p><strong>店舗:</strong> <?php echo htmlspecialchars($res['merchant']); ?></p>
                <p><strong>日付:</strong> <?php echo htmlspecialchars($res['date']); ?></p>
                <p style="color:red; font-size:1.2em;"><strong>合計:</strong> ¥<?php echo number_format($res['total']); ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <br><a href="index.php">← 戻る</a>
</body>
</html>
