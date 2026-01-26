<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>レシートOCR（FamilyMart）</title>
</head>
<body>
    <h2>ファミリーマート レシートOCR</h2>

    <form action="process.php" method="post" enctype="multipart/form-data">
        <input type="file" name="receipts[]" multiple accept="image/*" required>
        <br><br>
        <button type="submit">アップロード & OCR 実行</button>
    </form>
</body>
</html>
