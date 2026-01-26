<?php
require 'config.php';

// 创建文件夹（如果不存在）
if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
if (!file_exists($csv_dir)) mkdir($csv_dir, 0777, true);
if (!file_exists(dirname($log_file))) mkdir(dirname($log_file), 0777, true);
if (!file_exists($log_file)) file_put_contents($log_file, "");

// 连接数据库
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) die("数据库连接失败: " . $conn->connect_error);

$results = []; // 保存每张图片结果

foreach ($_FILES['receipts']['tmp_name'] as $key => $tmp_name) {
    $original_name = $_FILES['receipts']['name'][$key];
    $filename = time() . '_' . $original_name;
    $filepath = $upload_dir . $filename;
    move_uploaded_file($tmp_name, $filepath);

    // ---------- 调用 Azure OCR ----------
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $ocr_endpoint . "vision/v3.2/read/analyze");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Ocp-Apim-Subscription-Key: $ocr_key",
        "Content-Type: application/octet-stream"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($filepath));
    $response = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status_code != 202) {
        $results[] = [
            'filename' => $filename,
            'items' => ['OCR失败'],
            'total' => 0
        ];
        continue;
    }

    // 获取 OCR 结果 URL
    $response_data = json_decode($response, true);
    $operation_location = $response_data['operation-location'] ?? '';
    if (!$operation_location) continue;

    // 等待 OCR 处理完成
    $ocr_text_lines = [];
    for ($i = 0; $i < 20; $i++) { // 最多轮询20次
        sleep(1);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $operation_location);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Ocp-Apim-Subscription-Key: $ocr_key"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result_json = curl_exec($ch);
        curl_close($ch);

        // 写日志
        file_put_contents($log_file, "[".date('Y-m-d H:i:s')."] $filename\n$result_json\n", FILE_APPEND);

        $result_data = json_decode($result_json, true);
        if (isset($result_data['status']) && $result_data['status'] == 'succeeded') {
            foreach ($result_data['analyzeResult']['readResults'] as $page) {
                foreach ($page['lines'] as $line) {
                    $ocr_text_lines[] = $line['text'];
                }
            }
            break;
        }
    }

    // ---------- 解析 FamilyMart 收据 ----------
    $items = [];
    $total = 0;
    foreach ($ocr_text_lines as $line) {
        $line = str_replace(['軽','※'], '', $line); // 去掉多余字符

        // 商品和价格
        if (preg_match('/(.+?)\s*¥?(\d+)/u', $line, $matches)) {
            $items[] = trim($matches[1]) . ' ¥' . $matches[2];
        }

        // 合计
        if (strpos($line, '合計') !== false) {
            if (preg_match('/¥?(\d+)/u', $line, $m)) $total = $m[1];
        }
    }

    // 写入数据库
    $stmt = $conn->prepare("INSERT INTO receipts (filename, items, total) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $filename, implode(", ", $items), $total);
    $stmt->execute();
    $stmt->close();

    $results[] = [
        'filename' => $filename,
        'items' => $items,
        'total' => $total
    ];
}

// ---------- 生成统一 CSV ----------
$csv_file = $csv_dir . "receipts_all_" . date('Ymd_His') . ".csv";
$fp = fopen($csv_file, 'w');
fputcsv($fp, ['filename', 'items', 'total']);
foreach ($results as $res) {
    fputcsv($fp, [$res['filename'], implode(", ", $res['items']), $res['total']]);
}
fclose($fp);

$conn->close();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>レシートOCR 結果</title>
</head>
<body>
<h2>OCR結果</h2>

<?php foreach ($results as $res): ?>
    <h3><?php echo htmlspecialchars($res['filename']); ?></h3>
    <p>商品: <?php echo htmlspecialchars(implode(", ", $res['items'])); ?></p>
    <p>合計: ¥<?php echo htmlspecialchars($res['total']); ?></p>
<?php endforeach; ?>

<p><a href="<?php echo htmlspecialchars($csv_file); ?>" download>CSVをダウンロード</a></p>
<p><a href="logs/ocr.log" download>OCRログをダウンロード</a></p>
<p><a href="index.php">戻る</a></p>
</body>
</html>
