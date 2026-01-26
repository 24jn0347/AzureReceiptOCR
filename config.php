<?php
// ---------- 数据库配置 ----------
// 优先从 Azure 环境变量读取，如果读取不到则使用本地测试值（建议上线后将默认值设为空）
$db_host = getenv('DB_HOST') ?: "24jn0347db.database.windows.net";
$db_name = getenv('DB_NAME') ?: "ReceiptDB";
$db_user = getenv('DB_USER') ?: "jndb";
$db_pass = getenv('DB_PASS') ?: "Pa$$word1234";

// ---------- OCR 配置 ----------
// GitHub 会拦截硬编码的 Key，所以这里必须使用 getenv
$ocr_endpoint = getenv('OCR_ENDPOINT') ?: "https://receiptvision-jndb.cognitiveservices.azure.com/";
$ocr_key = getenv('OCR_KEY'); 

// ---------- 文件夹路径 ----------
// 确保目录存在（Azure App Service 建议使用相对路径或 tmp 目录）
$upload_dir = __DIR__ . "/uploads/";
$log_file = __DIR__ . "/ocr.log"; // 根据要件要求，直接放在根目录方便下载
$csv_dir = __DIR__ . "/csv/";

// 自动创建必要的文件夹
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
if (!is_dir($csv_dir)) mkdir($csv_dir, 0777, true);
?>