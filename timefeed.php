<?php
header('Content-Type: application/json');
$config = require __DIR__ . '/config.php';
require __DIR__ . '/api/RouterOSAPI.php';
function ros_time_to_seconds($ros){ if(!$ros) return 0; $t=0; if(preg_match('/(\d+)d/',$ros,$m)) $t+=intval($m[1])*86400; if(preg_match('/(\d+)h/',$ros,$m)) $t+=intval($m[1])*3600; if(preg_match('/(\d+)m/',$ros,$m)) $t+=intval($m[1])*60; if(preg_match('/(\d+)s/',$ros,$m)) $t+=intval($m[1]); return $t; }
try{
  $api=new RouterOSAPI($config['host'],$config['port'],$config['timeout']); $api->connect(); $api->login($config['user'],$config['pass']);
  $ipMap=[]; foreach($api->print('/ip/firewall/address-list',['list'=>'INTERNET_ALLOWED']) as $a){ if(!empty($a['address'])) $ipMap[$a['address']]=ros_time_to_seconds($a['timeout']??''); }
  $api->close(); echo json_encode(['ip'=>$ipMap]);
}catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
