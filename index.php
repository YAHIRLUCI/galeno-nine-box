<?php
/* Galeno Nine Box v2.3 — matriz en vivo al evaluar + reiniciar datos + graficas RH */
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function db() {
  static $pdo = null;
  if ($pdo) return $pdo;
  if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0777, true);
  if (!is_dir(__DIR__ . '/uploads')) @mkdir(__DIR__ . '/uploads', 0777, true);
  try {
    $pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS galeno_ninebox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE galeno_ninebox");
    $driver = 'mysql';
  } catch (Throwable $e) {
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/galeno.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("PRAGMA journal_mode=WAL");
    $driver = 'sqlite';
  }
  $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
  foreach ([
    "CREATE TABLE IF NOT EXISTS empleados (id $pk, num VARCHAR(30) UNIQUE, nombre VARCHAR(120), puesto VARCHAR(120), sede VARCHAR(80), area VARCHAR(80), jefe_id VARCHAR(30), rol VARCHAR(15) DEFAULT '', pass VARCHAR(120) DEFAULT 'galeno', activo INTEGER DEFAULT 1)",
    "CREATE TABLE IF NOT EXISTS ciclos (id $pk, nombre VARCHAR(40) UNIQUE, fecha_inicio DATE, fecha_fin DATE, activo INTEGER DEFAULT 0)",
    "CREATE TABLE IF NOT EXISTS evaluaciones (id $pk, colaborador_id VARCHAR(30), evaluador_id VARCHAR(30), ciclo_id INTEGER, calif_anual VARCHAR(20), desempeno INTEGER, potencial INTEGER, cuadrante VARCHAR(40), comentario TEXT, audio VARCHAR(255), fecha VARCHAR(40))",
    "CREATE TABLE IF NOT EXISTS notificaciones (id $pk, para VARCHAR(30), titulo VARCHAR(120), mensaje TEXT, leida INTEGER DEFAULT 0, fecha VARCHAR(40))"
  ] as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }
  try {
    if (!(int)$pdo->query("SELECT COUNT(*) FROM ciclos")->fetchColumn())
      $pdo->exec("INSERT INTO ciclos (nombre,fecha_inicio,fecha_fin,activo) VALUES ('Ciclo 2026-1','2026-01-01','2026-06-30',1)");
  } catch (Throwable $e) {}
  try {
    if (!(int)$pdo->query("SELECT COUNT(*) FROM empleados WHERE num='4820'")->fetchColumn())
      $pdo->exec("INSERT INTO empleados (num,nombre,puesto,sede,area,jefe_id,rol,pass) VALUES
        ('4820','Roberto Mendez','Gerente de distrito','CDMX','Ventas',NULL,'supervisor','galeno'),
        ('9001','Analista RH','Recursos Humanos','Corporativo','RH',NULL,'rh','galeno')");
  } catch (Throwable $e) {}
  return $pdo;
}
function q($sql,$p=[]){ $st=db()->prepare($sql); $st->execute($p); return $st; }
function rows($sql,$p=[]){ return q($sql,$p)->fetchAll(PDO::FETCH_ASSOC); }
function row($sql,$p=[]){ $r=q($sql,$p)->fetch(PDO::FETCH_ASSOC); return $r?:null; }
function notify($para,$titulo,$mensaje){ q("INSERT INTO notificaciones (para,titulo,mensaje,fecha) VALUES (?,?,?,?)",[$para,$titulo,$mensaje,date('d/m/Y H:i')]); }

