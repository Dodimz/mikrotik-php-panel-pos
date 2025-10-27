<?php
header('Content-Type: text/plain; charset=utf-8');
$CONFIG = require __DIR__ . '/config.php';
$dataFile = __DIR__ . '/data/orders.json';

function read_json($path){
  if(!file_exists($path)) return [];
  $raw = file_get_contents($path);
  if(!$raw) return [];
  $j = json_decode($raw, true);
  return is_array($j)? $j : [];
}
function write_json($path,$arr){
  $tmp = $path . '.tmp';
  file_put_contents($tmp, json_encode($arr, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  rename($tmp, $path);
}
function load_menu(){
  $m = json_decode(file_get_contents(__DIR__ . '/data/menu.json'), true);
  return $m['items'] ?? [];
}
function price_of($id){
  foreach(load_menu() as $it){ if($it['id']===$id) return intval($it['price']); }
  return 0;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$ip = trim($_POST['ip'] ?? $_GET['ip'] ?? '');

if($action==='list'){
  $orders = read_json($dataFile);
  $out = $orders[$ip] ?? [];
  header('Content-Type: application/json');
  echo json_encode($out);
  exit;
}

if($action==='add'){
  $item_id = trim($_POST['item_id'] ?? '');
  $qty = intval($_POST['qty'] ?? 1);
  if(!$ip || !$item_id || $qty<1){ http_response_code(400); echo "ip, item_id, qty required"; exit; }
  $orders = read_json($dataFile);
  if(!isset($orders[$ip])) $orders[$ip] = ['items'=>[], 'note'=>''];
  // merge if same id exists
  $merged = false;
  foreach($orders[$ip]['items'] as &$row){
    if($row['id']===$item_id){ $row['qty'] += $qty; $merged = true; break; }
  }
  if(!$merged){
    $orders[$ip]['items'][] = ['id'=>$item_id, 'qty'=>$qty, 'price'=>price_of($item_id)];
  }
  write_json($dataFile, $orders);
  header('Location: index.php?m='.urlencode('Added item '.$item_id.' for '.$ip));
  exit;
}

if($action==='remove'){
  $item_id = trim($_POST['item_id'] ?? '');
  if(!$ip || !$item_id){ http_response_code(400); echo "ip, item_id required"; exit; }
  $orders = read_json($dataFile);
  if(isset($orders[$ip])){
    $orders[$ip]['items'] = array_values(array_filter($orders[$ip]['items'], function($r) use ($item_id){ return $r['id'] !== $item_id; }));
    write_json($dataFile, $orders);
  }
  header('Location: index.php?m='.urlencode('Removed item '.$item_id.' for '.$ip));
  exit;
}

if($action==='clear'){
  $orders = read_json($dataFile);
  unset($orders[$ip]);
  write_json($dataFile, $orders);
  header('Location: index.php?m='.urlencode('Cleared order for '.$ip));
  exit;
}

if($action==='note'){
  $note = trim($_POST['note'] ?? '');
  $orders = read_json($dataFile);
  if(!isset($orders[$ip])) $orders[$ip] = ['items'=>[], 'note'=>''];
  $orders[$ip]['note'] = $note;
  write_json($dataFile, $orders);
  header('Location: index.php?m='.urlencode('Saved note for '.$ip));
  exit;
}

if($action==='export_csv'){
  $orders = read_json($dataFile);
  $order = $orders[$ip] ?? ['items'=>[]];
  $filename = 'receipt_'.$ip.'_'.date('Ymd_His').'.csv';
  $path = __DIR__ . '/data/'.$filename;
  $fp = fopen($path, 'w');
  fputcsv($fp, ['IP', $ip]);
  fputcsv($fp, []);
  fputcsv($fp, ['Item ID', 'Name', 'Qty', 'Price', 'Subtotal']);
  $menu = load_menu();
  $byid = []; foreach($menu as $it){ $byid[$it['id']]=$it; }
  $total = 0;
  foreach($order['items'] as $row){
    $name = $byid[$row['id']]['name'] ?? $row['id'];
    $price = intval($row['price'] ?? 0);
    $qty = intval($row['qty'] ?? 0);
    $sub = $price * $qty;
    $total += $sub;
    fputcsv($fp, [$row['id'], $name, $qty, $price, $sub]);
  }
  fputcsv($fp, []);
  fputcsv($fp, ['Items Total', $total]);
  fclose($fp);
  header('Location: data/'.$filename);
  exit;
}

echo "Unknown action";