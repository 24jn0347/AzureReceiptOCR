<?php
// 1. 获取 Azure 钥匙
$ocr_key = getenv('OCR_KEY');
$ocr_endpoint = getenv('OCR_ENDPOINT');
$ocr_endpoint = rtrim($ocr_endpoint, '/') . '/';

// 2. 准备文件夹
$upload_dir = 'uploads/';
if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

$results = [];

// 3. 处理上传的小票
foreach ($_FILES['receipts']['tmp_name'] as $key => $tmp_name) {
    if (empty($tmp_name)) continue;

    $original_name = $_FILES['receipts']['name'][$key];
    $filename = time() . '_' . $original_name;
    $filepath = $upload_dir . $filename;
    move_uploaded_file($tmp_name, $filepath);

    // 调用最新的 Azure Receipt 识别接口
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

    // 获取结果地址
    if (preg_match('/apim-request-id:\s*([\w-]+)/i', $headers, $matches)) {
        $requestId = trim($matches[1]);
        $resultUrl = $ocr_endpoint . "formrecognizer/documentModels/prebuilt-receipt/analyzeResults/" . $requestId . "?api-version=2023-07-31";

        // 轮询等待 AI 结果
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
                    'merchant' => $doc['MerchantName']['valueString'] ?? '未知店铺',
                    'date' => $doc['TransactionDate']['valueDate'] ?? '未知日期',
                    'total' => $doc['Total']['valueCurrency']['amount'] ?? 0
                ];
                break;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>识别结果</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f9f9f9; }
        .card { background: white; padding: 15px; margin-bottom: 10px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .total { color: #e74c3c; font-size: 1.2em; font-weight: bold; }
    </style>
</head>
<body>
    <h2>🏪 扫描结果</h2>
    <?php if (empty($results)): ?>
        <p>未能识别，请检查 Key 和 Endpoint 是否正确。</p>
    <?php else: ?>
        <?php foreach ($results as $res): ?>
            <div class="card">
                <p><strong>文件：</strong><?php echo $res['filename']; ?></p>
                <p><strong>店名：</strong><?php echo $res['merchant']; ?></p>
                <p><strong>日期：</strong><?php echo $res['date']; ?></p>
                <p class="total"><strong>金额：</strong>¥<?php echo number_format($res['total']); ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <br>
    <a href="index.php">← 返回继续上传</a>
</body>
</html>