const ANUAL_MAP = ['SUPERA'=>3,'ALCANZA'=>2,'PROGRESO'=>2,'BAJO'=>1];
const ANUAL_LABEL = [
  'SUPERA'  => 'Supera expectativas (Alto)',
  'ALCANZA' => 'Alcanza metas (Medio)',
  'PROGRESO'=> 'En progreso (Medio)',
  'BAJO'    => 'Por debajo de lo esperado (Bajo)'
];
function cuadrantes(){
  return [
    'Enigma'           => ['color'=>'#F5C518','desc'=>'Alto potencial no canalizado: requiere foco y acompanamiento.'],
    'Fuerte Potencial' => ['color'=>'#5B8DB8','desc'=>'Futuro lider: proyectar hacia retos mayores.'],
    'Talento'          => ['color'=>'#3A9B5C','desc'=>'Referente clave: retener y preparar para sucesion.'],
    'Cuestionable'     => ['color'=>'#E07A2F','desc'=>'Desempeno inconsistente: clarificar expectativas.'],
    'Mantenedor'       => ['color'=>'#F0A020','desc'=>'Aporte estable: mantener motivado en su rol.'],
    'Buen Desempeño'   => ['color'=>'#4A90B8','desc'=>'Excelente entrega operativa: expandir alcance.'],
    'Riesgo'           => ['color'=>'#D94040','desc'=>'Bajo aporte: plan de mejora urgente o reubicacion.'],
    'Eficiente'        => ['color'=>'#E8922A','desc'=>'Cumplimiento estandar: capacitacion tecnica continua.'],
    'Comprometido'     => ['color'=>'#E8A820','desc'=>'Especialista en su funcion: tutor clave.'],
  ];
}
function cuadranteDe($d,$p){
  $m = [
    3 => [1=>'Enigma', 2=>'Fuerte Potencial', 3=>'Talento'],
    2 => [1=>'Cuestionable', 2=>'Mantenedor', 3=>'Buen Desempeño'],
    1 => [1=>'Riesgo', 2=>'Eficiente', 3=>'Comprometido'],
  ];
  return $m[$p][$d];
}
function matrixOrder(){
  return [
    ['Enigma','Fuerte Potencial','Talento'],
    ['Cuestionable','Mantenedor','Buen Desempeño'],
    ['Riesgo','Eficiente','Comprometido'],
  ];
}
function adjustColor($hex) {
  $hex = ltrim($hex,'#');
  if (strlen($hex)!==6) return '#E07820';
  $r=max(0,hexdec(substr($hex,0,2))-30); $g=max(0,hexdec(substr($hex,2,2))-20); $b=max(0,hexdec(substr($hex,4,2))-10);
  return sprintf('#%02x%02x%02x',$r,$g,$b);
}
function renderMatrix($counts, $highlight = null) {
  $cq = cuadrantes();
  $html = '<div class="nb-grid" id="nbGrid">';
  foreach (matrixOrder() as $row) {
    $html .= '<div class="nb-row">';
    foreach ($row as $name) {
      $n = (int)($counts[$name] ?? 0);
      $col = $cq[$name]['color'] ?? '#F0A020';
      $sel = ($highlight === $name) ? ' nb-sel' : '';
      $html .= '<div class="nb-cell'.$sel.'" data-q="'.h($name).'" style="background:linear-gradient(145deg,'.$col.','.adjustColor($col).')"><span class="nb-label">'.h($name).'</span><span class="nb-num">'.$n.'</span></div>';
    }
    $html .= '</div>';
  }
  $html .= '</div>';
  return $html;
}
function renderAudioPanel($num) {
  $ev = row("SELECT * FROM evaluaciones WHERE colaborador_id=?",[$num]);
  $e = row("SELECT * FROM empleados WHERE num=?",[$num]);
  if (!$ev || empty($ev['audio']) || !$e) return '';
  return '<div class="audio-panel" id="audioPanel">
    <div class="audio-head">
      <div class="avatar">'.h(mb_substr($e['nombre'],0,1)).'</div>
      <div><strong>'.h($e['nombre']).'</strong><br><span class="muted">No. '.h($e['num']).' · '.h($e['puesto']).' · '.h($ev['cuadrante']).'</span></div>
    </div>
    <audio controls autoplay src="'.h($ev['audio']).'" style="width:100%;margin-top:10px"></audio>
    '.($ev['comentario']?'<p class="muted" style="margin-top:8px">'.h($ev['comentario']).'</p>':'').'
  </div>';
}

$user = $_SESSION['user'] ?? null;
$ERR = '';
$MSG = '';
$accion = $_POST['accion'] ?? ($_GET['accion'] ?? '');
$p = $_GET['p'] ?? '';
$play = $_GET['play'] ?? '';

if ($accion === 'login') {
  try {
    $st = row("SELECT * FROM empleados WHERE num=? AND activo=1", [trim($_POST['num']??'')]);
    $pass = trim($_POST['pass']??'');
    if ($st && !empty($st['rol']) && ($pass==='galeno' || $pass===(string)($st['pass']??''))) {
      $_SESSION['user'] = ['num'=>$st['num'],'nombre'=>$st['nombre'],'rol'=>$st['rol']];
      header('Location: ?p='.($st['rol']==='rh'?'rh':'equipo')); exit;
    }
    $ERR = 'Numero, contrasena o rol incorrectos.';
  } catch (Throwable $e) { $ERR = 'Error BD: '.$e->getMessage(); }
}
if ($accion === 'logout') { session_destroy(); header('Location: ?'); exit; }

if ($accion === 'reiniciar' && $user && $user['rol']==='rh') {
  // borra evaluaciones, notificaciones y colaboradores de prueba (no toca 4820/9001)
  q("DELETE FROM evaluaciones");
  q("DELETE FROM notificaciones");
  q("DELETE FROM empleados WHERE num NOT IN ('4820','9001')");
  // limpia archivos de audio
  foreach (glob(__DIR__.'/uploads/audio_*') ?: [] as $f) @unlink($f);
  notify('9001','Reinicio','Se borraron evaluaciones, notificaciones y personal de prueba.');
  header('Location: ?p=rh'); exit;
}

