<?php
// 1. Azure の設定を取得
$ocr_key = getenv('OCR_KEY');
$ocr_endpoint = getenv('OCR_ENDPOINT');

// エンドポイントの形式を自動調整（末尾の / を整理）
$ocr_endpoint = rtrim($ocr_endpoint, '/') . '/';

// 2. アップロード用フォルダの準備
$upload_dir = 'uploads/';
if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

$results = [];

// 3. アップロードされたファイルの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmp_name) {
        if (empty($tmp_name)) continue;

        $original_name = $_FILES['receipts']['name'][$key];
        $filename = time() . '_' . $original_name;
        $filepath = $upload_dir . $filename;
        move_uploaded_file($tmp_name, $filepath);

        // Azure AI Document Intelligence API (最新版)
        $url = $ocr_endpoint . "formrecognizer/documentModels/prebuilt-receipt:analyze?api-version=2023-07-31";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/octet-stream',
            'Ocp-Apim-Subscription-Key: ' . $ocr_key
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($filepath));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        curl_close($ch);

        // 解析リクエスト ID を取得
        if (preg_match('/apim-request-id:\s*([\w-]+)/i', $headers, $matches)) {
            $requestId = trim($matches[1]);
            $resultUrl = $ocr_endpoint . "formrecognizer/documentModels/prebuilt-receipt/analyzeResults/" . $requestId . "?api-version=2023-07-31";

            // 結果が出るまでループで待機
            for ($i = 0; $i < 10; $i++) {
                sleep(1);
                $ch = curl_init($resultUrl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $ocr_key]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $resBody = curl_exec($ch);
                curl_close($ch);

                $data = json_decode($resBody, true);
                if (isset($data['status']) && $data['status'] === 'succeeded') {
                    $doc = $data['analyzeResult']['documents'][0]['fields'] ?? [];
                    $results[] = [
                        'filename' => $original_name,
                        'merchant' => $doc['MerchantName']['valueString'] ?? '不明な店舗',
                        'date' => $doc['TransactionDate']['valueDate'] ?? '不明な日付',
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
        body { font-family: sans-serif; padding: 20px; background: #f9f9f9; }
        .card { background: white; padding: 15px; margin-bottom: 10px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .total { color: #e74c3c; font-size: 1.2em; font-weight: bold; }
        .error { color: #d63031; background: #fab1a0; padding: 15px; border-radius: 8px; }
    </style>
</head>
<body>
    <h2>🏪 スキャン結果</h2>
    <?php if (empty($results)): ?>
        <div class="error">
            <p><strong>解析に失敗しました。</strong></p>
            <p>原因として以下の可能性があります：</p>
            <ul>
                <li>Azure の Key または Endpoint が正しく設定されていない。</li>
                <li>領収書が不鮮明で読み取れなかった。</li>
            </ul>
        </div>
    <?php else: ?>
        <?php foreach ($results as $res): ?>
            <div class="card">
                <p><strong>ファイル名：</strong><?php echo htmlspecialchars($res['filename']); ?></p>
                <p><strong>店舗名：</strong><?php echo htmlspecialchars($res['merchant']); ?></p>
                <p><strong>日付：</strong><?php echo htmlspecialchars($res['date']); ?></p>
                <p class="total"><strong>合計金額：</strong>¥<?php echo number_format($res['total']); ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <br>
    <a href="index.php">← 戻って再度アップロード</a>
</body>
</html>
