<?php
// 1. Azure の設定を取得 (App Service の環境変数から読み込み)
$ocr_key = getenv('OCR_KEY');
$ocr_endpoint = getenv('OCR_ENDPOINT');

// エンドポイントの末尾を調整
$ocr_endpoint = rtrim($ocr_endpoint, '/') . '/';

// 2. 保存用フォルダの準備
$upload_dir = 'uploads/';
if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

$results = [];

// 3. アップロード処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmp_name) {
        if (empty($tmp_name)) continue;

        $original_name = $_FILES['receipts']['name'][$key];
        $filename = time() . '_' . $original_name;
        $filepath = $upload_dir . $filename;
        move_uploaded_file($tmp_name, $filepath);

        // Azure Document Intelligence API URL (最新の領収書モデル)
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 認証エラーのチェック
        if ($httpCode == 401) {
            die("【エラー】AzureのKeyが正しくありません。App Serviceの設定を確認してください。");
        }

        // 解析リクエストIDを取得して結果を待機
        if (preg_match('/apim-request-id:\s*([\w-]+)/i', $headers, $matches)) {
            $requestId = trim($matches[1]);
            $resultUrl = $ocr_endpoint . "formrecognizer/documentModels/prebuilt-receipt/analyzeResults/" . $requestId . "?api-version=2023-07-31";

            // 最大10回、1秒おきに結果を確認
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
        body { font-family: 'Helvetica Neue', Arial, sans-serif; padding: 20px; background: #f0f2f5; color: #333; }
        .card { background: white; padding: 20px; margin-bottom: 15px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-left: 5px solid #00a82d; }
        .total { color: #d63031; font-size: 1.4em; font-weight: bold; }
        h2 { color: #00a82d; }
        .btn { display: inline-block; padding: 10px 20px; background: #00a82d; color: white; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <h2>🏪 スキャン結果</h2>
    <?php if (empty($results)): ?>
        <div style="background: #fab1a0; padding: 20px; border-radius: 10px;">
            <p><strong>解析できませんでした。</strong></p>
            <p>設定を確認してください：</p>
            <ul>
                <li>App Serviceの環境変数 <strong>OCR_KEY</strong> が正しいか</li>
                <li>App Serviceの環境変数 <strong>OCR_ENDPOINT</strong> が正しいか</li>
            </ul>
        </div>
    <?php else: ?>
        <?php foreach ($results as $res): ?>
            <div class="card">
                <p><strong>ファイル:</strong> <?php echo htmlspecialchars($res['filename']); ?></p>
                <p><strong>店舗:</strong> <?php echo htmlspecialchars($res['merchant']); ?></p>
                <p><strong>日付:</strong> <?php echo htmlspecialchars($res['date']); ?></p>
                <p class="total"><strong>合計金額:</strong> ¥<?php echo number_format($res['total']); ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <br>
    <a href="index.php" class="btn">← 戻る</a>
</body>
</html>