if ($accion === 'guardar_eval' && $user && $user['rol']==='supervisor') {
  $a = trim($_POST['calif_anual']??''); $pot = (int)($_POST['potencial']??0);
  if (isset(ANUAL_MAP[$a]) && $pot>=1 && $pot<=3) {
    $ac = ANUAL_MAP[$a];
    $colab = row("SELECT * FROM empleados WHERE num=? AND jefe_id=?", [$_POST['colab']??'', $user['num']]);
    $ciclo = row("SELECT * FROM ciclos WHERE activo=1");
    if ($colab && $ciclo) {
      $audio = null;
      if (!empty($_FILES['audio']['tmp_name']) && is_uploaded_file($_FILES['audio']['tmp_name']) && $_FILES['audio']['size']<8*1024*1024) {
        $ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'webm';
        $ext = preg_replace('/[^a-z0-9]/i','',$ext) ?: 'webm';
        $audio = 'uploads/audio_'.preg_replace('/[^a-zA-Z0-9_-]/','',$_POST['colab']).'_'.bin2hex(random_bytes(3)).'.'.$ext;
        move_uploaded_file($_FILES['audio']['tmp_name'], __DIR__.'/'.$audio);
      }
      $prev = row("SELECT id,audio FROM evaluaciones WHERE colaborador_id=? AND evaluador_id=? AND ciclo_id=?", [$_POST['colab'],$user['num'],$ciclo['id']]);
      $cuad = cuadranteDe($ac,$pot);
      if ($prev) {
        $audio = $audio ?: $prev['audio'];
        q("UPDATE evaluaciones SET calif_anual=?,desempeno=?,potencial=?,cuadrante=?,comentario=?,audio=?,fecha=? WHERE id=?",
          [$a,$ac,$pot,$cuad,trim($_POST['comentario']??''),$audio,date('d/m/Y H:i'),$prev['id']]);
      } else {
        q("INSERT INTO evaluaciones (colaborador_id,evaluador_id,ciclo_id,calif_anual,desempeno,potencial,cuadrante,comentario,audio,fecha) VALUES (?,?,?,?,?,?,?,?,?,?)",
          [$_POST['colab'],$user['num'],$ciclo['id'],$a,$ac,$pot,$cuad,trim($_POST['comentario']??''),$audio,date('d/m/Y H:i')]);
      }
    }
  }
  header('Location: ?p=equipo'); exit;
}
if ($accion === 'enviar_rh' && $user && $user['rol']==='supervisor') {
  $n = (int)row("SELECT COUNT(*) AS n FROM empleados WHERE jefe_id=? AND activo=1",[$user['num']])['n'];
  $e = (int)row("SELECT COUNT(*) AS n FROM evaluaciones ev JOIN empleados em ON em.num=ev.colaborador_id WHERE em.jefe_id=? AND em.activo=1",[$user['num']])['n'];
  if ($e>=$n && $n>0) {
    notify('9001','Envio recibido',$user['nombre']." envio $e evaluaciones.");
    notify($user['num'],'Envio confirmado',"RH recibio tus $e evaluaciones.");
  }
  header('Location: ?p=equipo'); exit;
}
if ($accion === 'recordar' && $user && $user['rol']==='rh') {
  if (!empty($_POST['sup'])) notify($_POST['sup'],'Recordatorio RH','RH te recuerda completar la evaluacion de '.($_POST['quien']??''));
  header('Location: ?p=rh'); exit;
}
if ($accion === 'importar_csv' && $user && $user['rol']==='rh') {
  $file = $_FILES['csv']['tmp_name']??''; $ok=0;
  if ($file && is_readable($file)) {
    $f=fopen($file,'r'); $first=fgets($f);
    $del = (substr_count($first,';')>substr_count($first,',')) ? ';' : ',';
    if (!preg_match('/num|empleado|nombre/i',$first)) rewind($f);
    while (($r=fgetcsv($f,0,$del))!==false) {
      if (count($r)<2) continue;
      $num=preg_replace('/^EMP-?/i','',trim($r[0]));
      $nombre=trim($r[1]??''); $puesto=trim($r[2]??''); $sede=trim($r[3]??''); $area=trim($r[4]??'');
      $jefe=trim($r[5]??'')?:null; $rol=preg_match('/^(supervisor|rh)$/i',trim($r[6]??''))?strtolower(trim($r[6])):'';
      if (!$num || preg_match('/num|empleado/i',$num)) continue;
      $ex=row("SELECT id FROM empleados WHERE num=?",[$num]);
      if ($ex) q("UPDATE empleados SET nombre=?,puesto=?,sede=?,area=?,jefe_id=?,rol=? WHERE id=?",[$nombre,$puesto,$sede,$area,$jefe,$rol,$ex['id']]);
      else q("INSERT INTO empleados (num,nombre,puesto,sede,area,jefe_id,rol) VALUES (?,?,?,?,?,?,?)",[$num,$nombre,$puesto,$sede,$area,$jefe,$rol]);
      $ok++;
    }
    fclose($f);
  }
  notify('9001','Importacion',"Se importaron/actualizaron $ok registros.");
  header('Location: ?p=rh'); exit;
}
if ($p==='exportar' && $user && $user['rol']==='rh') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=ninebox.csv');
  $out=fopen('php://output','w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
  fputcsv($out,['num','nombre','puesto','evaluador','calificacion','desempeno','potencial','cuadrante','comentario','fecha']);
  foreach (rows("SELECT ev.*,em.nombre,em.puesto FROM evaluaciones ev JOIN empleados em ON em.num=ev.colaborador_id") as $d)
    fputcsv($out,[$d['colaborador_id'],$d['nombre'],$d['puesto'],$d['evaluador_id'],$d['calif_anual'],$d['desempeno'],$d['potencial'],$d['cuadrante'],$d['comentario'],$d['fecha']]);
  fclose($out); exit;
}
try { db(); } catch (Throwable $e) { $ERR = 'No se pudo iniciar BD: '.$e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Galeno Nine Box</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;min-height:100vh;padding:22px;color:#1a1a1a;
background:linear-gradient(160deg,#FFF8F0 0%,#FFF5EB 40%,#FFE8D0 100%)}
.wrap{max-width:1040px;margin:0 auto}
.logo{font-size:11px;letter-spacing:2.5px;color:#E07820;font-weight:800;text-transform:uppercase}
h1{font-size:24px;margin:4px 0;color:#1a1a1a}
h2{font-size:16px;margin-bottom:10px;color:#1a1a1a}
.sub{color:#6B5E52;font-size:13px}
.card{background:#fff;border-radius:16px;padding:20px;margin-bottom:14px;box-shadow:0 8px 28px -12px rgba(0,0,0,.12);border:1px solid #F0E6DC}
.glass{background:rgba(255,255,255,.85);border:1px solid #F0D9C0;border-radius:18px;padding:26px}
label{display:block;font-size:12px;font-weight:700;color:#5C4E42;margin-bottom:5px}
input,select,textarea{width:100%;padding:11px 12px;border:1.5px solid #E8D9C8;border-radius:10px;font-size:14px;margin-bottom:12px;background:#FFFCFA;color:#1a1a1a}
input:focus,select:focus,textarea:focus{border-color:#E07820;outline:none;box-shadow:0 0 0 3px rgba(224,120,32,.15)}
.btn{padding:11px 16px;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;
background:linear-gradient(135deg,#F09030,#D96818);color:#fff;text-decoration:none;display:inline-block;box-shadow:0 4px 14px -4px rgba(217,104,24,.45)}
.btn.gray{background:#F5F0EB;color:#1a1a1a;box-shadow:none;border:1px solid #E8D9C8}
.btn.danger{background:linear-gradient(135deg,#E05040,#C03028);box-shadow:none}
.err{color:#C03028;font-size:13px;margin:8px 0}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:10px 6px;border-bottom:1px solid #F0E6DC}
th{font-size:11px;text-transform:uppercase;color:#8A7A6A}
.tag{display:inline-block;padding:3px 9px;border-radius:999px;font-size:10px;font-weight:800;color:#fff}
.t-eval{background:#3A9B5C}.t-pend{background:#D94040}
.stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.stat{flex:1;min-width:110px;background:linear-gradient(145deg,#fff,#FFF5EB);border:1px solid #F0D9C0;border-radius:14px;padding:14px}
.stat .n{font-size:26px;font-weight:800}
.stat .l{font-size:11px;color:#8A7A6A;text-transform:uppercase;margin-top:2px}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px}
.pill{background:#1a1a1a;color:#fff;border-radius:999px;padding:6px 13px;font-size:12px;text-decoration:none}
.pill.light{background:#fff;color:#1a1a1a;border:1px solid #E8D9C8}
.linklike{color:#D96818;font-weight:700;text-decoration:none;font-size:13px;background:none;border:none;cursor:pointer;padding:0}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:720px){.grid2{grid-template-columns:1fr}}
.hero{font-size:30px;font-weight:800;margin:8px 0;background:linear-gradient(90deg,#1a1a1a,#E07820);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.login-nums{display:flex;gap:10px;justify-content:center;margin-top:14px;flex-wrap:wrap}
.login-num{background:#FFFCFA;border:1.5px solid #E8D9C8;border-radius:12px;padding:12px 16px;cursor:pointer;color:#1a1a1a;min-width:150px;text-align:center}
.login-num:hover{border-color:#E07820;background:#FFF5EB}
.login-num strong{display:block;font-size:18px;color:#D96818}
.voice-box{border:2px dashed #E8D9C8;border-radius:12px;padding:14px;background:#FFFCFA;text-align:center;margin-bottom:12px}
.notif{border-left:4px solid #E07820;background:#FFF8F0;border-radius:8px;padding:10px;margin-bottom:8px;font-size:13px}
.avatar{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#F09030,#D96818);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;flex-shrink:0}
.profile{display:flex;gap:12px;align-items:center;margin-bottom:14px}
.muted{color:#8A7A6A;font-size:13px}
.nb-grid{display:flex;flex-direction:column;gap:8px;margin:12px 0}
.nb-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.nb-cell{border-radius:12px;padding:14px 8px;text-align:center;color:#fff;min-height:72px;display:flex;flex-direction:column;justify-content:center;align-items:center;box-shadow:0 4px 12px -4px rgba(0,0,0,.18);opacity:.72;transition:transform .15s,opacity .15s,box-shadow .15s}
.nb-cell.nb-sel{opacity:1;transform:scale(1.04);box-shadow:0 0 0 3px #1a1a1a,0 8px 20px -6px rgba(0,0,0,.35);z-index:1}
.nb-label{font-size:11px;font-weight:700;opacity:.95;margin-bottom:2px}
.nb-num{font-size:26px;font-weight:800;line-height:1}
.audio-panel{background:linear-gradient(145deg,#FFF8F0,#FFE8D0);border:1.5px solid #F0D9C0;border-radius:14px;padding:16px;margin-top:14px}
.audio-head{display:flex;gap:12px;align-items:center}
.bar-row{display:flex;align-items:center;gap:8px;margin:7px 0;font-size:12px}
.bar-name{width:150px;text-align:right;font-weight:600;color:#5C4E42;font-size:12px}
.bar-track{flex:1;background:#F0E6DC;border-radius:6px;overflow:hidden;height:18px}
.bar-fill{height:18px;border-radius:6px;transition:width .4s}
.hint-live{font-size:12px;color:#E07820;font-weight:700;margin:6px 0 0}
</style>
</head>
<body>
<div class="wrap">
<?php if (!$user): ?>
  <div style="text-align:center;margin-bottom:18px">
    <div class="logo">Galeno Nine Box</div>
    <div class="hero">Evaluacion de talento</div>
    <p class="sub">Supervisor y Recursos Humanos · notas de voz · matriz Nine Box</p>
  </div>
  <div class="glass" style="max-width:460px;margin:0 auto">
    <?php if ($ERR): ?><p class="err"><?= h($ERR) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="accion" value="login" />
      <label>Numero de empleado</label>
      <input name="num" required placeholder="4820 o 9001" style="text-align:center" />
      <label>Contrasena</label>
      <input name="pass" type="password" required placeholder="galeno" />
      <button class="btn" style="width:100%">Entrar</button>
    </form>
    <div class="login-nums">
      <div class="login-num" onclick="document.querySelector('[name=num]').value='4820';document.querySelector('[name=pass]').value='galeno'"><strong>4820</strong>Supervisor</div>
      <div class="login-num" onclick="document.querySelector('[name=num]').value='9001';document.querySelector('[name=pass]').value='galeno'"><strong>9001</strong>Recursos Humanos</div>
    </div>
  </div>

<?php elseif ($user['rol']==='supervisor' && $p==='evaluar'):
  $e = row("SELECT * FROM empleados WHERE num=? AND jefe_id=?", [$_GET['id']??'', $user['num']]);
  if (!$e) { echo '<div class="card"><p>Colaborador no valido. <a class="linklike" href="?p=equipo">Volver</a></p></div>'; }
  else {
    $ev = row("SELECT * FROM evaluaciones WHERE colaborador_id=? AND evaluador_id=?", [$e['num'],$user['num']]);
    $mapJs = json_encode(ANUAL_MAP);
    $gridJs = json_encode([
      '3'=>['1'=>'Enigma','2'=>'Fuerte Potencial','3'=>'Talento'],
      '2'=>['1'=>'Cuestionable','2'=>'Mantenedor','3'=>'Buen Desempeño'],
      '1'=>['1'=>'Riesgo','2'=>'Eficiente','3'=>'Comprometido'],
    ]);
    $descJs = json_encode(array_map(fn($v)=>$v['desc'], cuadrantes()));
    $hi = null;
    if (!empty($ev['calif_anual']) && !empty($ev['potencial'])) {
      $hi = cuadranteDe(ANUAL_MAP[$ev['calif_anual']], (int)$ev['potencial']);
    }
?>
  <div class="topbar">
    <div><div class="logo">Galeno Nine Box</div><h1>Evaluar colaborador</h1></div>
    <a class="pill light" href="?p=equipo">← Volver</a>
  </div>
  <div class="card">
    <div class="profile">
      <div class="avatar"><?= h(mb_substr($e['nombre'],0,1)) ?></div>
      <div><strong style="font-size:17px"><?= h($e['nombre']) ?></strong><br><span class="muted"><?= h($e['puesto']) ?> · No. <?= h($e['num']) ?> · <?= h($e['sede']?:'') ?></span></div>
    </div>

    <form method="post" enctype="multipart/form-data" id="formEval">
      <input type="hidden" name="accion" value="guardar_eval" />
      <input type="hidden" name="colab" value="<?= h($e['num']) ?>" />
      <div class="grid2">
        <div>
          <label>Calificacion de desempeno</label>
          <select name="calif_anual" id="selAnual" required>
            <option value="">— Selecciona —</option>
            <?php foreach (ANUAL_LABEL as $k=>$lbl): ?>
            <option value="<?= $k ?>" <?= ($ev['calif_anual']??'')===$k?'selected':'' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Potencial</label>
          <select name="potencial" id="selPot" required>
            <option value="">— Selecciona —</option>
            <option value="3" <?= ($ev['potencial']??'')==3?'selected':'' ?>>Alto potencial</option>
            <option value="2" <?= ($ev['potencial']??'')==2?'selected':'' ?>>Medio potencial</option>
            <option value="1" <?= ($ev['potencial']??'')==1?'selected':'' ?>>Bajo potencial</option>
          </select>
        </div>
      </div>

      <div id="resultadoBox" style="display:none;border-radius:12px;padding:14px 16px;color:#fff;margin:4px 0 14px;font-weight:600">
        <div id="resultadoNombre" style="font-size:16px"></div>
        <div id="resultadoDesc" style="font-size:13px;font-weight:400;opacity:.95;margin-top:4px"></div>
      </div>

      <label>Comentario escrito</label>
      <textarea name="comentario" rows="3" placeholder="Fortalezas, areas de oportunidad, compromisos..."><?= h($ev['comentario']??'') ?></textarea>
      <label>Nota de voz</label>
      <div class="voice-box">
        <?php if (!empty($ev['audio'])): ?><audio controls src="<?= h($ev['audio']) ?>" style="width:100%;max-width:360px"></audio><p class="muted" style="margin:6px 0">Puedes regrabar abajo.</p><?php endif; ?>
        <button type="button" class="btn" id="btnRec">Grabar voz</button>
        <button type="button" class="btn danger" id="btnStop" style="display:none">Detener</button>
        <audio id="prevAudio" controls style="display:none;margin-top:8px;width:100%;max-width:360px"></audio>
      </div>
      <button class="btn" type="submit">Guardar evaluacion</button>
      <a class="btn gray" href="?p=equipo">Cancelar</a>
    </form>
  </div>
<script>
const ANUAL = <?= $mapJs ?>;
const GRID = <?= $gridJs ?>;
const DESC = <?= $descJs ?>;
const COLORS = {
  'Enigma':'#F5C518','Fuerte Potencial':'#5B8DB8','Talento':'#3A9B5C',
  'Cuestionable':'#E07A2F','Mantenedor':'#F0A020','Buen Desempeño':'#4A90B8',
  'Riesgo':'#D94040','Eficiente':'#E8922A','Comprometido':'#E8A820'
};
function updateResultado(){
  const a = document.getElementById('selAnual').value;
  const p = document.getElementById('selPot').value;
  const box = document.getElementById('resultadoBox');
  if (!a || !p) { box.style.display = 'none'; return; }
  const d = ANUAL[a];
  const name = GRID[String(p)][String(d)];
  box.style.display = 'block';
  box.style.background = 'linear-gradient(145deg,'+(COLORS[name]||'#E07820')+',#c46810)';
  document.getElementById('resultadoNombre').textContent = name;
  document.getElementById('resultadoDesc').textContent = DESC[name] || '';
}
document.getElementById('selAnual').addEventListener('change', updateResultado);
document.getElementById('selPot').addEventListener('change', updateResultado);
updateResultado();

let rec=null,chunks=[],blob=null;
document.getElementById('btnRec').onclick=async()=>{
  try{
    const st=await navigator.mediaDevices.getUserMedia({audio:true});
    rec=new MediaRecorder(st); chunks=[];
    rec.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};
    rec.onstop=()=>{blob=new Blob(chunks,{type:rec.mimeType||'audio/webm'});const a=document.getElementById('prevAudio');a.src=URL.createObjectURL(blob);a.style.display='block';st.getTracks().forEach(t=>t.stop())};
    rec.start(250);
    document.getElementById('btnRec').style.display='none';
    document.getElementById('btnStop').style.display='inline-block';
  }catch(e){alert('Sin acceso al microfono')}
};
document.getElementById('btnStop').onclick=()=>{
  if(rec&&rec.state!=='inactive')rec.stop();
  document.getElementById('btnRec').style.display='inline-block';
  document.getElementById('btnStop').style.display='none';
};
document.getElementById('formEval').addEventListener('submit',function(e){
  if(!blob)return;
  e.preventDefault();
  const fd=new FormData(this);
  fd.set('audio',new File([blob],'nota.webm',{type:blob.type||'audio/webm'}));
  fetch('?',{method:'POST',body:fd,credentials:'same-origin'}).then(()=>location='?p=equipo');
});
</script>
<?php } ?>

<?php elseif ($user['rol']==='rh'):
  $total=(int)(row("SELECT COUNT(*) AS n FROM empleados WHERE activo=1 AND (rol='' OR rol IS NULL) AND num NOT IN ('4820','9001')")['n']??0);
  $evals=(int)(row("SELECT COUNT(*) AS n FROM evaluaciones")['n']??0);
  $voces=(int)(row("SELECT COUNT(*) AS n FROM evaluaciones WHERE audio IS NOT NULL AND audio!=''")['n']??0);
  $cuadRows = rows("SELECT cuadrante, COUNT(*) AS n FROM evaluaciones GROUP BY cuadrante");
  $counts = [];
  foreach ($cuadRows as $c) $counts[$c['cuadrante']] = (int)$c['n'];
  $maxC = max(1, $counts ? max($counts) : 1);
  $faltan=rows("SELECT em.* FROM empleados em LEFT JOIN evaluaciones ev ON ev.colaborador_id=em.num WHERE em.activo=1 AND (em.rol='' OR em.rol IS NULL) AND em.num NOT IN ('4820','9001') AND ev.id IS NULL ORDER BY em.nombre");
  $det=rows("SELECT ev.*, em.nombre, em.puesto FROM evaluaciones ev JOIN empleados em ON em.num=ev.colaborador_id ORDER BY ev.id DESC");
  $nots=rows("SELECT * FROM notificaciones WHERE para='9001' ORDER BY id DESC LIMIT 12");
  $orden = ['Talento','Fuerte Potencial','Buen Desempeño','Comprometido','Mantenedor','Eficiente','Enigma','Cuestionable','Riesgo'];
?>
  <div class="topbar">
    <div><div class="logo">Galeno Nine Box</div><h1>Panel Recursos Humanos</h1></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="pill light" href="?p=exportar">Exportar CSV</a>
      <form method="post" style="display:inline" onsubmit="return confirm('Esto borra evaluaciones, notificaciones y personal de prueba. ¿Continuar?')">
        <input type="hidden" name="accion" value="reiniciar" />
        <button type="submit" class="pill" style="border:none;cursor:pointer;background:#C03028">Reiniciar datos de prueba</button>
      </form>
      <a class="pill" href="?accion=logout">Salir</a>
    </div>
  </div>
  <div class="stats">
    <div class="stat"><div class="n"><?= $total ?></div><div class="l">Colaboradores</div></div>
    <div class="stat"><div class="n" style="color:#3A9B5C"><?= $evals ?></div><div class="l">Evaluadas</div></div>
    <div class="stat"><div class="n" style="color:#D94040"><?= max(0,$total-$evals) ?></div><div class="l">Pendientes</div></div>
    <div class="stat"><div class="n" style="color:#E07820"><?= $voces ?></div><div class="l">Notas de voz</div></div>
  </div>

  <div class="card">
    <h2>Rendimiento por cuadrante (tiempo real)</h2>
    <p class="muted" style="margin-bottom:10px">Se actualiza con cada evaluacion guardada</p>
    <?php if (!$counts): ?><p class="muted">Aun no hay evaluaciones. Importa personal y pide al supervisor evaluar.</p><?php endif; ?>
    <?php foreach ($orden as $name):
      $col = cuadrantes()[$name]['color'] ?? '#E07820';
      $n = (int)($counts[$name] ?? 0);
      $pct = $evals > 0 ? round($n / max($evals,1) * 100) : 0;
      $pctBar = $maxC > 0 ? round($n / $maxC * 100) : 0;
    ?>
      <div class="bar-row">
        <div class="bar-name"><?= h($name) ?></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= $pctBar ?>%;background:<?= h($col) ?>"></div></div>
        <strong style="min-width:48px"><?= $n ?> <span class="muted" style="font-weight:500">(<?= $pct ?>%)</span></strong>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="grid2">
    <div class="card">
      <h2>Resumen rapido</h2>
      <p class="muted">Evaluadas: <strong><?= $evals ?></strong> de <strong><?= $total ?></strong></p>
      <p class="muted">Pendientes: <strong style="color:#D94040"><?= max(0,$total-$evals) ?></strong></p>
      <p class="muted">Notas de voz: <strong style="color:#E07820"><?= $voces ?></strong></p>
      <?php if ($total>0): ?>
      <div class="bar-row" style="margin-top:12px">
        <div class="bar-name">Avance global</div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= $total?round($evals/$total*100):0 ?>%;background:linear-gradient(90deg,#F09030,#3A9B5C)"></div></div>
        <strong><?= $total?round($evals/$total*100):0 ?>%</strong>
      </div>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Importar personal</h2>
      <p class="muted" style="margin-bottom:8px">CSV: numero;nombre;puesto;sede;area;jefe</p>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="importar_csv" />
        <input type="file" name="csv" accept=".csv" required />
        <button class="btn">Importar CSV</button>
      </form>
      <h2 style="margin-top:16px">Notificaciones</h2>
      <?php foreach ($nots as $n): ?>
        <div class="notif"><strong><?= h($n['titulo']) ?></strong> — <?= h($n['mensaje']) ?><br><small class="muted"><?= h($n['fecha']) ?></small></div>
      <?php endforeach; ?>
      <?php if (!$nots): ?><p class="muted">Sin notificaciones.</p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>Quien falta por evaluar</h2>
    <table>
      <tr><th>No.</th><th>Nombre</th><th>Puesto</th><th>Jefe</th><th></th></tr>
      <?php foreach ($faltan as $f): ?>
      <tr>
        <td><?= h($f['num']) ?></td><td><?= h($f['nombre']) ?></td><td><?= h($f['puesto']) ?></td><td><?= h($f['jefe_id']?:'—') ?></td>
        <td><?php if ($f['jefe_id']): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="accion" value="recordar" />
            <input type="hidden" name="sup" value="<?= h($f['jefe_id']) ?>" />
            <input type="hidden" name="quien" value="<?= h($f['nombre']) ?>" />
            <button type="submit" class="linklike">Recordar</button>
          </form>
        <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$faltan): ?><tr><td colspan="5" style="color:#3A9B5C;font-weight:700">Todo evaluado</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="card">
    <h2>Detalle de evaluaciones</h2>
    <p class="muted" style="margin-bottom:10px">Escuchar abre el audio abajo, sin salir de la pagina</p>
    <table>
      <tr><th>Colaborador</th><th>Cuadrante</th><th>Desempeno / Potencial</th><th>Voz</th><th>Fecha</th></tr>
      <?php foreach ($det as $d): ?>
      <tr>
        <td><strong><?= h($d['nombre']) ?></strong><br><span class="muted"><?= h($d['colaborador_id']) ?></span></td>
        <td><?= h($d['cuadrante']) ?></td>
        <td><?= h($d['calif_anual'] ?: '—') ?> / <?= $d['potencial']==3?'Alto':($d['potencial']==2?'Medio':'Bajo') ?></td>
        <td><?= $d['audio'] ? '<a class="linklike" href="?p=rh&play='.h($d['colaborador_id']).'#audioPanel">Escuchar</a>' : '—' ?></td>
        <td><?= h($d['fecha']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$det): ?><tr><td colspan="5">Sin datos</td></tr><?php endif; ?>
    </table>
    <?php if ($play): echo renderAudioPanel($play); endif; ?>
  </div>

<?php else:
  $total=(int)(row("SELECT COUNT(*) AS n FROM empleados WHERE jefe_id=? AND activo=1",[$user['num']])['n']??0);
  $evals=(int)(row("SELECT COUNT(*) AS n FROM evaluaciones ev JOIN empleados em ON em.num=ev.colaborador_id WHERE em.jefe_id=? AND em.activo=1",[$user['num']])['n']??0);
  $prog=$total?round($evals/$total*100):0;
  $equipo=rows("SELECT * FROM empleados WHERE jefe_id=? AND activo=1 ORDER BY nombre",[$user['num']]);
  $nots=rows("SELECT * FROM notificaciones WHERE para=? ORDER BY id DESC LIMIT 10",[$user['num']]);
  $cuadRows=rows("SELECT ev.cuadrante, COUNT(*) AS n FROM evaluaciones ev JOIN empleados em ON em.num=ev.colaborador_id WHERE em.jefe_id=? GROUP BY ev.cuadrante",[$user['num']]);
  $counts=[]; foreach($cuadRows as $c) $counts[$c['cuadrante']]=(int)$c['n'];
?>
  <div class="topbar">
    <div><div class="logo">Galeno Nine Box</div><h1>Panel del evaluador</h1><p class="sub"><?= h($user['nombre']) ?></p></div>
    <a class="pill" href="?accion=logout">Salir</a>
  </div>
  <div class="stats">
    <div class="stat"><div class="n"><?= $total ?></div><div class="l">Equipo</div></div>
    <div class="stat"><div class="n" style="color:#3A9B5C"><?= $evals ?></div><div class="l">Evaluados</div></div>
    <div class="stat"><div class="n" style="color:#D94040"><?= $total-$evals ?></div><div class="l">Pendientes</div></div>
    <div class="stat"><div class="n" style="color:#E07820"><?= $prog ?>%</div><div class="l">Progreso</div></div>
  </div>

  <?php if ($evals > 0): ?>
  <div class="card">
    <h2>Matriz de tu equipo</h2>
    <?= renderMatrix($counts) ?>
  </div>
  <?php endif; ?>

  <?php if ($nots): ?>
  <div class="card">
    <h2>Notificaciones</h2>
    <?php foreach ($nots as $n): ?><div class="notif"><strong><?= h($n['titulo']) ?></strong> — <?= h($n['mensaje']) ?><br><small class="muted"><?= h($n['fecha']) ?></small></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Equipo a cargo</h2>
    <table>
      <tr><th>No.</th><th>Nombre</th><th>Puesto</th><th>Estado</th><th>Accion</th></tr>
      <?php foreach ($equipo as $e):
        $ev=row("SELECT * FROM evaluaciones WHERE colaborador_id=? AND evaluador_id=?",[$e['num'],$user['num']]); ?>
      <tr>
        <td><?= h($e['num']) ?></td>
        <td><?= h($e['nombre']) ?></td>
        <td><?= h($e['puesto']) ?></td>
        <td><span class="tag <?= $ev?'t-eval':'t-pend' ?>"><?= $ev?'EVALUADO':'PENDIENTE' ?></span></td>
        <td>
          <a class="linklike" href="?p=evaluar&id=<?= h($e['num']) ?>"><?= $ev?'Editar':'Iniciar' ?> →</a>
          <?php if ($ev && $ev['audio']): ?> · <a class="linklike" href="?p=equipo&play=<?= h($e['num']) ?>#audioPanel">Escuchar</a><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$equipo): ?><tr><td colspan="5" class="muted">Sin colaboradores. RH importa CSV con jefe=<?= h($user['num']) ?></td></tr><?php endif; ?>
    </table>
    <?php if ($play): echo renderAudioPanel($play); endif; ?>
  </div>

  <div class="card">
    <h2>Cierre del ciclo</h2>
    <p style="margin-bottom:10px"><?= $evals ?> / <?= $total ?> evaluados · <?= $prog ?>%</p>
    <form method="post">
      <input type="hidden" name="accion" value="enviar_rh" />
      <button class="btn" <?= ($evals<$total||$total==0)?'style="opacity:.5" onclick="alert(\'Completa el 100% del equipo\');return false"':'' ?>>Enviar a Recursos Humanos</button>
    </form>
  </div>
<?php endif; ?>
</div>
</body>
</html>
