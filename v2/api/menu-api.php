<?php
/**
 * menu-api.php
 * アプリとメニュー管理画面が使うAPI。
 *
 * GET  ?slug=xK9mP2qR&action=get_menu   → メニュー一覧と店情報を返す
 * POST action=save_menu                  → メニューを保存（パスワード認証要）
 * POST action=toggle_stop                → 提供中止フラグを切り替え（認証要）
 */

require __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : '';

if ($method === 'POST') {
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body)) json_fail('JSONの読み取りに失敗しました');
  $action = $body['action'] ?? '';
}

/* ===== GET: メニュー取得 ===== */
if ($method === 'GET' && $action === 'get_menu') {
  $slug = trim($_GET['slug'] ?? '');
  if ($slug === '') json_fail('slugが必要です');

  $store = get_store_by_slug($slug);
  if (!$store) json_fail('店が見つかりません', 404);

  $st = get_db()->prepare(
    'SELECT id, name, reading, price, aliases, sort_order, is_stopped
     FROM menus WHERE store_id = ? ORDER BY sort_order ASC, id ASC'
  );
  $st->execute([$store['id']]);
  $menus = $st->fetchAll();

  // aliasesをカンマ区切り文字列 → 配列に変換
  foreach ($menus as &$m) {
    $m['aliases'] = $m['aliases'] !== ''
      ? array_map('trim', explode(',', $m['aliases']))
      : [$m['name']];
    $m['is_stopped'] = (bool)$m['is_stopped'];
  }
  unset($m);

  json_ok([
    'store' => [
      'name'     => $store['name'],
      'tax_rate' => (float)$store['tax_rate'],
    ],
    'menus' => $menus,
  ]);
}

/* ===== POST: メニュー保存 ===== */
if ($method === 'POST' && $action === 'save_menu') {
  $slug     = trim($body['slug'] ?? '');
  $password = $body['password'] ?? '';
  $menus    = $body['menus'] ?? [];
  $tax_rate = isset($body['tax_rate']) ? (float)$body['tax_rate'] : null;

  if ($slug === '') json_fail('slugが必要です');

  $store = get_store_by_slug($slug);
  if (!$store) json_fail('店が見つかりません', 404);
  if (!password_verify($password, $store['password'])) json_fail('パスワードが違います', 401);
  if (!is_array($menus)) json_fail('menusが必要です');

  $db = get_db();
  $db->beginTransaction();
  try {
    // 既存メニューを全削除して入れ直す（シンプル方式）
    $db->prepare('DELETE FROM menus WHERE store_id = ?')->execute([$store['id']]);

    $ins = $db->prepare(
      'INSERT INTO menus (store_id, name, reading, price, aliases, sort_order, is_stopped)
       VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($menus as $i => $m) {
      $name    = trim($m['name'] ?? '');
      if ($name === '') continue;
      $reading = trim($m['reading'] ?? '');
      $price   = max(0, intval($m['price'] ?? 0));
      $aliases = trim($m['aliases'] ?? '');
      // aliasesに品名が含まれていなければ先頭に追加
      $aliasArr = array_filter(array_map('trim', explode(',', $aliases)));
      if (!in_array($name, $aliasArr)) array_unshift($aliasArr, $name);
      $aliasStr = implode(',', $aliasArr);
      $stopped  = isset($m['is_stopped']) && $m['is_stopped'] ? 1 : 0;
      $ins->execute([$store['id'], $name, $reading, $price, $aliasStr, $i, $stopped]);
    }

    // 税率も更新
    if ($tax_rate !== null && $tax_rate >= 0 && $tax_rate <= 30) {
      $db->prepare('UPDATE stores SET tax_rate = ? WHERE id = ?')
         ->execute([$tax_rate, $store['id']]);
    }

    $db->commit();
    json_ok(['message' => '保存しました']);
  } catch (Exception $e) {
    $db->rollBack();
    json_fail('保存に失敗しました: ' . $e->getMessage(), 500);
  }
}

/* ===== POST: 提供中止フラグ切り替え ===== */
if ($method === 'POST' && $action === 'toggle_stop') {
  $slug      = trim($body['slug'] ?? '');
  $password  = $body['password'] ?? '';
  $menu_id   = intval($body['menu_id'] ?? 0);
  $is_stopped = isset($body['is_stopped']) ? (bool)$body['is_stopped'] : null;

  if ($slug === '') json_fail('slugが必要です');
  if ($menu_id <= 0) json_fail('menu_idが必要です');
  if ($is_stopped === null) json_fail('is_stoppedが必要です');

  $store = get_store_by_slug($slug);
  if (!$store) json_fail('店が見つかりません', 404);
  if (!password_verify($password, $store['password'])) json_fail('パスワードが違います', 401);

  // そのメニューがこの店のものか確認してから更新
  $st = get_db()->prepare(
    'UPDATE menus SET is_stopped = ? WHERE id = ? AND store_id = ?'
  );
  $st->execute([$is_stopped ? 1 : 0, $menu_id, $store['id']]);

  if ($st->rowCount() === 0) json_fail('メニューが見つかりません', 404);
  json_ok(['message' => $is_stopped ? '提供中止にしました' : '提供再開しました']);
}

json_fail('不明なアクションです');
