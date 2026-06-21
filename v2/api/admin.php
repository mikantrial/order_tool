<?php
/**
 * admin.php
 * あなた専用の管理画面。
 * 店の登録・一覧・QRコード生成・削除ができる。
 * config.php の MASTER_PASSWORD でログイン。
 */

session_start();
require __DIR__ . '/config.php';

$error   = '';
$success = '';

/* ===== ログアウト ===== */
if (isset($_GET['logout'])) {
  unset($_SESSION['admin_master']);
  header('Location: admin.php');
  exit;
}

/* ===== ログイン ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['master_password'])) {
  if ($_POST['master_password'] === MASTER_PASSWORD) {
    $_SESSION['admin_master'] = true;
    header('Location: admin.php');
    exit;
  }
  $error = 'パスワードが違います';
}

$logged_in = !empty($_SESSION['admin_master']);

/* ===== 店の登録 ===== */
if ($logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_store'])) {
  $name     = trim($_POST['store_name'] ?? '');
  $password = trim($_POST['store_password'] ?? '');
  $tax_rate = floatval($_POST['tax_rate'] ?? 10);

  if ($name === '')     $error = '店名を入力してください';
  elseif (strlen($password) < 4) $error = 'パスワードは4文字以上にしてください';
  else {
    // ランダムslugを生成（16文字の16進数）
    $slug = bin2hex(random_bytes(8));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
      get_db()->prepare(
        'INSERT INTO stores (slug, name, tax_rate, password) VALUES (?, ?, ?, ?)'
      )->execute([$slug, $name, $tax_rate, $hash]);
      $success = "「{$name}」を登録しました。slug: {$slug}";
    } catch (Exception $e) {
      $error = '登録に失敗しました: ' . $e->getMessage();
    }
  }
}

/* ===== 店の削除 ===== */
if ($logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_store'])) {
  $id = intval($_POST['store_id'] ?? 0);
  if ($id > 0) {
    $st = get_db()->prepare('SELECT name FROM stores WHERE id = ?');
    $st->execute([$id]);
    $target = $st->fetch();
    if ($target) {
      get_db()->prepare('DELETE FROM stores WHERE id = ?')->execute([$id]);
      $success = "「{$target['name']}」を削除しました";
    }
  }
}

