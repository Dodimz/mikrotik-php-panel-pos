<?php
class RouterOSAPI {
    private $host, $port, $timeout, $socket;
    public function __construct($h,$p=8728,$t=5){ $this->host=$h;$this->port=$p;$this->timeout=$t; }
    public function connect(){ $this->socket=fsockopen($this->host,$this->port,$e,$s,$this->timeout); if(!$this->socket) throw new Exception("API connect failed: $s ($e)"); stream_set_timeout($this->socket,$this->timeout); }
    public function login($u,$p){ $this->writeSentence(['/login','=name='.$u,'=password='.$p]); $r=$this->readSentence(); if(!isset($r[0])||$r[0]!=='!done'){ throw new Exception('Login failed'); } }
    public function close(){ if($this->socket) fclose($this->socket); $this->socket=null; }
    private function writeLen($l){ if($l<0x80){fwrite($this->socket,chr($l));} elseif($l<0x4000){$l|=0x8000;fwrite($this->socket,chr(($l>>8)&0xFF));fwrite($this->socket,chr($l&0xFF));} elseif($l<0x200000){$l|=0xC00000;fwrite($this->socket,chr(($l>>16)&0xFF));fwrite($this->socket,chr(($l>>8)&0xFF));fwrite($this->socket,chr($l&0xFF));} elseif($l<0x10000000){$l|=0xE0000000;fwrite($this->socket,chr(($l>>24)&0xFF));fwrite($this->socket,chr(($l>>16)&0xFF));fwrite($this->socket,chr(($l>>8)&0xFF));fwrite($this->socket,chr($l&0xFF));} else {fwrite($this->socket,chr(0xF0));fwrite($this->socket,chr(($l>>24)&0xFF));fwrite($this->socket,chr(($l>>16)&0xFF));fwrite($this->socket,chr(($l>>8)&0xFF));fwrite($this->socket,chr($l&0xFF));} }
    private function readLen(){ $c=ord(fread($this->socket,1)); if($c<0x80) return $c; if(($c&0xC0)==0x80){$c2=ord(fread($this->socket,1)); return (($c&~0xC0) << 8) + $c2;} if(($c&0xE0)==0xC0){$c2=ord(fread($this->socket,1));$c3=ord(fread($this->socket,1)); return (($c&~0xE0) << 16) + ($c2 << 8) + $c3;} if(($c&0xF0)==0xE0){$c2=ord(fread($this->socket,1));$c3=ord(fread($this->socket,1));$c4=ord(fread($this->socket,1)); return (($c&~0xF0) << 24) + ($c2 << 16) + ($c3 << 8) + $c4;} if($c==0xF0){$c1=ord(fread($this->socket,1));$c2=ord(fread($this->socket,1));$c3=ord(fread($this->socket,1));$c4=ord(fread($this->socket,1)); return ($c1<<24)+($c2<<16)+($c3<<8)+$c4;} return 0; }
    private function writeWord($w){ $this->writeLen(strlen($w)); fwrite($this->socket,$w); }
    private function readWord(){ $l=$this->readLen(); if($l==0) return ''; $w=''; while(strlen($w)<$l){ $w.=fread($this->socket,$l-strlen($w)); } return $w; }
    public function writeSentence($words){ foreach($words as $w) $this->writeWord($w); $this->writeWord(''); }
    public function readSentence(){ $r=[]; while(true){ $w=$this->readWord(); if($w==='') break; $r[]=$w; } return $r; }
    public function talk($path,$attrs=[]){ $words=[$path]; foreach($attrs as $k=>$v){ if(is_int($k)) $words[]=$v; else { if($k[0] !== '=') $k='='.$k; $words[]=$k.'='.$v; } } $this->writeSentence($words); $reply=[]; while(true){ $r=$this->readSentence(); if(!$r) break; $reply[]=$r; if($r[0]==='!done') break; } return $reply; }
    public function print($path,$where=[]){ $words=[$path.'/print']; foreach($where as $k=>$v) $words[]='?'.$k.'='.$v; $this->writeSentence($words); $rows=[]; while(true){ $r=$this->readSentence(); if(!$r) break; if($r[0]==='!re'){ $row=[]; foreach($r as $w){ if(strpos($w,'=')===0){ $p=explode('=',$w,3); if(count($p)===3) $row[$p[1]]=$p[2]; } } $rows[]=$row; } elseif($r[0]==='!done'){ break; } } return $rows; }
    public function add($path,$attrs){ return $this->talk($path.'/add',$attrs); }
    public function remove($path,$id){ return $this->talk($path.'/remove',['=.id'=>$id]); }
    public function set($path,$id,$attrs){ $attrs['.id']=$id; return $this->talk($path.'/set',$attrs); }
    public function enable($path,$id){ return $this->talk($path.'/enable',['=.id'=>$id]); }
    public function disable($path,$id){ return $this->talk($path.'/disable',['=.id'=>$id]); }
}
