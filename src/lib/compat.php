<?php
// Fallbacks for PHP extensions that some shared hosts leave out (zip, zlib, mbstring).
if (!defined('APP')) { http_response_code(404); exit; }

// ---------- mbstring ----------
if (!function_exists('mb_strlen')) {
  function _mb_chars($s) { $a = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY); return $a === false ? str_split((string)$s) : $a; }
  function mb_strlen($s, $enc = null) { return count(_mb_chars($s)); }
  function mb_substr($s, $start, $len = null, $enc = null) { $c = _mb_chars($s); return implode('', $len === null ? array_slice($c, $start) : array_slice($c, $start, $len)); }
  function mb_strtolower($s, $enc = null) { return strtolower((string)$s); }
  function mb_strtoupper($s, $enc = null) { return strtoupper((string)$s); }
  function mb_strimwidth($s, $start, $width, $trim = '', $enc = null) {
    $s = mb_substr($s, $start);
    return mb_strlen($s) <= $width ? $s : mb_substr($s, 0, max(0, $width - mb_strlen($trim))) . $trim;
  }
}

// ---------- pure-PHP DEFLATE decoder (used only when zlib is missing) ----------
class PhpInflate {
  private $d; private $p = 0; private $n; private $bb = 0; private $bc = 0; private $out = '';
  const LBASE = [3,4,5,6,7,8,9,10,11,13,15,17,19,23,27,31,35,43,51,59,67,83,99,115,131,163,195,227,258];
  const LEXT = [0,0,0,0,0,0,0,0,1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5,0];
  const DBASE = [1,2,3,4,5,7,9,13,17,25,33,49,65,97,129,193,257,385,513,769,1025,1537,2049,3073,4097,6145,8193,12289,16385,24577];
  const DEXT = [0,0,0,0,1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10,11,11,12,12,13,13];

  static function run($data) { $x = new self($data); return $x->go(); }
  private function __construct($d) { $this->d = $d; $this->n = strlen($d); }

  private function bits($k) {
    while ($this->bc < $k) {
      if ($this->p >= $this->n) throw new Exception('Compressed data ended early.');
      $this->bb |= ord($this->d[$this->p++]) << $this->bc; $this->bc += 8;
    }
    $v = $this->bb & ((1 << $k) - 1);
    $this->bb >>= $k; $this->bc -= $k;
    return $v;
  }
  private function build($lengths) {
    $count = array_fill(0, 16, 0);
    foreach ($lengths as $l) $count[$l]++;
    $count[0] = 0;
    $offs = [0, 0];
    for ($i = 1; $i < 15; $i++) $offs[$i + 1] = $offs[$i] + $count[$i];
    $sym = [];
    foreach ($lengths as $s => $l) if ($l) $sym[$offs[$l]++] = $s;
    return [$count, $sym];
  }
  private function decode($h) {
    [$count, $sym] = $h;
    $code = 0; $first = 0; $index = 0;
    for ($len = 1; $len < 16; $len++) {
      if ($this->bc === 0) {
        if ($this->p >= $this->n) throw new Exception('Compressed data ended early.');
        $this->bb = ord($this->d[$this->p++]); $this->bc = 8;
      }
      $code |= $this->bb & 1; $this->bb >>= 1; $this->bc--;
      $c = $count[$len];
      if ($code - $c < $first) return $sym[$index + ($code - $first)];
      $index += $c; $first += $c; $first <<= 1; $code <<= 1;
    }
    throw new Exception('Bad compressed data.');
  }
  private function codes($lc, $dc) {
    while (true) {
      $s = $this->decode($lc);
      if ($s < 256) { $this->out .= chr($s); continue; }
      if ($s === 256) return;
      $s -= 257;
      if ($s >= 29) throw new Exception('Bad length code.');
      $len = self::LBASE[$s] + $this->bits(self::LEXT[$s]);
      $ds = $this->decode($dc);
      $dist = self::DBASE[$ds] + $this->bits(self::DEXT[$ds]);
      $ol = strlen($this->out);
      if ($dist > $ol) throw new Exception('Bad distance.');
      if ($ol > 314572800) throw new Exception('Uncompressed data is too large.');
      if ($dist >= $len) $this->out .= substr($this->out, $ol - $dist, $len);
      else { $chunk = substr($this->out, $ol - $dist, $dist); while ($len > 0) { $t = substr($chunk, 0, $len); $this->out .= $t; $len -= strlen($t); $chunk .= $t; } }
    }
  }
  private function go() {
    static $fixed = null;
    do {
      $last = $this->bits(1);
      $type = $this->bits(2);
      if ($type === 0) {
        $this->bb = 0; $this->bc = 0;
        if ($this->p + 4 > $this->n) throw new Exception('Compressed data ended early.');
        $len = ord($this->d[$this->p]) | (ord($this->d[$this->p + 1]) << 8);
        $this->p += 4;
        $this->out .= substr($this->d, $this->p, $len); $this->p += $len;
      } elseif ($type === 1) {
        if ($fixed === null) {
          $l = array_merge(array_fill(0, 144, 8), array_fill(0, 112, 9), array_fill(0, 24, 7), array_fill(0, 8, 8));
          $fixed = [$this->build($l), $this->build(array_fill(0, 30, 5))];
        }
        $this->codes($fixed[0], $fixed[1]);
      } elseif ($type === 2) {
        $nlen = $this->bits(5) + 257; $ndist = $this->bits(5) + 1; $ncode = $this->bits(4) + 4;
        $order = [16,17,18,0,8,7,9,6,10,5,11,4,12,3,13,2,14,1,15];
        $cl = array_fill(0, 19, 0);
        for ($i = 0; $i < $ncode; $i++) $cl[$order[$i]] = $this->bits(3);
        $clh = $this->build($cl);
        $lengths = [];
        while (count($lengths) < $nlen + $ndist) {
          $s = $this->decode($clh);
          if ($s < 16) { $lengths[] = $s; continue; }
          if ($s === 16) { if (!$lengths) throw new Exception('Bad lengths.'); $prev = end($lengths); $r = 3 + $this->bits(2); }
          elseif ($s === 17) { $prev = 0; $r = 3 + $this->bits(3); }
          else { $prev = 0; $r = 11 + $this->bits(7); }
          for ($i = 0; $i < $r; $i++) $lengths[] = $prev;
        }
        $this->codes($this->build(array_slice($lengths, 0, $nlen)), $this->build(array_slice($lengths, $nlen, $ndist)));
      } else throw new Exception('Bad block type.');
    } while (!$last);
    return $this->out;
  }
}

