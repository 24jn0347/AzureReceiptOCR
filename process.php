<?php
// 1. 環境設定
$ocr_key = trim(getenv('OCR_KEY'));
$ocr_endpoint = trim(getenv('OCR_ENDPOINT'));
$ocr_endpoint = rtrim($ocr_endpoint, '/') . '/';

$log_file = "ocr.log";
$csv_file = "csv/result.csv";
if (!is_dir("csv")) mkdir("csv", 0777, true);

$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $idx => $tmp_name) {
        if (empty($tmp_name)) continue;
        
        $fileData = file_get_contents($tmp_name);
        $url = $ocr_endpoint . "formrecognizer/documentModels/prebuilt-receipt:analyze?api-version=2023-07-31";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $ocr_key, // 長いキー (ASUkv...) 用
            'Content-Type: application/octet-stream'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 202 && preg_match('/Operation-Location:\s*(.*)\r/i', $headers, $matches)) {
            $resultUrl = trim($matches[1]);
            // 解析待ち
            for ($i = 0; $i < 10; $i++) {
                sleep(2);
                $ch_res = curl_init($resultUrl);
                curl_setopt($ch_res, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $ocr_key]);
                curl_setopt($ch_res, CURLOPT_RETURNTRANSFER, true);
                $finalBody = curl_exec($ch_res);
                curl_close($ch_res);

                $data = json_decode($finalBody, true);
                if (isset($data['status']) && $data['status'] === 'succeeded') {
                    $doc = $data['analyzeResult']['documents'][0]['fields'] ?? [];
                    
                    // --- 要件定義：商品名と値段の抽出 ---
                    $items_text = [];
                    if (isset($doc['Items']['valueArray'])) {
                        foreach ($doc['Items']['valueArray'] as $itemObj) {
                            $f = $itemObj['valueObject'] ?? [];
                            // 「軽」や「*」などの不要な文字を削除
                            $name = $f['Description']['valueString'] ?? '';
                            $name = str_replace(['軽', '*', '＊', '◎', '（', '）', '(', ')'], '', $name);
                            $price = $f['TotalPrice']['valueNumber'] ?? 0;
                            if ($name) $items_text[] = "$name ¥$price";
                        }
                    }

                    $total = $doc['Total']['valueCurrency']['amount'] ?? ($doc['Total']['valueNumber'] ?? 0);
                    $merchant = $doc['MerchantName']['valueString'] ?? 'FamilyMart';
                    
                    // 表示用とログ用の文字列作成
                    $final_content = implode(", ", $items_text) . ", 合計 ¥$total";
                    $results[] = ["merchant" => $merchant, "text" => $final_content];

                    // --- 要件定義：ocr.log への書き込み ---
                    // file_put_contents がログを生成する核心部分
                    $log_entry = "[" . date("Y-m-d H:i:s") . "] " . $final_content . PHP_EOL;
                    file_put_contents($log_file, $log_entry, FILE_APPEND);
                    
                    // --- 要件定義：CSV ファイルの生成 ---
                    $fp = fopen($csv_file, 'a');
                    fputcsv($fp, [$merchant, $final_content]);
                    fclose($fp);
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
    <title>解析結果</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f0f2f5; line-height: 1.6; }
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-left: 5px solid #00a82d; margin-bottom: 15px; }
        .btn { display: inline-block; padding: 10px 20px; color: white; text-decoration: none; border-radius: 5px; margin: 5px; font-weight: bold; }
    </style>
</head>
<body>
    <h2>🏪 レシート解析結果</h2>
    
    <?php if (empty($results)): ?>
        <p>解析結果がありません。アップロードし直してください。</p>
    <?php else: ?>
        <?php foreach ($results as $res): ?>
            <div class="card">
                <p><strong><?php echo htmlspecialchars($res['merchant']); ?>:</strong></p>
                <p><?php echo htmlspecialchars($res['text']); ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <hr>
    <h3>【提出書類ダウンロード】</h3>
    <a href="ocr.log" class="btn" style="background:#6c757d;">ocr.log を表示</a>
    <a href="<?php echo $csv_file; ?>" class="btn" style="background:#28a745;" download>CSVファイルをダウンロード</a>
    <br><br>
    <a href="index.php">← 戻る</a>
</body>
</html>