/* ===== 店一覧 ===== */
$stores = [];
if ($logged_in) {
  $st = get_db()->query(
    'SELECT s.*, COUNT(m.id) as menu_count,
            SUM(m.is_stopped) as stopped_count
     FROM stores s
     LEFT JOIN menus m ON m.store_id = s.id
     GROUP BY s.id ORDER BY s.created_at DESC'
  );
  $stores = $st->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理画面 — 注文サポート</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:"Hiragino Kaku Gothic ProN","Yu Gothic",sans-serif;
  background:#f5f4ef;color:#1c2024;font-size:15px;line-height:1.6;}
.wrap{max-width:780px;margin:0 auto;padding:20px 16px 60px;}
h1{font-size:20px;font-weight:700;}
h2{font-size:16px;font-weight:700;margin-bottom:14px;}
.sub{font-size:13px;color:#7a756b;margin-bottom:24px;}
.card{background:#fff;border:1px solid #e3ddd0;border-radius:12px;
  padding:20px;margin-bottom:20px;}
input[type=text],input[type=password],input[type=number]{
  width:100%;padding:10px 12px;border:1px solid #e3ddd0;border-radius:8px;
  font-size:15px;background:#fff;margin-bottom:8px;}
input:focus{outline:2px solid #2f6f4f;}
.btn{padding:10px 18px;border-radius:9px;border:none;font-size:14px;
  font-weight:700;cursor:pointer;}
.btn-primary{background:#2f6f4f;color:#fff;}
.btn-primary:hover{background:#256040;}
.btn-danger{background:#fff;color:#c00;border:1px solid #fcc;}
.btn-danger:hover{background:#fde8df;}
.btn-sm{padding:6px 12px;font-size:13px;border-radius:7px;}
.error{background:#fde8df;color:#b4541f;padding:10px 14px;
  border-radius:8px;margin-bottom:14px;font-size:14px;}
.success{background:#e7f0ea;color:#2f6f4f;padding:10px 14px;
  border-radius:8px;margin-bottom:14px;font-size:14px;}
.header{display:flex;justify-content:space-between;align-items:flex-start;
  margin-bottom:6px;}
.logout-link{font-size:13px;color:#7a756b;text-decoration:none;}
.logout-link:hover{color:#c00;}

/* 店一覧テーブル */
.store-table{width:100%;border-collapse:collapse;}
.store-table th{font-size:13px;color:#7a756b;font-weight:500;
  border-bottom:1px solid #e3ddd0;padding:8px 10px;text-align:left;}
.store-table td{padding:12px 10px;border-bottom:1px solid #f0ece2;
  font-size:14px;vertical-align:middle;}
.store-table tr:last-child td{border-bottom:none;}
.slug-text{font-family:monospace;font-size:12px;color:#7a756b;
  background:#f5f4ef;padding:2px 6px;border-radius:4px;}
.stopped-badge{background:#fff3e0;color:#7a4000;font-size:11px;
  padding:2px 6px;border-radius:4px;margin-left:4px;}
.btn-row{display:flex;gap:6px;flex-wrap:wrap;}

/* フォームの横並び */
.form-row{display:flex;gap:10px;}
.form-row input{margin-bottom:0;}
.form-row .w-sm{flex:0 0 90px;}

/* QRモーダル */
.modal-bg{position:fixed;inset:0;background:rgba(28,32,36,.5);
  display:none;align-items:center;justify-content:center;padding:16px;z-index:50;}
.modal-bg.show{display:flex;}
.modal{background:#fff;border-radius:16px;max-width:600px;width:100%;
  max-height:90vh;overflow:auto;padding:24px;}
.modal h3{font-size:17px;font-weight:700;margin-bottom:6px;}
.modal .sub{margin-bottom:16px;}
.qr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:16px;}
.qr-item{text-align:center;border:1px solid #e3ddd0;border-radius:10px;padding:12px;}
.qr-item .table-label{font-size:16px;font-weight:700;margin-bottom:8px;}
.qr-item .url-text{font-size:10px;color:#7a756b;word-break:break-all;margin-top:6px;}
.modal-footer{display:flex;gap:10px;margin-top:20px;}
.modal-footer .btn{flex:1;}

/* ログイン */
.login-wrap{max-width:360px;margin:80px auto;}
.login-wrap h1{margin-bottom:20px;}
.login-wrap .btn-primary{width:100%;margin-top:4px;}

@media print {
  body{background:#fff;}
  .no-print{display:none!important;}
  .qr-grid{grid-template-columns:repeat(3,1fr);}
}
</style>
</head>
<body>
<div class="wrap">

<?php if (!$logged_in): ?>
<!-- ===== ログイン ===== -->
<div class="login-wrap">
  <h1>管理画面ログイン</h1>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="password" name="master_password" placeholder="管理者パスワード" autofocus>
    <button type="submit" class="btn btn-primary">ログイン</button>
  </form>
</div>

<?php else: ?>
<!-- ===== 管理画面 ===== -->
<div class="header">
  <div>
    <h1>注文サポート 管理画面</h1>
    <p class="sub">店の登録・QRコード生成</p>
  </div>
  <a href="?logout=1" class="logout-link no-print">ログアウト</a>
</div>

<?php if ($error):   ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- 店の登録 -->
<div class="card no-print">
  <h2>新しい店を登録</h2>
  <form method="post">
    <input type="hidden" name="add_store" value="1">
    <div class="form-row" style="margin-bottom:8px;">
      <input type="text" name="store_name" placeholder="店名（例：居酒屋 花まる）" style="margin-bottom:0;">
      <input type="number" class="w-sm" name="tax_rate" value="10"
        min="0" max="30" step="0.1" placeholder="税率" style="margin-bottom:0;">
      <span style="white-space:nowrap;line-height:42px;font-size:13px;color:#7a756b;">%</span>
    </div>
    <div class="form-row">
      <input type="password" name="store_password"
        placeholder="お店の設定画面パスワード（4文字以上）" style="margin-bottom:0;">
      <button type="submit" class="btn btn-primary btn-sm" style="white-space:nowrap;">登録する</button>
    </div>
    <p style="font-size:12px;color:#7a756b;margin-top:6px;">
      slugは自動生成されます。パスワードはお店の人に伝えてください。
    </p>
  </form>
</div>

<!-- 店一覧 -->
<div class="card">
  <h2>登録済みの店（<?= count($stores) ?>件）</h2>
  <?php if (empty($stores)): ?>
    <p style="color:#7a756b;font-size:14px;">まだ店が登録されていません</p>
  <?php else: ?>
  <table class="store-table">
    <thead>
      <tr>
        <th>店名</th>
        <th>slug</th>
        <th>税率</th>
        <th>メニュー</th>
        <th class="no-print">操作</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($stores as $s): ?>
    <tr>
      <td>
        <strong><?= htmlspecialchars($s['name']) ?></strong>
        <?php if ($s['stopped_count'] > 0): ?>
          <span class="stopped-badge">品切れ<?= $s['stopped_count'] ?>件</span>
        <?php endif; ?>
      </td>
      <td><span class="slug-text"><?= htmlspecialchars($s['slug']) ?></span></td>
      <td><?= $s['tax_rate'] ?>%</td>
      <td><?= $s['menu_count'] ?>品</td>
      <td class="no-print">
        <div class="btn-row">
          <button class="btn btn-sm btn-primary"
            onclick="showQR('<?= htmlspecialchars($s['slug']) ?>','<?= htmlspecialchars(addslashes($s['name'])) ?>')">
            QR生成
          </button>
          <a href="menu-admin.php?slug=<?= urlencode($s['slug']) ?>"
            class="btn btn-sm" target="_blank"
            style="background:#fff;border:1px solid #e3ddd0;text-decoration:none;color:#1c2024;">
            メニュー編集
          </a>
          <a href="<?= BASE_URL ?>/stores/<?= urlencode($s['slug']) ?>/?table=1"
            class="btn btn-sm" target="_blank"
            style="background:#e7f0ea;border:1px solid #2f6f4f;text-decoration:none;color:#2f6f4f;">
            注文画面
          </a>
          <form method="post" style="display:inline;"
            onsubmit="return confirm('「<?= htmlspecialchars(addslashes($s['name'])) ?>」を削除しますか？\nメニューも全て消えます。')">
            <input type="hidden" name="delete_store" value="1">
            <input type="hidden" name="store_id" value="<?= $s['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">削除</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- QRモーダル -->
<div class="modal-bg no-print" id="qrModal">
  <div class="modal">
    <h3 id="qrStoreName"></h3>
    <p class="sub">テーブル数を入力してQRコードを生成します。印刷してラミネートしてテーブルへ。</p>
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;">
      <label style="font-size:14px;white-space:nowrap;">テーブル数</label>
      <input type="number" id="tableCount" value="10" min="1" max="50"
        style="width:80px;margin:0;">
      <button class="btn btn-primary btn-sm" onclick="generateQR()">生成</button>
    </div>
    <div class="qr-grid" id="qrGrid"></div>
    <div class="modal-footer">
      <button class="btn" style="border:1px solid #e3ddd0;" onclick="closeQR()">閉じる</button>
      <button class="btn btn-primary" onclick="window.print()">印刷する</button>
    </div>
  </div>
</div>

<script>
const BASE_URL = "<?= BASE_URL ?>";
let currentSlug = '';

function showQR(slug, name){
  currentSlug = slug;
  document.getElementById('qrStoreName').textContent = name + ' テーブルQRコード';
  document.getElementById('qrModal').classList.add('show');
  generateQR();
}

function closeQR(){
  document.getElementById('qrModal').classList.remove('show');
  document.getElementById('qrGrid').innerHTML = '';
  currentSlug = '';
}

function generateQR(){
  const count = Math.min(50, Math.max(1, parseInt(document.getElementById('tableCount').value)||10));
  const grid  = document.getElementById('qrGrid');
  grid.innerHTML = '';

  for(let i=1; i<=count; i++){
    const url = `${BASE_URL}/stores/${currentSlug}/?table=${i}`;
    const item = document.createElement('div');
    item.className = 'qr-item';
    item.innerHTML = `
      <div class="table-label">${i}番テーブル</div>
      <div id="qr-${i}"></div>
      <div class="url-text">${url}</div>`;
    grid.appendChild(item);
    new QRCode(document.getElementById(`qr-${i}`), {
      text: url, width:110, height:110,
      colorDark:"#1c2024", colorLight:"#ffffff",
      correctLevel: QRCode.CorrectLevel.M
    });
  }
}

// モーダル背景クリックで閉じる
document.getElementById('qrModal').addEventListener('click', function(e){
  if(e.target === this) closeQR();
});
</script>

<?php endif; ?>
</div>
</body>
</html>
