<?php
/**
 * menu-admin.php
 * お店のメニューを編集する管理画面。
 * URLに ?slug=xK9mP2qR を付けて開く。
 * 一度パスワードでログインするとセッションで保持される。
 */

session_start();
require __DIR__ . '/config.php';

$slug  = trim($_GET['slug'] ?? $_POST['slug'] ?? '');
$error = '';
$store = null;
$menus = [];

if ($slug === '') {
  die('<p style="font-family:sans-serif;padding:2rem;color:#c00">URLにslugが必要です（?slug=xxxx）</p>');
}

$store = get_store_by_slug($slug);
if (!$store) {
  die('<p style="font-family:sans-serif;padding:2rem;color:#c00">店が見つかりません</p>');
}

$session_key = 'admin_' . $slug;

/* ===== ログアウト ===== */
if (isset($_GET['logout'])) {
  unset($_SESSION[$session_key]);
  header('Location: menu-admin.php?slug=' . urlencode($slug));
  exit;
}

/* ===== ログイン処理 ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !isset($_SESSION[$session_key])) {
  if (password_verify($_POST['password'], $store['password'])) {
    $_SESSION[$session_key] = true;
    header('Location: menu-admin.php?slug=' . urlencode($slug));
    exit;
  } else {
    $error = 'パスワードが違います';
  }
}

$logged_in = !empty($_SESSION[$session_key]);

/* ===== メニュー保存処理 ===== */
$saved = false;
if ($logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_menu'])) {
  $names      = $_POST['name']       ?? [];
  $readings   = $_POST['reading']    ?? [];
  $prices     = $_POST['price']      ?? [];
  $aliases    = $_POST['aliases']    ?? [];
  $stops      = $_POST['is_stopped'] ?? [];
  $tax_rate   = floatval($_POST['tax_rate'] ?? $store['tax_rate']);
  $tax_rate   = max(0, min(30, $tax_rate));

  $db = get_db();
  $db->beginTransaction();
  try {
    $db->prepare('DELETE FROM menus WHERE store_id = ?')->execute([$store['id']]);
    $ins = $db->prepare(
      'INSERT INTO menus (store_id, name, reading, price, aliases, sort_order, is_stopped)
       VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($names as $i => $name) {
      $name = trim($name);
      if ($name === '') continue;
      $reading   = trim($readings[$i] ?? '');
      $price     = max(0, intval($prices[$i] ?? 0));
      $aliasStr  = trim($aliases[$i] ?? '');
      $aliasArr  = array_filter(array_map('trim', explode(',', $aliasStr)));
      if (!in_array($name, $aliasArr)) array_unshift($aliasArr, $name);
      $aliasStr  = implode(',', $aliasArr);
      $is_stopped = isset($stops[$i]) ? 1 : 0;
      $ins->execute([$store['id'], $name, $reading, $price, $aliasStr, $i, $is_stopped]);
    }
    $db->prepare('UPDATE stores SET tax_rate = ? WHERE id = ?')
       ->execute([$tax_rate, $store['id']]);
    $db->commit();
    $saved = true;
    // 保存後にstoreを再取得
    $store = get_store_by_slug($slug);
  } catch (Exception $e) {
    $db->rollBack();
    $error = '保存に失敗しました: ' . $e->getMessage();
  }
}

/* ===== メニュー読み込み ===== */
if ($logged_in) {
  $st = get_db()->prepare(
    'SELECT * FROM menus WHERE store_id = ? ORDER BY sort_order ASC, id ASC'
  );
  $st->execute([$store['id']]);
  $menus = $st->fetchAll();
}

$has_stopped = array_filter($menus, fn($m) => $m['is_stopped']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($store['name']) ?> メニュー管理</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:"Hiragino Kaku Gothic ProN","Yu Gothic",sans-serif;background:#f5f4ef;color:#1c2024;font-size:15px;line-height:1.6;}
.wrap{max-width:680px;margin:0 auto;padding:16px;}
h1{font-size:18px;font-weight:700;margin-bottom:4px;}
.sub{font-size:13px;color:#7a756b;margin-bottom:20px;}
.card{background:#fff;border:1px solid #e3ddd0;border-radius:12px;padding:20px;margin-bottom:16px;}
.card h2{font-size:15px;font-weight:700;margin-bottom:14px;}

/* ログインフォーム */
.login-wrap{max-width:360px;margin:60px auto;}
.login-wrap h1{margin-bottom:20px;}
input[type=password],input[type=text],input[type=number]{
  width:100%;padding:10px 12px;border:1px solid #e3ddd0;border-radius:8px;
  font-size:15px;background:#fff;
}
input:focus{outline:2px solid #2f6f4f;border-color:#2f6f4f;}
.btn{display:inline-block;padding:11px 20px;border-radius:9px;border:none;
  font-size:15px;font-weight:700;cursor:pointer;}
.btn-primary{background:#2f6f4f;color:#fff;width:100%;margin-top:10px;}
.btn-primary:hover{background:#256040;}
.btn-warn{background:#b4541f;color:#fff;}
.btn-warn:hover{background:#9a4418;}
.btn-sm{padding:6px 12px;font-size:13px;border-radius:7px;border:1px solid #e3ddd0;background:#fff;cursor:pointer;}
.btn-sm:hover{background:#f0ece2;}
.btn-stop{border-color:#b4541f;color:#b4541f;}
.btn-stop.active{background:#b4541f;color:#fff;border-color:#b4541f;}
.error{background:#fde8df;color:#b4541f;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:14px;}
.success{background:#e7f0ea;color:#2f6f4f;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:14px;}

/* 提供中止バナー */
.stop-banner{background:#fff3e0;border:1px solid #f5a623;border-radius:10px;padding:12px 16px;
  margin-bottom:16px;font-size:14px;color:#7a4000;}

/* メニュー行 */
.menu-item{border:1px solid #e3ddd0;border-radius:10px;padding:14px;margin-bottom:10px;background:#fff;position:relative;}
.menu-item.stopped{background:#fff8f5;border-color:#f5c4a0;}
.menu-item .row{display:flex;gap:8px;align-items:center;margin-bottom:8px;}
.menu-item .row:last-child{margin-bottom:0;}
.menu-item input{flex:1;}
.menu-item input.w-price{flex:0 0 90px;}
.menu-item .label{font-size:12px;color:#7a756b;white-space:nowrap;min-width:60px;}
.menu-item .actions{display:flex;gap:6px;align-items:center;}
.drag-handle{cursor:grab;color:#bbb;font-size:20px;padding:0 4px;}
.drag-handle:active{cursor:grabbing;}
.item-num{font-size:12px;color:#bbb;min-width:20px;}

/* 税率 */
.tax-row{display:flex;align-items:center;gap:10px;}
.tax-row input{width:80px;}

/* ヘッダー */
.header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;}
.logout-link{font-size:13px;color:#7a756b;text-decoration:none;}
.logout-link:hover{color:#c00;}

/* 保存バー */
.save-bar{position:sticky;bottom:0;background:rgba(245,244,239,.96);
  backdrop-filter:blur(6px);border-top:1px solid #e3ddd0;
  padding:12px 16px;display:flex;gap:10px;}
.save-bar .btn-primary{flex:1;margin-top:0;}
</style>
</head>
<body>
<div class="wrap">

<?php if (!$logged_in): ?>
<!-- ===== ログイン画面 ===== -->
<div class="login-wrap">
  <h1><?= htmlspecialchars($store['name']) ?></h1>
  <p class="sub">メニュー管理画面</p>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
    <input type="password" name="password" placeholder="パスワード" autofocus>
    <button type="submit" class="btn btn-primary">ログイン</button>
  </form>
</div>

<?php else: ?>
<!-- ===== 管理画面 ===== -->
<div class="header">
  <div>
    <h1><?= htmlspecialchars($store['name']) ?> メニュー管理</h1>
    <p class="sub">変更後は「保存する」を押してください</p>
  </div>
  <a href="?slug=<?= urlencode($slug) ?>&logout=1" class="logout-link">ログアウト</a>
</div>

<?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($saved): ?><div class="success">保存しました</div><?php endif; ?>

<?php if ($has_stopped): ?>
<div class="stop-banner">
  ⚠️ 提供中止のメニューがあります：
  <?= implode('・', array_map(fn($m)=>htmlspecialchars($m['name']), $has_stopped)) ?>
</div>
<?php endif; ?>

<form method="post" id="menuForm">
  <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
  <input type="hidden" name="save_menu" value="1">

  <!-- 税率 -->
  <div class="card">
    <h2>消費税率</h2>
    <div class="tax-row">
      <input type="number" name="tax_rate" value="<?= htmlspecialchars($store['tax_rate']) ?>"
        min="0" max="30" step="0.1" inputmode="decimal">
      <span>%（価格は税込で入力）</span>
    </div>
  </div>

  <!-- メニュー一覧 -->
  <div class="card">
    <h2>メニュー一覧</h2>
    <div id="menuList">
    <?php foreach ($menus as $i => $m):
      $stopped = $m['is_stopped'] ? 'stopped' : '';
      // aliasesから品名を除いた呼び名を表示
      $aliasArr = array_filter(array_map('trim', explode(',', $m['aliases'])));
      $aliasArr = array_values(array_filter($aliasArr, fn($a)=>$a !== $m['name']));
      $aliasStr = implode(',', $aliasArr);
    ?>
    <div class="menu-item <?= $stopped ?>" data-index="<?= $i ?>">
      <div class="row">
        <span class="drag-handle" title="ドラッグで並び替え">⠿</span>
        <span class="item-num"><?= $i+1 ?></span>
        <input type="text" name="name[]" value="<?= htmlspecialchars($m['name']) ?>" placeholder="品名" required>
        <input type="number" class="w-price" name="price[]" value="<?= $m['price'] ?>"
          placeholder="価格" min="0" inputmode="numeric">
        <span style="font-size:13px;color:#7a756b;">円</span>
      </div>
      <div class="row">
        <span class="label">読みがな</span>
        <input type="text" name="reading[]" value="<?= htmlspecialchars($m['reading']) ?>"
          placeholder="ひらがな・カタカナ">
      </div>
      <div class="row">
        <span class="label">呼び名</span>
        <input type="text" name="aliases[]" value="<?= htmlspecialchars($aliasStr) ?>"
          placeholder="カンマ区切り（例：ビール,なま）">
      </div>
      <div class="row actions">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
          <input type="checkbox" name="is_stopped[<?= $i ?>]" value="1"
            <?= $m['is_stopped'] ? 'checked' : '' ?>>
          本日提供中止
        </label>
        <button type="button" class="btn btn-sm" style="margin-left:auto;color:#c00;"
          onclick="removeItem(this)">削除</button>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

    <button type="button" class="btn btn-sm" onclick="addItem()"
      style="margin-top:12px;width:100%;text-align:center;">＋ 品目を追加</button>
  </div>

  <div class="save-bar">
    <button type="submit" class="btn btn-primary">保存する</button>
  </div>
</form>

<script>
let itemCount = <?= count($menus) ?>;

function addItem(){
  const i = itemCount++;
  const div = document.createElement('div');
  div.className = 'menu-item';
  div.dataset.index = i;
  div.innerHTML = `
    <div class="row">
      <span class="drag-handle">⠿</span>
      <span class="item-num">${i+1}</span>
      <input type="text" name="name[]" placeholder="品名" required>
      <input type="number" class="w-price" name="price[]" placeholder="価格" min="0" inputmode="numeric">
      <span style="font-size:13px;color:#7a756b;">円</span>
    </div>
    <div class="row">
      <span class="label">読みがな</span>
      <input type="text" name="reading[]" placeholder="ひらがな・カタカナ">
    </div>
    <div class="row">
      <span class="label">呼び名</span>
      <input type="text" name="aliases[]" placeholder="カンマ区切り（例：ビール,なま）">
    </div>
    <div class="row actions">
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
        <input type="checkbox" name="is_stopped[${i}]" value="1">
        本日提供中止
      </label>
      <button type="button" class="btn btn-sm" style="margin-left:auto;color:#c00;"
        onclick="removeItem(this)">削除</button>
    </div>`;
  document.getElementById('menuList').appendChild(div);
  div.querySelector('input[name="name[]"]').focus();
  updateNumbers();
}

function removeItem(btn){
  if(!confirm('この品目を削除しますか？')) return;
  btn.closest('.menu-item').remove();
  updateNumbers();
}

function updateNumbers(){
  document.querySelectorAll('.menu-item').forEach((el,i)=>{
    el.querySelector('.item-num').textContent = i+1;
  });
}

// ドラッグ＆ドロップによる並び替え（簡易実装）
let dragging = null;
document.getElementById('menuList').addEventListener('dragstart', e=>{
  dragging = e.target.closest('.menu-item');
  if(dragging) dragging.style.opacity='.4';
});
document.getElementById('menuList').addEventListener('dragend', e=>{
  if(dragging){ dragging.style.opacity=''; dragging=null; updateNumbers(); }
});
document.getElementById('menuList').addEventListener('dragover', e=>{
  e.preventDefault();
  const target = e.target.closest('.menu-item');
  if(target && dragging && target !== dragging){
    const list = document.getElementById('menuList');
    const items = [...list.children];
    const fromIdx = items.indexOf(dragging);
    const toIdx   = items.indexOf(target);
    if(fromIdx < toIdx) list.insertBefore(dragging, target.nextSibling);
    else list.insertBefore(dragging, target);
  }
});
document.querySelectorAll('.drag-handle').forEach(h=>{
  h.closest('.menu-item').draggable = true;
});
</script>
<?php endif; ?>

</div><!-- /wrap -->
</body>
</html>
