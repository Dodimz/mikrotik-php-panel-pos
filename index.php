<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/api/RouterOSAPI.php';

function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function mik_login(){ global $config; $api=new RouterOSAPI($config['host'],$config['port'],$config['timeout']); $api->connect(); $api->login($config['user'],$config['pass']); return $api; }
function ros_time_to_seconds($ros){ if(!$ros) return 0; $t=0; if(preg_match('/(\d+)d/',$ros,$m)) $t+=intval($m[1])*86400; if(preg_match('/(\d+)h/',$ros,$m)) $t+=intval($m[1])*3600; if(preg_match('/(\d+)m/',$ros,$m)) $t+=intval($m[1])*60; if(preg_match('/(\d+)s/',$ros,$m)) $t+=intval($m[1]); return $t; }
function read_json($path){ if(!file_exists($path)) return []; $raw=file_get_contents($path); if(!$raw) return []; $j=json_decode($raw,true); return is_array($j)?$j:[]; }

$err=null;$leases=[];$addrList=[];$schedules=[];
try{
  $api=mik_login();
  $leases=$api->print('/ip/dhcp-server/lease');
  $addrList=$api->print('/ip/firewall/address-list',['list'=>'INTERNET_ALLOWED']);
  $schedules=$api->print('/system/scheduler');
  $api->close();
}catch(Exception $e){ $err=$e->getMessage(); }

