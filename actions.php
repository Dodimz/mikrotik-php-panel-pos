<?php
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo "Method Not Allowed"; exit; }
$config = require __DIR__ . '/config.php';
require __DIR__ . '/api/RouterOSAPI.php';
function redirect_home($m=null){ header('Location: index.php'.($m?('?m='.urlencode($m)):'') ); exit; }
function mik_login(){ global $config; $api=new RouterOSAPI($config['host'],$config['port'],$config['timeout']); $api->connect(); $api->login($config['user'],$config['pass']); return $api; }
$action=$_POST['action']??''; $pricePerHour=intval($config['price_per_hour']??0);

function ensure_rule_order($api){
  $rules=$api->print('/ip/firewall/filter'); $dropId=null;
  foreach($rules as $r){ if(($r['chain']??'')==='forward' && ($r['action']??'')==='drop' && strpos(($r['comment']??''),'WebPanel Drop All Others')!==false){ $dropId=$r['.id']??null; } }
  if($dropId){ $api->remove('/ip/firewall/filter',$dropId); }
  $haveAllowClients=false; $haveAllowAdmin=false;
  foreach($api->print('/ip/firewall/filter') as $r){
    if(($r['chain']??'')==='forward' && ($r['action']??'')==='accept' && ($r['src-address-list']??'')==='INTERNET_ALLOWED'){ $haveAllowClients=true; }
    if(($r['chain']??'')==='forward' && ($r['action']??'')==='accept' && ($r['src-address-list']??'')==='ADMIN_PC'){ $haveAllowAdmin=true; }
  }
  if(!$haveAllowClients){ $api->add('/ip/firewall/filter',['chain'=>'forward','src-address-list'=>'INTERNET_ALLOWED','action'=>'accept','comment'=>'WebPanel Allow Clients']); }
  if(!$haveAllowAdmin){ $api->add('/ip/firewall/filter',['chain'=>'forward','src-address-list'=>'ADMIN_PC','action'=>'accept','comment'=>'WebPanel Always Allow Admin']); }
  $api->add('/ip/firewall/filter',['chain'=>'forward','action'=>'drop','comment'=>'WebPanel Drop All Others']);
}

try{
  $api=mik_login();

  if($action==='enforce_default_block_admin'){
    $admin_ip = trim($config['admin_ip'] ?? '');
    if($admin_ip===''){ $api->close(); redirect_home('No admin_ip in config.php.'); }
    $adminExists=false;
    foreach($api->print('/ip/firewall/address-list',['list'=>'ADMIN_PC','address'=>$admin_ip]) as $r){ if(!empty($r['.id'])){ $adminExists=true; } }
    if(!$adminExists){ $api->add('/ip/firewall/address-list',['list'=>'ADMIN_PC','address'=>$admin_ip,'comment'=>'Admin PC']); }
    ensure_rule_order($api);
    $api->close(); redirect_home('Default block enforced. Admin: '.$admin_ip);
  }

  if($action==='allow_now_ip'){
    $ip=trim($_POST['ip']??''); $h=intval($_POST['hours']??2); if(!$ip) throw new Exception('IP required'); if($h<1)$h=1;
    foreach($api->print('/ip/firewall/address-list',['list'=>'INTERNET_ALLOWED','address'=>$ip]) as $r){ if(!empty($r['.id'])) $api->remove('/ip/firewall/address-list',$r['.id']); }
    $comment='wp|start='.time().'|hours='.$h.'|rate='.$pricePerHour;
    $api->add('/ip/firewall/address-list',['list'=>'INTERNET_ALLOWED','address'=>$ip,'timeout'=>$h.'h','comment'=>$comment]);
    $api->close(); redirect_home('IP '.$ip.' allowed for '.$h.' h');
  }

  if($action==='block_now_ip'){
    $ip=trim($_POST['ip']??''); if(!$ip) throw new Exception('IP required'); $cnt=0;
    foreach($api->print('/ip/firewall/address-list',['list'=>'INTERNET_ALLOWED','address'=>$ip]) as $r){ if(!empty($r['.id'])){ $api->remove('/ip/firewall/address-list',$r['.id']); $cnt++; } }
    $api->close(); redirect_home('IP '.$ip.' blocked (removed '.$cnt.' entries)');
  }

  if($action==='create_window_ip'){
    $ip=trim($_POST['ip']??''); $start=$_POST['start_dt']??''; $end=$_POST['end_dt']??'';
    if(!$ip||!$start||!$end) throw new Exception('IP, start, end required');
    $startDT = date_create($start); $endDT = date_create($end);
    if(!$startDT || !$endDT) throw new Exception('Invalid datetime');
    $start_ts = $startDT->getTimestamp(); $end_ts = $endDT->getTimestamp();
    $dur = $end_ts - $start_ts; if($dur <= 0) throw new Exception('End must be after Start');
    $hours = (int)ceil($dur / 3600);
    foreach($api->print('/system/scheduler') as $s){
      if(!empty($s['name']) && ( $s['name']==='wp-win-'.$ip.'-start' || $s['name']==='wp-win-'.$ip.'-end') && !empty($s['.id']) ){
        $api->remove('/system/scheduler',$s['.id']);
      }
    }
    $start_date = date('Y-m-d',$start_ts); $start_time = date('H:i:s',$start_ts);
    $end_date   = date('Y-m-d',$end_ts);   $end_time   = date('H:i:s',$end_ts);
    $onStart = '/ip firewall address-list remove [find where list="INTERNET_ALLOWED" and address="'.$ip.'"]; '
             . '/ip firewall address-list add list=INTERNET_ALLOWED address='.$ip.' timeout='.$dur.'s comment="wp|start_ts='.$start_ts.'|end_ts='.$end_ts.'|hours='.$hours.'|rate='.$pricePerHour.'"';
    $onEnd   = '/ip firewall address-list remove [find where list="INTERNET_ALLOWED" and address="'.$ip.'"]';
    $schedComment = 'wpwin|ip='.$ip.'|hours='.$hours.'|rate='.$pricePerHour;
    $api->add('/system/scheduler',['name'=>'wp-win-'.$ip.'-start','start-date'=>$start_date,'start-time'=>$start_time,'interval'=>'0s','on-event'=>$onStart,'comment'=>$schedComment,'disabled'=>'no']);
    $api->add('/system/scheduler',['name'=>'wp-win-'.$ip.'-end','start-date'=>$end_date,'start-time'=>$end_time,'interval'=>'0s','on-event'=>$onEnd,'comment'=>$schedComment,'disabled'=>'no']);
    $api->close(); redirect_home('Window set for '.$ip);
  }

  $api->close(); redirect_home('Unknown action');
}catch(Exception $e){
  redirect_home('Error: '.$e->getMessage());
}
