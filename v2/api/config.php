<?php
/**
 * config.php
 * DB接続設定とAPIキー。絶対にGitHubなど公開の場所に上げないこと。
 */

/* ---------- MySQL ---------- */
define('DB_HOST', 'localhost');
define('DB_NAME', 'restaurant_store_db');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_CHARSET', 'utf8mb4');

/* ---------- Gemini API ---------- */
// お持ちのAPIキーを貼る（AIzaSy... で始まる文字列）
define('GEMINI_API_KEY', 'AIzaSyDtURPNmLT8w-wk-04uBnivIXQaMchlPVE');
// お持ちのキーが対応するモデル名に合わせて変更
// 例: 'gemini-2.5-flash', 'gemini-3.5-flash'
define('GEMINI_MODEL', 'gemini-2.5-flash');

/* ---------- あなた専用管理画面のパスワード ---------- */
// admin.php にアクセスするためのパスワード。自分だけが知っているものにする。
define('MASTER_PASSWORD', 'qCT9FPbLrAxdda92');

/* ---------- サイトのベースURL ---------- */
// QRコードのURLに使う。末尾スラッシュなし。
define('BASE_URL', 'http://localhost:8888/order-support');

/* ---------- DB接続（PDO）---------- */
function get_db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
  }
  return $pdo;
}

/* ---------- 共通レスポンス ---------- */
function json_ok($data = []) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_fail($msg, $code = 400) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- slugからstoreを取得 ---------- */
function get_store_by_slug(string $slug): ?array {
  $st = get_db()->prepare('SELECT * FROM stores WHERE slug = ?');
  $st->execute([$slug]);
  return $st->fetch() ?: null;
}