$allowedIPs=[]; $ipRemaining=[]; $ipPriceInfo=[];
foreach($addrList as $a){
  if(!empty($a['address'])){
    $allowedIPs[$a['address']]=true;
    $ipRemaining[$a['address']]=ros_time_to_seconds($a['timeout']??'');
    if(!empty($a['comment']) && strpos($a['comment'],'wp|')===0) $ipPriceInfo[$a['address']]=$a['comment'];
  }
}
$prePrice=[];
foreach($schedules as $s){
  $c=$s['comment'] ?? '';
  if($c && strpos($c,'wpwin|')===0){
    $parts = explode('|',$c);
    $meta=[]; foreach($parts as $p){ if(strpos($p,'=')!==false){ list($k,$v)=explode('=',$p,2); $meta[$k]=$v; } }
    $ip=$meta['ip'] ?? null;
    if($ip){ $prePrice[$ip]=['hours'=>intval($meta['hours']??0), 'rate'=>intval($meta['rate']??($config['price_per_hour']??0))]; }
  }
}
$currency=$config['currency']??''; $rate=intval($config['price_per_hour']??0);
$taxP = floatval($config['tax_percent'] ?? 0);
$svcP = floatval($config['service_percent'] ?? 0);
$menu = json_decode(file_get_contents(__DIR__.'/data/menu.json'), true)['items'] ?? [];
$menuById=[]; foreach($menu as $it){ $menuById[$it['id']]=$it; }
$orders = read_json(__DIR__.'/data/orders.json');
?><!doctype html>
<html>
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MikroTik Panel — Internet + F&B Billing</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:20px;max-width:1350px}
    table{border-collapse:collapse;width:100%} th,td{border:1px solid #ddd;padding:8px;font-size:13px} th{background:#f5f5f5}
    .card{border:1px solid #e5e5e5;border-radius:12px;padding:16px;margin:10px 0;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    .btn{padding:6px 10px;border:1px solid #ccc;border-radius:8px;background:#fafafa;text-decoration:none;color:#111;font-size:12px;cursor:pointer}
    .btn:hover{background:#f0f0f0}.btn.danger{border-color:#d33;color:#b00}.btn.primary{border-color:#2684ff}
    .ok{color:#080}.muted{color:#666}.small{font-size:12px}.countdown{font-variant-numeric:tabular-nums}
    form.inline{display:inline}
    .tbl-min td, .tbl-min th{font-size:12px;padding:6px}
  </style>
</head>
<body>
  <h1>MikroTik Internet + F&B</h1>
  <?php if(!empty($_GET['m'])): ?><div class="card small"><?= esc($_GET['m']) ?></div><?php endif; ?>
  <?php if($err): ?><div class="card" style="border-color:#ffb3b3;color:#900"><strong>Connection error:</strong> <?= esc($err) ?></div><?php endif; ?>

  <div class="card">
    <h3>Menu</h3>
    <table class="tbl-min"><thead><tr><th>ID</th><th>Item</th><th>Price</th></tr></thead><tbody>
      <?php foreach($menu as $it): ?><tr><td><?= esc($it['id']) ?></td><td><?= esc($it['name']) ?></td><td><?= esc($currency.number_format($it['price'])) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>

  <div class="card">
    <h3>Leases / Orders</h3>
    <table>
      <thead>
        <tr>
          <th>Nama PC/Room</th><th>IP</th><th>MAC</th>
          <th>Open?</th><th>Time Left</th><th>Room Price</th>
          <th>F&B Items</th><th>Add Item</th><th>Totals</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($leases as $l):
        $ip=$l['address']??''; if(!$ip) continue;
        $mac=strtoupper($l['mac-address']??'');
        $ip_sec=$ipRemaining[$ip]??0;

        // internet price
        $inetPrice='';
        if(isset($ipPriceInfo[$ip])){
          $h=0; if(preg_match('/hours=(\d+)/',$ipPriceInfo[$ip],$m)) $h=intval($m[1]);
          $r=$rate; if(preg_match('/rate=(\d+)/',$ipPriceInfo[$ip],$m2)) $r=intval($m2[1]);
          if($h>0) $inetPrice=$currency.number_format($h*$r);
        } elseif(isset($prePrice[$ip])){
          $h=$prePrice[$ip]['hours']; $r=$prePrice[$ip]['rate'];
          $inetPrice=$currency.number_format($h*$r).' (scheduled)';
        }

        // F&B table
        $cart = $orders[$ip]['items'] ?? [];
        $itemsTotal = 0;
        ob_start();
        echo '<table class="tbl-min"><thead><tr><th>ID</th><th>Name</th><th>Qty</th><th>Price</th><th>Sub</th><th></th></tr></thead><tbody>';
        foreach($cart as $row){
          $id=$row['id']; $qty=intval($row['qty']??0); $price=intval($row['price']??0);
          $name=$menuById[$id]['name'] ?? $id; $sub=$qty*$price; $itemsTotal+=$sub;
          echo '<tr><td>'.esc($id).'</td><td>'.esc($name).'</td><td>'.esc($qty).'</td><td>'.esc($currency.number_format($price)).'</td><td>'.esc($currency.number_format($sub)).'</td><td>';
          echo '<form class="inline" method="post" action="items.php"><input type="hidden" name="action" value="remove"><input type="hidden" name="ip" value="'.esc($ip).'"><input type="hidden" name="item_id" value="'.esc($id).'"><button class="btn danger">x</button></form>';
          echo '</td></tr>';
        }
        if(empty($cart)){ echo '<tr><td colspan="6" class="muted small">No items</td></tr>'; }
        echo '</tbody></table>';
        $itemsTable = ob_get_clean();

        // totals (with tax/service)
        $inetAmt = 0;
        if(preg_match('/(\d+)/', $inetPrice, $m3)){ /* rough parse number */ $inetAmt = 0; }
        // better compute from comments again for accuracy:
        if(isset($ipPriceInfo[$ip])){
          $h=0; if(preg_match('/hours=(\d+)/',$ipPriceInfo[$ip],$m)) $h=intval($m[1]);
          $r=$rate; if(preg_match('/rate=(\d+)/',$ipPriceInfo[$ip],$m2)) $r=intval($m2[1]);
          $inetAmt = $h>0 ? ($h*$r) : 0;
        } elseif(isset($prePrice[$ip])){
          $inetAmt = $prePrice[$ip]['hours'] * $prePrice[$ip]['rate'];
        }
        $subtotal = $inetAmt + $itemsTotal;
        $tax = round($subtotal * ($taxP/100));
        $svc = round($subtotal * ($svcP/100));
        $grand = $subtotal + $tax + $svc;
      ?>
        <tr>
          <td><?= esc($l['host-name']??'') ?></td>
          <td><?= esc($ip) ?></td>
          <td><?= esc($mac) ?></td>
          <td><?= isset($allowedIPs[$ip])?'<span class="ok">Yes</span>':'No' ?></td>
          <td><span class="countdown" data-key="<?= esc($ip) ?>" data-seconds="<?= intval($ip_sec) ?>"></span></td>
          <td><?= esc($inetPrice) ?></td>
          <td><?= $itemsTable ?></td>
          <td>
            <form method="post" action="items.php" class="small">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="ip" value="<?= esc($ip) ?>">
              <select name="item_id"><?php foreach($menu as $it): ?><option value="<?= esc($it['id']) ?>"><?= esc($it['name'].' — '.$currency.number_format($it['price'])) ?></option><?php endforeach; ?></select>
              <input type="number" name="qty" min="1" value="1" style="width:60px">
              <button class="btn">Add</button>
            </form>
            <form method="post" action="items.php" onsubmit="return confirm('Clear all items for <?= esc($ip) ?>?')" class="small">
              <input type="hidden" name="action" value="clear">
              <input type="hidden" name="ip" value="<?= esc($ip) ?>">
              <button class="btn danger">Clear Items</button>
            </form>
            <form method="post" action="items.php" class="small">
              <input type="hidden" name="action" value="note">
              <input type="hidden" name="ip" value="<?= esc($ip) ?>">
              <input type="text" name="note" placeholder="Catatan / Nama customer" value="<?= esc($orders[$ip]['note'] ?? '') ?>">
              <button class="btn">Save Note</button>
            </form>
          </td>
          <td>
            <div class="small">Items: <strong><?= esc($currency.number_format($itemsTotal)) ?></strong></div>
            <div class="small">Internet: <strong><?= esc($currency.number_format($inetAmt)) ?></strong></div>
            <?php if($taxP>0): ?><div class="small">Tax (<?= esc($taxP) ?>%): <strong><?= esc($currency.number_format($tax)) ?></strong></div><?php endif; ?>
            <?php if($svcP>0): ?><div class="small">Service (<?= esc($svcP) ?>%): <strong><?= esc($currency.number_format($svc)) ?></strong></div><?php endif; ?>
            <div class="small"><strong>Grand Total: <?= esc($currency.number_format($grand)) ?></strong></div>
          </td>
          <td>
            <form method="post" action="items.php" class="small">
              <input type="hidden" name="action" value="export_csv">
              <input type="hidden" name="ip" value="<?= esc($ip) ?>">
              <button class="btn">Export CSV</button>
            </form>
            <form class="inline" method="post" action="actions.php">
              <input type="hidden" name="action" value="allow_now_ip">
              <input type="hidden" name="ip" value="<?= esc($ip) ?>">
              <select name="hours"><option>1</option><option selected>2</option><option>3</option><option>4</option><option>5</option><option>6</option><option>8</option><option>24</option></select>
              <button class="btn primary" type="submit">Allow (Buka Room)</button>
            </form>
            <form class="inline" method="post" action="actions.php">
              <input type="hidden" name="action" value="block_now_ip">
              <input type="hidden" name="ip" value="<?= esc($ip) ?>">
              <button class="btn danger" type="submit">Block!</button>
            </form>
            <!-- <form method="post" action="actions.php" class="small">
              <input type="hidden" name="action" value="create_window_ip">
              <input type="hidden" name="ip" value="<?= esc($ip) ?>">
              <div class="small">
                <label>Start<br><input type="datetime-local" name="start_dt" required></label>
                <label>End<br><input type="datetime-local" name="end_dt" required></label>
              </div>
              <button class="btn">Schedule Window</button>
            </form> -->
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small muted">Pricing: <?= esc($currency) ?><?= number_format($rate) ?>/jam. Add F&B per IP; totals include optional tax/service from config.</p>
  </div>

<script>
function fmt(s){ s=Math.max(0,Math.floor(s)); const h=Math.floor(s/3600), m=Math.floor((s%3600)/60), se=s%60; return String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(se).padStart(2,'0'); }
const els=[...document.querySelectorAll('.countdown')]; const state={};
els.forEach(el=>{ state[el.dataset.key]=parseInt(el.dataset.seconds||'0',10); el.textContent=fmt(state[el.dataset.key]); });
setInterval(()=>{ els.forEach(el=>{ const k=el.dataset.key; if(state[k]>0){ state[k]--; el.textContent=fmt(state[k]); } }); },1000);
async function refresh(){ try{ const r=await fetch('timefeed.php'); if(!r.ok) return; const data=await r.json(); if(data.ip){ Object.entries(data.ip).forEach(([k,v])=>{ const el=document.querySelector('.countdown[data-key="'+k+'"]'); if(el){ state[k]=parseInt(v||0,10); el.textContent=fmt(state[k]); } }); } }catch(e){} }
setInterval(refresh,60000);
</script>
</body>
</html>
