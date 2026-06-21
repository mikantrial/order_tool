<?php
/**
 * analyze-order.php
 * お客さんの発話テキストをGemini APIで注文に解析して返す。
 */

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('POSTで送ってください', 405);

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) json_fail('JSONの読み取りに失敗しました');

$transcript = isset($input['transcript']) ? trim($input['transcript']) : '';
$menu       = isset($input['menu']) && is_array($input['menu']) ? $input['menu'] : [];

if ($transcript === '') json_fail('発話テキストが空です');
if (count($menu) === 0) json_fail('メニューが空です');

$menuLines  = [];
$validNames = [];
foreach ($menu as $m) {
  $name = trim($m['name'] ?? '');
  if ($name === '') continue;
  if (!empty($m['is_stopped'])) continue;
  $validNames[$name] = true;
  $aliases = isset($m['aliases']) && is_array($m['aliases'])
    ? array_filter(array_map('trim', $m['aliases'])) : [];
  $menuLines[] = count($aliases) > 0
    ? '- ' . $name . '（呼び名: ' . implode('、', $aliases) . '）'
    : '- ' . $name;
}
$menuText = implode("\n", $menuLines);
if ($menuText === '') json_fail('有効なメニューがありません');

$prompt = <<<PROMPT
あなたは飲食店の注文を、お客さんの発話から正確に読み取る係です。
渡された「メニュー」と「発話」を見て、最終的な注文を商品名と数量で出してください。

厳守するルール:
1. メニューに存在する商品だけを対象にする。メニューにないものは一切含めない。
2. 数量は発話全体から「最終的な数」を判断する。言い直しがあれば最後の意図を採用。
   例:「焼き鳥2つ、あ、やっぱり3つで」→ 焼き鳥は3。
3. 取り消しを反映する。「やっぱりいらない」と言われた商品は含めない。
4. 「以上で」「お願いします」「えーと」などの注文でない言葉は無視する。
   スタッフの案内文（「ご注文をどうぞお聞かせください」など）も注文ではないので無視する。
5. 同じ商品が複数回出ても勝手に足し算しない。最終的な意図を読み取る。
6. 数量が言われていない商品は数量1とする。
7. 不明瞭な部分は含めない（推測で増やさない）。

出力は次のJSONだけを返す。説明や前置きは一切書かない。
{"order":[{"name":"正式な商品名","qty":整数}]}
商品が一つもなければ {"order":[]} を返す。
商品名はメニューの正式名（呼び名ではなく）で書くこと。

メニュー:
{$menuText}

発話:
{$transcript}
PROMPT;

// モデル名はお持ちのキーが対応するものに合わせて変更してください
// 例: gemini-2.5-flash, gemini-3.5-flash など
$model   = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash';
$url     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
$payload = [
  'contents' => [
    ['role' => 'user', 'parts' => [['text' => $prompt]]]
  ],
  'generationConfig' => [
    'temperature'     => 0.1,
    'maxOutputTokens' => 15000,
    'responseMimeType'=> 'application/json', // JSONモードで返させる
  ],
];

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => [
    'Content-Type: application/json',
    'x-goog-api-key: ' . GEMINI_API_KEY,
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT    => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) json_fail('AIサーバーへの接続に失敗しました: ' . $curlErr, 502);
if ($httpCode !== 200)   json_fail('AIサーバーエラー（HTTP ' . $httpCode . '）: ' . $response, 502);

$data = json_decode($response, true);
$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
$text = trim($text);
if ($text === '') json_fail('AIから空の応答が返りました', 502);

$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
$text = preg_replace('/\s*```$/', '', $text);
$text = trim($text);

$parsed = json_decode($text, true);

// JSONが途中で切れていた場合、閉じカッコを補完して再パースを試みる
if (!is_array($parsed) || !isset($parsed['order'])) {
  // 末尾に不足している閉じカッコを補う
  $fixed = $text;
  // 途切れた文字列の末尾を削除（最後の完全なオブジェクトまで）
  $fixed = preg_replace('/,\s*\{[^}]*$/', '', $fixed); // 不完全な最後の要素を除去
  // 閉じカッコを補完
  $open_brace  = substr_count($fixed, '{');
  $close_brace = substr_count($fixed, '}');
  $open_bracket  = substr_count($fixed, '[');
  $close_bracket = substr_count($fixed, ']');
  $fixed .= str_repeat('}', max(0, $open_brace - $close_brace));
  $fixed .= str_repeat(']', max(0, $open_bracket - $close_bracket));
  $parsed = json_decode($fixed, true);
}

if (!is_array($parsed) || !isset($parsed['order'])) {
  json_fail('AIの応答を読み取れませんでした: ' . $text, 502);
}

$clean = [];
foreach ($parsed['order'] as $item) {
  $name = trim($item['name'] ?? '');
  $qty  = min(99, max(1, intval($item['qty'] ?? 1)));
  if ($name === '' || !isset($validNames[$name])) continue;
  $clean[$name] = isset($clean[$name]) ? max($clean[$name], $qty) : $qty;
}

$order = array_map(fn($n, $q) => ['name' => $n, 'qty' => $q], array_keys($clean), $clean);
json_ok(['order' => $order]);