function inflate_raw($data) {
  if (function_exists('gzinflate')) { $r = @gzinflate($data); if ($r !== false) return $r; }
  return PhpInflate::run($data);
}

// ---------- zip reader with the subset of ZipArchive's interface the importer uses ----------
class MiniZip {
  public $numFiles = 0;
  private $fh; private $entries = []; private $byName = [];

  function open($path) {
    $this->fh = @fopen($path, 'rb');
    if (!$this->fh) return false;
    $size = filesize($path);
    $tail = min($size, 65557);
    fseek($this->fh, $size - $tail);
    $buf = fread($this->fh, $tail);
    $eocd = strrpos($buf, "PK\x05\x06");
    if ($eocd === false) return false;
    $e = unpack('vdisk/vcddisk/vcount1/vcount/Vcdsize/Vcdoff', substr($buf, $eocd + 4, 16));
    fseek($this->fh, $e['cdoff']);
    $cd = fread($this->fh, $e['cdsize']);
    $p = 0;
    for ($i = 0; $i < $e['count']; $i++) {
      if (substr($cd, $p, 4) !== "PK\x01\x02") return false;
      $h = unpack('vmade/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/viattr/Vxattr/Voffset', substr($cd, $p + 4, 42));
      $name = substr($cd, $p + 46, $h['nlen']);
      $this->entries[] = ['name' => $name, 'method' => $h['method'], 'flag' => $h['flag'], 'csize' => $h['csize'], 'usize' => $h['usize'], 'offset' => $h['offset']];
      $this->byName[$name] = count($this->entries) - 1;
      $p += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
    }
    $this->numFiles = count($this->entries);
    return true;
  }
  function getNameIndex($i) { return $this->entries[$i]['name'] ?? false; }
  function getFromName($name) {
    if (!isset($this->byName[$name])) return false;
    $e = $this->entries[$this->byName[$name]];
    if ($e['flag'] & 1) return false; // encrypted
    fseek($this->fh, $e['offset']);
    $lh = fread($this->fh, 30);
    if (substr($lh, 0, 4) !== "PK\x03\x04") return false;
    $l = unpack('vnlen/vxlen', substr($lh, 26, 4));
    fseek($this->fh, $e['offset'] + 30 + $l['nlen'] + $l['xlen']);
    if ($e['usize'] > 300 * 1048576 || $e['csize'] > 300 * 1048576) return false; // refuse oversized entries (zip bombs)
    $raw = $e['csize'] > 0 ? fread($this->fh, $e['csize']) : '';
    if ($e['method'] === 0) return $raw;
    if ($e['method'] === 8) { try { return inflate_raw($raw); } catch (Exception $x) { return false; } }
    return false;
  }
  function getStream($name) {
    $s = $this->getFromName($name);
    if ($s === false) return false;
    $t = fopen('php://temp', 'w+b'); fwrite($t, $s); rewind($t);
    return $t;
  }
  function close() { if ($this->fh) fclose($this->fh); $this->fh = null; }
}

function open_zip($path) {
  if (class_exists('ZipArchive')) {
    try { $z = @new ZipArchive(); if (@$z->open($path) === true) return $z; } catch (Throwable $e) { /* fall through to the built-in reader */ }
  }
  $z = new MiniZip();
  return $z->open($path) ? $z : null;
}

// ---------- tiny zip writer (stored, no compression) for "download all submissions" ----------
class MiniZipWriter {
  private $fh; private $cd = ''; private $count = 0; private $off = 0;
  function __construct($path) { $this->fh = fopen($path, 'wb'); }
  private function dos() { $t = getdate(); return [($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2), (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday']]; }
  private function add($name, $crc, $size, callable $writeData) {
    [$tm, $dt] = $this->dos();
    $name = str_replace('\\', '/', $name);
    $flag = 0x0800; // UTF-8 names
    $lh = "PK\x03\x04" . pack('vvvvvVVVvv', 10, $flag, 0, $tm, $dt, $crc, $size, $size, strlen($name), 0) . $name;
    fwrite($this->fh, $lh);
    $writeData($this->fh);
    $this->cd .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 10, $flag, 0, $tm, $dt, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $this->off) . $name;
    $this->off += strlen($lh) + $size;
    $this->count++;
  }
  function addFromString($name, $data) {
    $this->add($name, crc32($data), strlen($data), function ($fh) use ($data) { fwrite($fh, $data); });
  }
  function addFile($path, $name) {
    if (!is_file($path)) return;
    $crc = hexdec(hash_file('crc32b', $path));
    $this->add($name, $crc, filesize($path), function ($fh) use ($path) { $in = fopen($path, 'rb'); stream_copy_to_stream($in, $fh); fclose($in); });
  }
  function close() {
    fwrite($this->fh, $this->cd);
    fwrite($this->fh, "PK\x05\x06" . pack('vvvvVVv', 0, 0, $this->count, $this->count, strlen($this->cd), $this->off, 0));
    fclose($this->fh);
  }
}
