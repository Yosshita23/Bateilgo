<?php
// index.php — Bateilgo completo en UN solo archivo (versión web con sesiones).
// Necesita en la misma carpeta: estilos.css (tu CSS) y mascotas.png. Base de datos: bateilgo.sql + bateilgo_parte2.sql
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Monterrey');

const DB_HOST = 'localhost', DB_USER = 'root', DB_PASS = '', DB_NAME = 'bateilgo';
const RECOMPENSA = 50, SACIEDAD_MAX = 100, SACIEDAD_INICIAL = 70, DECAIMIENTO_POR_HORA = 2;
const ESPECIES = ['gato' => '🐱', 'perro' => '🐶', 'conejo' => '🐰', 'dragon' => '🐲'];
const UPLOADS = __DIR__ . '/uploads/';
const TAM_MAX = 10 * 1024 * 1024;
const TIPOS = [
    'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
    'pdf' => ['application/pdf'], 'txt' => ['text/plain'],
    'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'zip' => ['application/zip', 'application/x-zip-compressed'],
];

class Aviso extends Exception {}
function falla(string $m): void { throw new Aviso($m); }

set_exception_handler(function (Throwable $t) {
    error_log((string)$t);
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="estilos.css"><main class="contenido"><div class="aviso-error">Error interno. Revisa que MySQL esté encendido y que importaste bateilgo.sql y bateilgo_parte2.sql.</div></main>';
});

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

/* ---------- Utilidades ---------- */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ir(string $url): void { header('Location: ' . $url); exit; }
function flash(string $t, string $m): void { $_SESSION['flash'] = [$t, $m]; }
function q(string $sql, string $t = '', array $p = []): mysqli_stmt {
    global $db;
    $s = $db->prepare($sql);
    if ($t !== '') { $s->bind_param($t, ...$p); }
    $s->execute();
    return $s;
}
function filas(string $sql, string $t = '', array $p = []): array {
    $s = q($sql, $t, $p); $r = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $r;
}
function fila(string $sql, string $t = '', array $p = []): ?array { return filas($sql, $t, $p)[0] ?? null; }
function ejecutar(string $sql, string $t = '', array $p = []): int {
    $s = q($sql, $t, $p); $n = $s->affected_rows; $s->close(); return $n;
}
function transaccion(callable $f) {
    global $db;
    $db->begin_transaction();
    try { $r = $f(); $db->commit(); return $r; }
    catch (Throwable $t) { $db->rollback(); throw $t; }
}
function form(string $accion, string $html, string $attr = ''): string {
    return '<form method="post" class="f" ' . $attr . '><input type="hidden" name="csrf" value="' . e($_SESSION['csrf'])
        . '"><input type="hidden" name="accion" value="' . $accion . '">' . $html . '</form>';
}
function parsear_fecha(string $texto): ?string {
    $texto = str_replace('T', ' ', trim($texto));
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $f) {
        $d = DateTime::createFromFormat('!' . $f, $texto);
        $err = DateTime::getLastErrors();
        if ($d && (!$err || ($err['warning_count'] == 0 && $err['error_count'] == 0))) {
            if ($f === 'Y-m-d') { $d->setTime(23, 59, 59); }
            return $d->format('Y-m-d H:i:s');
        }
    }
    return null;
}
function fmt(?string $f): string { return $f ? date('d/m/Y H:i', strtotime($f)) : ''; }
function requerir(?array $u, ?string $rol = null): void {
    if (!$u) { falla('Inicia sesión'); }
    if ($rol !== null && $u['rol'] !== $rol) { falla('No tienes permiso para esta acción'); }
}

/* ---------- Mascota ---------- */
function estado_mascota(int $s): string { return $s >= 70 ? 'feliz' : ($s >= 35 ? 'normal' : ($s > 0 ? 'hambrienta' : 'desmayada')); }
function obtener_mascota(int $id, bool $bloquear = false): ?array {
    $m = fila('SELECT nombre, especie, saciedad, TIMESTAMPDIFF(MINUTE, ultima_actualizacion, NOW()) AS minutos
               FROM mascotas WHERE id_alumno = ?' . ($bloquear ? ' FOR UPDATE' : ''), 'i', [$id]);
    if (!$m) { return null; }
    $perdida = intdiv(max(0, (int)$m['minutos']) * DECAIMIENTO_POR_HORA, 60);
    $m['saciedad'] = max(0, (int)$m['saciedad'] - $perdida);
    $m['estado'] = estado_mascota($m['saciedad']);
    return $m;
}
function accesorios_equipados(int $id): array {
    return filas('SELECT i.id, i.nombre, i.slot, i.emoji FROM inventario inv JOIN tienda_items i ON i.id = inv.id_item
                  WHERE inv.id_alumno = ? AND inv.equipado = 1', 'i', [$id]);
}

/* ---------- Usuario actual ---------- */
$u = null;
if (isset($_SESSION['uid'])) {
    $u = fila('SELECT id, usuario, rol, monedas FROM usuarios WHERE id = ?', 'i', [$_SESSION['uid']]);
    if (!$u) { unset($_SESSION['uid']); }
}

/* ---------- Acciones (POST) ---------- */
function accion(string $a, ?array $u): string {
    $P = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $N = fn(string $k) => filter_var($_POST[$k] ?? null, FILTER_VALIDATE_INT);
    $aqui = $_SERVER['REQUEST_URI'];
    if (!in_array($a, ['login', 'registro'], true)) { requerir($u); }
    $id = $u ? (int)$u['id'] : 0;

    switch ($a) {
    case 'registro':
        $us = $P('usuario'); $pw = $_POST['password'] ?? ''; $rol = $P('rol');
        if (!preg_match('/^[\p{L}0-9_]{3,20}$/u', $us)) { falla('El usuario debe tener de 3 a 20 caracteres (letras, números o _)'); }
        if (!is_string($pw) || mb_strlen($pw) < 6 || mb_strlen($pw) > 72) { falla('La contraseña debe tener entre 6 y 72 caracteres'); }
        if (!in_array($rol, ['alumno', 'profesor'], true)) { falla('El rol debe ser alumno o profesor'); }
        try { ejecutar('INSERT INTO usuarios (usuario, password, rol) VALUES (?, ?, ?)', 'sss', [$us, password_hash($pw, PASSWORD_DEFAULT), $rol]); }
        catch (mysqli_sql_exception $x) { if ($x->getCode() === 1062) { falla('Ese usuario ya existe'); } throw $x; }
        flash('ok', 'Cuenta creada, ya puedes iniciar sesión');
        return '?p=login';

    case 'login':
        $pw = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $r = fila('SELECT id, password FROM usuarios WHERE usuario = ?', 's', [$P('usuario')]);
        if (!$r || !password_verify($pw, $r['password'])) { falla('Usuario o contraseña incorrectos'); }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$r['id'];
        return '?p=inicio';

    case 'logout':
        $_SESSION = [];
        session_destroy();
        return '?p=login';

    case 'crear_materia':
        requerir($u, 'profesor');
        $nombre = $P('nombre');
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 60) { falla('El nombre de la materia debe tener entre 3 y 60 caracteres'); }
        $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < 10; $i++) {
            $cod = 'BTL-';
            for ($j = 0; $j < 4; $j++) { $cod .= $alf[random_int(0, strlen($alf) - 1)]; }
            try {
                ejecutar('INSERT INTO materias (nombre, codigo, id_profesor) VALUES (?, ?, ?)', 'ssi', [$nombre, $cod, $id]);
                flash('ok', "Materia creada. Código para tus alumnos: $cod");
                return $aqui;
            } catch (mysqli_sql_exception $x) { if ($x->getCode() !== 1062) { throw $x; } }
        }
        falla('No se pudo generar un código único, intenta de nuevo');

    case 'unirse':
        requerir($u, 'alumno');
        $cod = strtoupper($P('codigo'));
        if (!preg_match('/^BTL-[A-Z0-9]{4}$/', $cod)) { falla('Código inválido (formato BTL-XXXX)'); }
        $m = fila('SELECT id, nombre FROM materias WHERE codigo = ?', 's', [$cod]);
        if (!$m) { falla('No existe una materia con ese código'); }
        if (ejecutar('INSERT IGNORE INTO clase_alumnos (id_materia, id_alumno) VALUES (?, ?)', 'ii', [$m['id'], $id]) !== 1) { falla('Ya estás inscrito en esta materia'); }
        flash('ok', 'Te uniste a ' . $m['nombre']);
        return $aqui;

    case 'crear_tarea':
    case 'editar_tarea':
        requerir($u, 'profesor');
        $titulo = $P('titulo'); $desc = $P('descripcion'); $fecha = parsear_fecha($P('fecha_entrega'));
        if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 100) { falla('El título debe tener entre 3 y 100 caracteres'); }
        if (mb_strlen($desc) > 2000) { falla('La descripción es demasiado larga'); }
        if (!$fecha) { falla('Fecha inválida'); }
        if ($a === 'crear_tarea') {
            $idm = $N('id_materia');
            if (!$idm || !fila('SELECT id FROM materias WHERE id = ? AND id_profesor = ?', 'ii', [$idm, $id])) { falla('No puedes publicar tareas en esa materia'); }
            ejecutar('INSERT INTO tareas (id_materia, titulo, descripcion, fecha_entrega) VALUES (?, ?, ?, ?)', 'isss', [$idm, $titulo, $desc, $fecha]);
            flash('ok', 'Tarea publicada');
        } else {
            $idt = $N('id_tarea');
            if (!$idt || !fila('SELECT t.id FROM tareas t JOIN materias m ON m.id = t.id_materia WHERE t.id = ? AND m.id_profesor = ?', 'ii', [$idt, $id])) { falla('Tarea no encontrada'); }
            ejecutar('UPDATE tareas SET titulo = ?, descripcion = ?, fecha_entrega = ? WHERE id = ?', 'sssi', [$titulo, $desc, $fecha, $idt]);
            flash('ok', 'Tarea actualizada');
        }
        return $aqui;

    case 'eliminar_tarea':
        requerir($u, 'profesor');
        $idt = $N('id_tarea');
        if (!$idt || !fila('SELECT t.id FROM tareas t JOIN materias m ON m.id = t.id_materia WHERE t.id = ? AND m.id_profesor = ?', 'ii', [$idt, $id])) { falla('Tarea no encontrada'); }
        $archivos = array_column(filas('SELECT archivo FROM entregas WHERE id_tarea = ? AND archivo IS NOT NULL', 'i', [$idt]), 'archivo');
        ejecutar('DELETE FROM tareas WHERE id = ?', 'i', [$idt]);
        foreach ($archivos as $f) { if (preg_match('/^\d+_[a-f0-9]{8}\.[a-z0-9]{2,5}$/', $f)) { @unlink(UPLOADS . $f); } }
        flash('ok', 'Tarea eliminada');
        return $aqui;

    case 'entregar':
        requerir($u, 'alumno');
        $idt = $N('id_tarea'); $link = $P('link'); $com = $P('comentario');
        if (!$idt) { falla('Falta la tarea'); }
        if (mb_strlen($com) > 500) { falla('El comentario es demasiado largo (máx. 500)'); }
        if ($link !== '') {
            $esq = strtolower((string)parse_url($link, PHP_URL_SCHEME));
            if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array($esq, ['http', 'https'], true) || mb_strlen($link) > 500) { falla('El link no es válido (debe iniciar con http:// o https://)'); }
        }
        if (!fila('SELECT t.id FROM tareas t JOIN clase_alumnos ca ON ca.id_materia = t.id_materia AND ca.id_alumno = ? WHERE t.id = ?', 'ii', [$id, $idt])) { falla('Tarea no encontrada'); }
        if (fila('SELECT id FROM entregas WHERE id_tarea = ? AND id_alumno = ?', 'ii', [$idt, $id])) { falla('Ya entregaste esta tarea'); }

        $guardado = null; $tmp = null;
        if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['archivo'];
            if ($f['error'] !== UPLOAD_ERR_OK) { falla(in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'El archivo es demasiado grande' : 'Error al subir el archivo'); }
            if ($f['size'] > TAM_MAX) { falla('El archivo supera los 10 MB'); }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!isset(TIPOS[$ext])) { falla('Tipo de archivo no permitido (' . implode(', ', array_keys(TIPOS)) . ')'); }
            if (!in_array((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']), TIPOS[$ext], true)) { falla('El contenido del archivo no coincide con su extensión'); }
            $guardado = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $tmp = $f['tmp_name'];
        }
        if ($guardado === null && $link === '') { falla('Debes enviar un archivo o un link'); }
        if ($guardado !== null) {
            if (!is_dir(UPLOADS) && !mkdir(UPLOADS, 0755, true)) { throw new RuntimeException('No se pudo crear uploads'); }
            if (!move_uploaded_file($tmp, UPLOADS . $guardado)) { throw new RuntimeException('No se pudo guardar el archivo'); }
        }
        try {
            transaccion(function () use ($idt, $id, $guardado, $link, $com) {
                $l = $link !== '' ? $link : null; $c = $com !== '' ? $com : null; $premio = RECOMPENSA;
                ejecutar('INSERT INTO entregas (id_tarea, id_alumno, archivo, link, comentario) VALUES (?, ?, ?, ?, ?)', 'iisss', [$idt, $id, $guardado, $l, $c]);
                ejecutar('UPDATE usuarios SET monedas = monedas + ? WHERE id = ?', 'ii', [$premio, $id]);
                ejecutar('INSERT INTO transacciones_monedas (id_alumno, cantidad, motivo) VALUES (?, ?, ?)', 'iis', [$id, $premio, 'Tarea entregada']);
            });
        } catch (Throwable $x) {
            if ($guardado !== null) { @unlink(UPLOADS . $guardado); }
            if ($x instanceof mysqli_sql_exception && $x->getCode() === 1062) { falla('Ya entregaste esta tarea'); }
            throw $x;
        }
        flash('ok', '¡Tarea entregada! Ganaste ' . RECOMPENSA . ' monedas');
        return $aqui;

    case 'crear_mascota':
        requerir($u, 'alumno');
        $nombre = $P('nombre'); $esp = $P('especie');
        if (!preg_match('/^[\p{L}0-9 ]{2,20}$/u', $nombre)) { falla('El nombre debe tener de 2 a 20 caracteres (letras, números o espacios)'); }
        if (!isset(ESPECIES[$esp])) { falla('Especie inválida'); }
        try { ejecutar('INSERT INTO mascotas (id_alumno, nombre, especie, saciedad) VALUES (?, ?, ?, ?)', 'issi', [$id, $nombre, $esp, SACIEDAD_INICIAL]); }
        catch (mysqli_sql_exception $x) { if ($x->getCode() === 1062) { falla('Ya tienes una mascota'); } throw $x; }
        flash('ok', '¡Conoce a tu nueva mascota!');
        return $aqui;

    case 'comprar':
        requerir($u, 'alumno');
        $idi = $N('id_item'); $cant = $N('cantidad') ?: 1;
        if (!$idi) { falla('Falta el producto'); }
        if ($cant < 1 || $cant > 10) { falla('La cantidad debe estar entre 1 y 10'); }
        $item = fila('SELECT id, nombre, tipo, precio FROM tienda_items WHERE id = ? AND activo = 1', 'i', [$idi]);
        if (!$item) { falla('Producto no encontrado'); }
        if ($item['tipo'] === 'accesorio' && $cant !== 1) { falla('Los accesorios se compran de uno en uno'); }
        $total = (int)$item['precio'] * $cant;
        transaccion(function () use ($id, $idi, $cant, $item, $total) {
            $monedas = (int)fila('SELECT monedas FROM usuarios WHERE id = ? FOR UPDATE', 'i', [$id])['monedas'];
            if ($monedas < $total) { falla("No tienes suficientes monedas (necesitas $total y tienes $monedas)"); }
            if ($item['tipo'] === 'accesorio' && fila('SELECT 1 AS x FROM inventario WHERE id_alumno = ? AND id_item = ?', 'ii', [$id, $idi])) { falla('Ya tienes este accesorio'); }
            ejecutar('UPDATE usuarios SET monedas = monedas - ? WHERE id = ?', 'ii', [$total, $id]);
            ejecutar('INSERT INTO inventario (id_alumno, id_item, cantidad) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cantidad = cantidad + ?', 'iiii', [$id, $idi, $cant, $cant]);
            ejecutar('INSERT INTO transacciones_monedas (id_alumno, cantidad, motivo) VALUES (?, ?, ?)', 'iis', [$id, -$total, 'Compra: ' . $item['nombre'] . ($cant > 1 ? " x$cant" : '')]);
        });
        flash('ok', 'Compraste ' . $item['nombre'] . " (-$total monedas)");
        return $aqui;

    case 'alimentar':
        requerir($u, 'alumno');
        $idi = $N('id_item');
        if (!$idi) { falla('Falta la comida'); }
        $msg = transaccion(function () use ($id, $idi) {
            $m = obtener_mascota($id, true);
            if (!$m) { falla('Aún no tienes mascota'); }
            if ($m['saciedad'] >= SACIEDAD_MAX) { falla('Tu mascota está llena'); }
            $c = fila("SELECT i.nombre, i.efecto, inv.cantidad FROM inventario inv JOIN tienda_items i ON i.id = inv.id_item
                       WHERE inv.id_alumno = ? AND inv.id_item = ? AND i.tipo = 'comida' FOR UPDATE", 'ii', [$id, $idi]);
            if (!$c || (int)$c['cantidad'] < 1) { falla('No tienes esa comida en tu inventario'); }
            $nueva = min(SACIEDAD_MAX, $m['saciedad'] + (int)$c['efecto']);
            ejecutar('UPDATE inventario SET cantidad = cantidad - 1 WHERE id_alumno = ? AND id_item = ?', 'ii', [$id, $idi]);
            ejecutar('DELETE FROM inventario WHERE id_alumno = ? AND id_item = ? AND cantidad <= 0', 'ii', [$id, $idi]);
            ejecutar('UPDATE mascotas SET saciedad = ?, ultima_actualizacion = NOW() WHERE id_alumno = ?', 'ii', [$nueva, $id]);
            return $m['nombre'] . ' comió ' . $c['nombre'];
        });
        flash('ok', $msg);
        return $aqui;

    case 'equipar':
        requerir($u, 'alumno');
        $idi = $N('id_item'); $poner = ($_POST['equipar'] ?? '1') === '1';
        if (!$idi) { falla('Falta el accesorio'); }
        if (!obtener_mascota($id)) { falla('Aún no tienes mascota'); }
        $acc = fila("SELECT i.nombre, i.slot FROM inventario inv JOIN tienda_items i ON i.id = inv.id_item
                     WHERE inv.id_alumno = ? AND inv.id_item = ? AND i.tipo = 'accesorio'", 'ii', [$id, $idi]);
        if (!$acc) { falla('No tienes ese accesorio'); }
        transaccion(function () use ($id, $idi, $acc, $poner) {
            if ($poner) {
                ejecutar('UPDATE inventario inv JOIN tienda_items i ON i.id = inv.id_item SET inv.equipado = 0 WHERE inv.id_alumno = ? AND i.slot = ?', 'is', [$id, $acc['slot']]);
            }
            ejecutar('UPDATE inventario SET equipado = ? WHERE id_alumno = ? AND id_item = ?', 'iii', [$poner ? 1 : 0, $id, $idi]);
        });
        flash('ok', $acc['nombre'] . ($poner ? ' equipado' : ' guardado'));
        return $aqui;
    }
    falla('Acción desconocida');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { falla('Sesión expirada, intenta de nuevo'); }
        $destino = accion((string)($_POST['accion'] ?? ''), $u);
    } catch (Aviso $x) {
        flash('error', $x->getMessage());
        $destino = $_SERVER['REQUEST_URI'];
    }
    ir($destino);
}

/* ---------- Descarga de entregas (solo alumno dueño o profesor de la materia) ---------- */
if ($u && isset($_GET['descargar'])) {
    $ide = filter_var($_GET['descargar'], FILTER_VALIDATE_INT);
    $r = $ide ? fila('SELECT e.archivo, e.id_alumno, m.id_profesor FROM entregas e JOIN tareas t ON t.id = e.id_tarea
                      JOIN materias m ON m.id = t.id_materia WHERE e.id = ?', 'i', [$ide]) : null;
    $ruta = $r && $r['archivo'] && preg_match('/^\d+_[a-f0-9]{8}\.[a-z0-9]{2,5}$/', $r['archivo']) ? UPLOADS . $r['archivo'] : '';
    if (!$r || ((int)$r['id_alumno'] !== (int)$u['id'] && (int)$r['id_profesor'] !== (int)$u['id']) || !is_file($ruta)) {
        http_response_code(404); exit('Archivo no encontrado');
    }
    header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($ruta));
    header('Content-Disposition: attachment; filename="' . $r['archivo'] . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($ruta);
    exit;
}

/* ---------- Páginas ---------- */
$p = (string)($_GET['p'] ?? 'inicio');
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function abrir(string $titulo, ?array $u, string $activo, ?array $flash): void {
    $menu = ['inicio' => ['🏠', 'Inicio'], 'materias' => ['📚', 'Materias']];
    if ($u && $u['rol'] === 'alumno') {
        $menu += ['recordatorios' => ['🔔', 'Avisos'], 'mascota' => ['🐾', 'Mascota'], 'tienda' => ['🛒', 'Tienda'], 'inventario' => ['🎒', 'Mochila']];
    }
    $menu['perfil'] = ['👤', 'Perfil'];
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Bateilgo</title>
<link rel="stylesheet" href="estilos.css">
<script>try{var h=document.documentElement,t=localStorage.getItem('tema');if(t)h.dataset.theme=t;if(!sessionStorage.getItem('carga')&&!matchMedia('(prefers-reduced-motion: reduce)').matches)h.classList.add('con-carga')}catch(e){}</script>
</head>
<body>
<div class="pantalla-carga" id="carga" role="status" aria-label="Cargando Bateilgo">
  <video muted playsinline preload="auto" aria-hidden="true"><source src="carga.mp4" type="video/mp4"></video>
  <button type="button" class="saltar">Saltar</button>
</div>
<?php if ($u): ?>
<div class="app">
<nav class="lateral">
<?php foreach ($menu as $k => [$ic, $tx]): ?>
  <a href="?p=<?= $k ?>" class="<?= $k === $activo ? 'activo' : '' ?>"><?= $ic ?><span><?= $tx ?></span></a>
<?php endforeach; ?>
</nav>
<main class="contenido">
  <div class="barra">
    <?php if ($u['rol'] === 'alumno'): ?><span class="moneda">🪙 <?= (int)$u['monedas'] ?></span><?php endif; ?>
    <span><?= e($u['usuario']) ?> · <?= e($u['rol']) ?></span>
    <div class="avatar"></div>
  </div>
<?php else: ?>
<main class="contenido centro">
<?php endif;
    if ($flash) { echo '<div class="aviso-' . ($flash[0] === 'ok' ? 'ok' : 'error') . '" role="status">' . e($flash[1]) . '</div>'; }
}
function cerrar(?array $u): void {
    echo $u ? "</main>\n</div>\n" : "</main>\n";
    ?>
<script>
(function(){var h=document.documentElement,c=document.getElementById('carga');if(!c)return;
if(!h.classList.contains('con-carga')){c.remove();return}
var v=c.querySelector('video'),fin=false;
function salir(){if(fin)return;fin=true;try{sessionStorage.setItem('carga','1')}catch(e){}
c.classList.add('saliendo');setTimeout(function(){h.classList.remove('con-carga');c.remove()},500)}
v.addEventListener('ended',salir);v.addEventListener('error',salir);c.addEventListener('click',salir);
var p=v.play();if(p&&p.catch)p.catch(salir);
setTimeout(salir,7000);})();
</script>
</body>
</html><?php
}
function chip_urgencia(string $urg): array {
    return $urg === 'vencida' ? ['', '⚠️', 'Vencida'] : ($urg === 'hoy' ? ['azul', '⏰', 'Para hoy'] : ['verde', '📝', 'Próxima']);
}

/* --- Acceso (sin sesión) --- */
if (!$u) {
    $registro = $p === 'registro';
    abrir($registro ? 'Crear cuenta' : 'Entrar', null, '', $flash); ?>
<div class="portada" style="min-height:140px"><small>Bienvenido a</small><h1>Bateilgo</h1></div>
<div class="tarjeta">
  <h2><?= $registro ? 'Crear cuenta' : 'Iniciar sesión' ?></h2><br>
  <?php if ($registro) {
      echo form('registro', '<label>Usuario<input name="usuario" required minlength="3" maxlength="20"></label>
        <label>Contraseña<input type="password" name="password" required minlength="6" maxlength="72"></label>
        <label>Soy<select name="rol"><option value="alumno">Alumno</option><option value="profesor">Profesor</option></select></label>
        <button class="boton">Crear cuenta</button>');
      echo '<p class="sin" style="margin-top:12px">¿Ya tienes cuenta? <a href="?p=login"><b>Entrar</b></a></p>';
  } else {
      echo form('login', '<label>Usuario<input name="usuario" required></label>
        <label>Contraseña<input type="password" name="password" required></label>
        <button class="boton">Entrar</button>');
      echo '<p class="sin" style="margin-top:12px">¿No tienes cuenta? <a href="?p=registro"><b>Regístrate</b></a></p>';
  } ?>
</div>
<?php cerrar(null); exit; }

$id = (int)$u['id'];
$esAlumno = $u['rol'] === 'alumno';
$validas = ['inicio', 'materias', 'materia', 'entregas', 'perfil'];
if ($esAlumno) { array_push($validas, 'recordatorios', 'mascota', 'tienda', 'inventario'); }
if (!in_array($p, $validas, true)) { $p = 'inicio'; }
$activo = in_array($p, ['materia', 'entregas'], true) ? 'materias' : $p;

/* --- Inicio --- */
if ($p === 'inicio') {
    abrir('Inicio', $u, $activo, $flash);
    if ($esAlumno) {
        $mat = (int)fila('SELECT COUNT(*) AS c FROM clase_alumnos WHERE id_alumno = ?', 'i', [$id])['c'];
        $res = fila('SELECT COUNT(t.id) AS total, COALESCE(SUM(e.id IS NULL), 0) AS pend, COALESCE(SUM(e.id IS NULL AND t.fecha_entrega < NOW()), 0) AS venc
                     FROM clase_alumnos ca JOIN tareas t ON t.id_materia = ca.id_materia
                     LEFT JOIN entregas e ON e.id_tarea = t.id AND e.id_alumno = ca.id_alumno WHERE ca.id_alumno = ?', 'i', [$id]);
        $inv = (int)fila('SELECT COUNT(*) AS c FROM inventario WHERE id_alumno = ?', 'i', [$id])['c'];
        $prox = filas("SELECT t.id, t.titulo, t.fecha_entrega, m.id AS id_materia, m.nombre AS materia,
                CASE WHEN t.fecha_entrega < NOW() THEN 'vencida' WHEN t.fecha_entrega <= DATE_ADD(NOW(), INTERVAL 1 DAY) THEN 'hoy' ELSE 'proxima' END AS urgencia
                FROM clase_alumnos ca JOIN materias m ON m.id = ca.id_materia JOIN tareas t ON t.id_materia = m.id
                LEFT JOIN entregas e ON e.id_tarea = t.id AND e.id_alumno = ca.id_alumno
                WHERE ca.id_alumno = ? AND e.id IS NULL AND t.fecha_entrega >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY t.fecha_entrega LIMIT 5", 'i', [$id]);
        $cifras = [['amarilla', $mat, 'Materias'], ['', (int)$res['total'], 'Tareas'], ['azul', (int)$res['pend'], 'Pendientes'], ['lila', (int)$res['venc'], 'Vencidas'], ['', $inv, 'En mochila']];
    } else {
        $mat = (int)fila('SELECT COUNT(*) AS c FROM materias WHERE id_profesor = ?', 'i', [$id])['c'];
        $alu = (int)fila('SELECT COUNT(DISTINCT ca.id_alumno) AS c FROM materias m LEFT JOIN clase_alumnos ca ON ca.id_materia = m.id WHERE m.id_profesor = ?', 'i', [$id])['c'];
        $res = fila('SELECT (SELECT COUNT(*) FROM tareas t JOIN materias m ON m.id = t.id_materia WHERE m.id_profesor = ?) AS tareas,
                            (SELECT COUNT(*) FROM entregas e JOIN tareas t ON t.id = e.id_tarea JOIN materias m ON m.id = t.id_materia WHERE m.id_profesor = ?) AS entregas', 'ii', [$id, $id]);
        $prox = filas('SELECT t.id, t.titulo, t.fecha_entrega, m.id AS id_materia, m.nombre AS materia,
                (SELECT COUNT(*) FROM entregas e WHERE e.id_tarea = t.id) AS entregas
                FROM tareas t JOIN materias m ON m.id = t.id_materia
                WHERE m.id_profesor = ? AND t.fecha_entrega >= NOW() ORDER BY t.fecha_entrega LIMIT 5', 'i', [$id]);
        $cifras = [['amarilla', $mat, 'Materias'], ['azul', $alu, 'Alumnos'], ['', (int)$res['tareas'], 'Tareas'], ['lila', (int)$res['entregas'], 'Entregas']];
    } ?>
<section class="portada"><small>Hola, <?= e($u['usuario']) ?></small><h1>Bateilgo</h1>
  <p><?= $esAlumno ? 'Entrega tus tareas, gana monedas y cuida a tu mascota.' : 'Publica tareas y revisa las entregas de tus alumnos.' ?></p></section>
<section class="tarjeta"><div class="cifras">
<?php foreach ($cifras as [$c, $n, $t]): ?><div class="cifra <?= $c ?>"><strong><?= $n ?></strong><span><?= $t ?></span></div><?php endforeach; ?>
</div></section>
<section class="tarjeta">
  <div class="tarjeta-titulo"><h2><?= $esAlumno ? 'Próximas tareas' : 'Tareas por vencer' ?></h2><a class="ver-todas" href="?p=materias">Ver materias</a></div>
  <div class="lista">
  <?php if (!$prox) { echo '<p class="sin">No hay tareas por ahora 🎉</p>'; }
  foreach ($prox as $t): [$cls, $ic, $et] = $esAlumno ? chip_urgencia($t['urgencia']) : ['verde', '📝', 'Tarea']; ?>
    <a class="item" href="?p=materia&id=<?= (int)$t['id_materia'] ?>">
      <div class="item-icono <?= $cls ?>"><?= $ic ?></div>
      <div class="item-texto"><span class="etiqueta"><?= $et ?></span><h3><?= e($t['titulo']) ?></h3>
        <p><?= e($t['materia']) ?><?= $esAlumno ? '' : ' · ' . (int)$t['entregas'] . ' entregas' ?></p></div>
      <div class="item-fecha"><?= fmt($t['fecha_entrega']) ?></div>
    </a>
  <?php endforeach; ?>
  </div>
</section>
<?php cerrar($u); exit; }

/* --- Materias --- */
if ($p === 'materias') {
    abrir('Materias', $u, $activo, $flash);
    if (!$esAlumno) {
        $lista = filas('SELECT m.id, m.nombre, m.codigo, (SELECT COUNT(*) FROM clase_alumnos ca WHERE ca.id_materia = m.id) AS alumnos
                        FROM materias m WHERE m.id_profesor = ? ORDER BY m.nombre', 'i', [$id]);
    } else {
        $lista = filas('SELECT m.id, m.nombre, p.usuario AS profesor FROM clase_alumnos ca JOIN materias m ON m.id = ca.id_materia
                        JOIN usuarios p ON p.id = m.id_profesor WHERE ca.id_alumno = ? ORDER BY m.nombre', 'i', [$id]);
    } ?>
<h1 class="titulo-vista">Materias</h1>
<section class="tarjeta">
  <h2><?= $esAlumno ? 'Unirme con un código' : 'Crear materia' ?></h2><br>
  <?= $esAlumno
      ? form('unirse', '<label>Código<input name="codigo" placeholder="BTL-XXXX" required maxlength="8"></label><button class="boton">Unirme</button>')
      : form('crear_materia', '<label>Nombre de la materia<input name="nombre" required minlength="3" maxlength="60"></label><button class="boton">Crear materia</button>') ?>
</section>
<section class="tarjeta"><div class="lista">
<?php if (!$lista) { echo '<p class="sin">Aún no hay materias.</p>'; }
foreach ($lista as $m): ?>
  <a class="item" href="?p=materia&id=<?= (int)$m['id'] ?>">
    <div class="item-icono azul">📚</div>
    <div class="item-texto"><h3><?= e($m['nombre']) ?></h3>
      <p><?= $esAlumno ? 'Profesor: ' . e($m['profesor']) : 'Código ' . e($m['codigo']) . ' · ' . (int)$m['alumnos'] . ' alumnos' ?></p></div>
  </a>
<?php endforeach; ?>
</div></section>
<?php cerrar($u); exit; }

/* --- Una materia y sus tareas --- */
if ($p === 'materia') {
    $idm = (int)($_GET['id'] ?? 0);
    $m = !$esAlumno
        ? fila('SELECT id, nombre, codigo FROM materias WHERE id = ? AND id_profesor = ?', 'ii', [$idm, $id])
        : fila('SELECT m.id, m.nombre, m.codigo FROM materias m JOIN clase_alumnos ca ON ca.id_materia = m.id AND ca.id_alumno = ? WHERE m.id = ?', 'ii', [$id, $idm]);
    if (!$m) { flash('error', 'Materia no encontrada'); ir('?p=materias'); }
    if (!$esAlumno) {
        $tareas = filas('SELECT t.id, t.titulo, t.descripcion, t.fecha_entrega, (SELECT COUNT(*) FROM entregas e WHERE e.id_tarea = t.id) AS entregas
                         FROM tareas t WHERE t.id_materia = ? ORDER BY t.fecha_entrega', 'i', [$idm]);
    } else {
        $tareas = filas('SELECT t.id, t.titulo, t.descripcion, t.fecha_entrega, (e.id IS NOT NULL) AS entregada
                         FROM tareas t LEFT JOIN entregas e ON e.id_tarea = t.id AND e.id_alumno = ? WHERE t.id_materia = ? ORDER BY t.fecha_entrega', 'ii', [$id, $idm]);
    }
    abrir($m['nombre'], $u, $activo, $flash); ?>
<a class="ver-todas" href="?p=materias">← Materias</a>
<h1 class="titulo-vista"><?= e($m['nombre']) ?></h1>
<?php if (!$esAlumno): ?>
<p class="sin">Código para tus alumnos: <b><?= e($m['codigo']) ?></b></p>
<section class="tarjeta"><h2>Publicar tarea</h2><br>
  <?= form('crear_tarea', '<input type="hidden" name="id_materia" value="' . $idm . '">
    <label>Título<input name="titulo" required minlength="3" maxlength="100"></label>
    <label>Descripción<textarea name="descripcion" rows="3" maxlength="2000"></textarea></label>
    <label>Fecha de entrega<input type="datetime-local" name="fecha_entrega" required></label>
    <button class="boton">Publicar</button>') ?>
</section>
<?php endif; ?>
<section class="tarjeta"><h2>Tareas</h2><br><div class="lista">
<?php if (!$tareas) { echo '<p class="sin">Todavía no hay tareas.</p>'; }
foreach ($tareas as $t):
    $hecha = $esAlumno && $t['entregada']; ?>
  <div>
    <div class="item <?= $hecha ? 'hecha' : '' ?>">
      <div class="item-icono <?= $hecha ? 'verde' : 'azul' ?>"><?= $hecha ? '✅' : '📝' ?></div>
      <div class="item-texto"><h3><?= e($t['titulo']) ?></h3>
        <p><?= e($t['descripcion']) ?><?= !$esAlumno ? ' · ' . (int)$t['entregas'] . ' entregas' : '' ?></p></div>
      <div class="item-fecha"><?= fmt($t['fecha_entrega']) ?></div>
    </div>
    <?php if (!$esAlumno): ?>
    <details><summary>Opciones</summary>
      <div class="fila" style="margin:8px 0"><a class="boton suave" href="?p=entregas&id=<?= (int)$t['id'] ?>">Ver entregas</a>
        <?= form('eliminar_tarea', '<input type="hidden" name="id_tarea" value="' . (int)$t['id'] . '"><button class="boton suave">Eliminar</button>', 'onsubmit="return confirm(\'¿Eliminar la tarea y sus entregas?\')"') ?></div>
      <?= form('editar_tarea', '<input type="hidden" name="id_tarea" value="' . (int)$t['id'] . '">
        <label>Título<input name="titulo" value="' . e($t['titulo']) . '" required minlength="3" maxlength="100"></label>
        <label>Descripción<textarea name="descripcion" rows="3" maxlength="2000">' . e($t['descripcion']) . '</textarea></label>
        <label>Fecha de entrega<input type="datetime-local" name="fecha_entrega" value="' . date('Y-m-d\TH:i', strtotime($t['fecha_entrega'])) . '" required></label>
        <button class="boton">Guardar cambios</button>') ?>
    </details>
    <?php elseif (!$hecha): ?>
    <details><summary>Entregar</summary>
      <?= form('entregar', '<input type="hidden" name="id_tarea" value="' . (int)$t['id'] . '">
        <label>Archivo (opcional, máx. 10 MB)<input type="file" name="archivo"></label>
        <label>Link (opcional)<input type="url" name="link" placeholder="https://..."></label>
        <label>Comentario<textarea name="comentario" rows="2" maxlength="500"></textarea></label>
        <button class="boton">Entregar (+' . RECOMPENSA . ' 🪙)</button>', 'enctype="multipart/form-data"') ?>
    </details>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div></section>
<?php cerrar($u); exit; }

/* --- Entregas de una tarea (profesor) --- */
if ($p === 'entregas') {
    $idt = (int)($_GET['id'] ?? 0);
    $t = !$esAlumno ? fila('SELECT t.id, t.titulo, t.fecha_entrega, t.id_materia FROM tareas t JOIN materias m ON m.id = t.id_materia WHERE t.id = ? AND m.id_profesor = ?', 'ii', [$idt, $id]) : null;
    if (!$t) { flash('error', 'Tarea no encontrada'); ir('?p=materias'); }
    $ent = filas('SELECT e.id AS id_entrega, u.usuario AS alumno, e.archivo, e.link, e.comentario, e.fecha_envio, (e.fecha_envio > ?) AS tarde
                  FROM entregas e JOIN usuarios u ON u.id = e.id_alumno WHERE e.id_tarea = ? ORDER BY e.fecha_envio', 'si', [$t['fecha_entrega'], $idt]);
    $faltan = filas('SELECT u.usuario FROM clase_alumnos ca JOIN usuarios u ON u.id = ca.id_alumno
                     LEFT JOIN entregas e ON e.id_tarea = ? AND e.id_alumno = ca.id_alumno WHERE ca.id_materia = ? AND e.id IS NULL ORDER BY u.usuario', 'ii', [$idt, $t['id_materia']]);
    abrir('Entregas', $u, $activo, $flash); ?>
<a class="ver-todas" href="?p=materia&id=<?= (int)$t['id_materia'] ?>">← Tareas</a>
<h1 class="titulo-vista"><?= e($t['titulo']) ?></h1>
<p class="sin">Fecha límite: <?= fmt($t['fecha_entrega']) ?></p>
<section class="tarjeta"><h2>Entregadas (<?= count($ent) ?>)</h2><br><div class="lista">
<?php if (!$ent) { echo '<p class="sin">Nadie ha entregado todavía.</p>'; }
foreach ($ent as $x): ?>
  <div class="item">
    <div class="item-icono verde">✅</div>
    <div class="item-texto"><h3><?= e($x['alumno']) ?><?= $x['tarde'] ? ' · tarde' : '' ?></h3>
      <p><?php if ($x['archivo']) { echo '<a href="?descargar=' . (int)$x['id_entrega'] . '"><b>Descargar archivo</b></a> '; }
               if ($x['link']) { echo '<a href="' . e($x['link']) . '" target="_blank" rel="noopener noreferrer"><b>Abrir link</b></a> '; }
               echo e($x['comentario']); ?></p></div>
    <div class="item-fecha"><?= fmt($x['fecha_envio']) ?></div>
  </div>
<?php endforeach; ?>
</div></section>
<section class="tarjeta"><h2>Faltan (<?= count($faltan) ?>)</h2><br><div class="chips">
<?php foreach ($faltan as $x) { echo '<span class="chip">' . e($x['usuario']) . '</span>'; } ?>
</div></section>
<?php cerrar($u); exit; }

/* --- Recordatorios (alumno) --- */
if ($p === 'recordatorios') {
    $dias = max(1, min(30, (int)filter_input(INPUT_GET, 'dias', FILTER_VALIDATE_INT) ?: 3));
    $pend = filas("SELECT t.id, t.titulo, t.fecha_entrega, m.id AS id_materia, m.nombre AS materia,
            CASE WHEN t.fecha_entrega < NOW() THEN 'vencida' WHEN t.fecha_entrega < DATE_ADD(NOW(), INTERVAL 1 DAY) THEN 'hoy' ELSE 'proxima' END AS urgencia
            FROM clase_alumnos ca JOIN materias m ON m.id = ca.id_materia JOIN tareas t ON t.id_materia = m.id
            LEFT JOIN entregas e ON e.id_tarea = t.id AND e.id_alumno = ca.id_alumno
            WHERE ca.id_alumno = ? AND e.id IS NULL AND t.fecha_entrega <= DATE_ADD(NOW(), INTERVAL ? DAY)
              AND t.fecha_entrega >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY t.fecha_entrega LIMIT 100", 'ii', [$id, $dias]);
    abrir('Avisos', $u, $activo, $flash); ?>
<h1 class="titulo-vista">Recordatorios</h1>
<div class="chips" style="margin-top:12px">
<?php foreach ([1, 3, 7, 14] as $d) { echo '<a class="chip ' . ($d === $dias ? 'activo' : '') . '" href="?p=recordatorios&dias=' . $d . '">' . $d . ' día' . ($d > 1 ? 's' : '') . '</a>'; } ?>
</div>
<section class="tarjeta"><div class="lista">
<?php if (!$pend) { echo '<p class="sin">Sin tareas pendientes en este periodo 🎉</p>'; }
foreach ($pend as $t): [$cls, $ic, $et] = chip_urgencia($t['urgencia']); ?>
  <a class="item" href="?p=materia&id=<?= (int)$t['id_materia'] ?>">
    <div class="item-icono <?= $cls ?>"><?= $ic ?></div>
    <div class="item-texto"><span class="etiqueta"><?= $et ?></span><h3><?= e($t['titulo']) ?></h3><p><?= e($t['materia']) ?></p></div>
    <div class="item-fecha"><?= fmt($t['fecha_entrega']) ?></div>
  </a>
<?php endforeach; ?>
</div></section>
<?php cerrar($u); exit; }

/* --- Mascota --- */
if ($p === 'mascota') {
    $m = obtener_mascota($id);
    abrir('Mascota', $u, $activo, $flash); ?>
<h1 class="titulo-vista">Mi mascota</h1>
<?php if (!$m): ?>
<section class="tarjeta"><h2>Adopta una mascota</h2><br>
  <?= form('crear_mascota', '<label>Nombre<input name="nombre" required minlength="2" maxlength="20"></label>
    <label>Especie<select name="especie">' . implode('', array_map(fn($k, $v) => "<option value=\"$k\">$v " . ucfirst($k) . '</option>', array_keys(ESPECIES), ESPECIES)) . '</select></label>
    <button class="boton">Adoptar</button>') ?>
</section>
<?php else:
    $acc = accesorios_equipados($id);
    $comidas = filas("SELECT i.id, i.nombre, i.emoji, i.efecto, inv.cantidad FROM inventario inv JOIN tienda_items i ON i.id = inv.id_item
                      WHERE inv.id_alumno = ? AND i.tipo = 'comida' AND inv.cantidad > 0 ORDER BY i.nombre", 'i', [$id]); ?>
<section class="tarjeta">
  <div class="grande"><?= ESPECIES[$m['especie']] ?></div>
  <h2 style="text-align:center;margin-top:8px"><?= e($m['nombre']) ?> <span class="sin">(<?= e($m['estado']) ?>)</span></h2>
  <p style="text-align:center;font-size:1.6rem"><?php foreach ($acc as $a) { echo e($a['emoji']) . ' '; } ?></p>
  <p class="sin">Saciedad: <?= (int)$m['saciedad'] ?>/<?= SACIEDAD_MAX ?></p>
  <progress max="<?= SACIEDAD_MAX ?>" value="<?= (int)$m['saciedad'] ?>"></progress>
</section>
<section class="tarjeta"><h2>Darle de comer</h2><br><div class="lista">
<?php if (!$comidas) { echo '<p class="sin">No tienes comida. Visita la <a href="?p=tienda"><b>tienda</b></a>.</p>'; }
foreach ($comidas as $c): ?>
  <div class="item">
    <div class="item-icono verde"><?= e($c['emoji']) ?></div>
    <div class="item-texto"><h3><?= e($c['nombre']) ?> ×<?= (int)$c['cantidad'] ?></h3><p>+<?= (int)$c['efecto'] ?> saciedad</p></div>
    <?= form('alimentar', '<input type="hidden" name="id_item" value="' . (int)$c['id'] . '"><button class="boton">Alimentar</button>') ?>
  </div>
<?php endforeach; ?>
</div></section>
<?php endif; cerrar($u); exit; }

/* --- Tienda --- */
if ($p === 'tienda') {
    $items = filas('SELECT i.id, i.nombre, i.tipo, i.precio, i.efecto, i.slot, i.emoji, COALESCE(inv.cantidad, 0) AS tienes
                    FROM tienda_items i LEFT JOIN inventario inv ON inv.id_item = i.id AND inv.id_alumno = ?
                    WHERE i.activo = 1 ORDER BY i.tipo, i.precio', 'i', [$id]);
    abrir('Tienda', $u, $activo, $flash); ?>
<h1 class="titulo-vista">Tienda</h1>
<p class="sin">Tienes <b><?= (int)$u['monedas'] ?></b> monedas</p>
<section class="tarjeta"><div class="lista">
<?php foreach ($items as $i): $acc = $i['tipo'] === 'accesorio'; ?>
  <div class="item">
    <div class="item-icono <?= $acc ? 'azul' : 'verde' ?>"><?= e($i['emoji']) ?></div>
    <div class="item-texto"><span class="etiqueta"><?= $acc ? 'accesorio · ' . e($i['slot']) : 'comida' ?></span><h3><?= e($i['nombre']) ?> · 🪙 <?= (int)$i['precio'] ?></h3>
      <p><?= $acc ? '' : '+' . (int)$i['efecto'] . ' saciedad · ' ?>Tienes <?= (int)$i['tienes'] ?></p></div>
    <?php if ($acc && $i['tienes'] > 0) { echo '<span class="sin">Ya lo tienes</span>'; }
    else { echo form('comprar', '<input type="hidden" name="id_item" value="' . (int)$i['id'] . '">'
        . ($acc ? '' : '<input type="number" name="cantidad" value="1" min="1" max="10" style="width:70px" aria-label="Cantidad">')
        . '<button class="boton">Comprar</button>', 'style="display:flex;gap:8px"'); } ?>
  </div>
<?php endforeach; ?>
</div></section>
<?php cerrar($u); exit; }

/* --- Inventario --- */
if ($p === 'inventario') {
    $items = filas('SELECT i.id, i.nombre, i.tipo, i.efecto, i.slot, i.emoji, inv.cantidad, inv.equipado
                    FROM inventario inv JOIN tienda_items i ON i.id = inv.id_item WHERE inv.id_alumno = ? AND inv.cantidad > 0 ORDER BY i.tipo, i.nombre', 'i', [$id]);
    abrir('Mochila', $u, $activo, $flash); ?>
<h1 class="titulo-vista">Mi mochila</h1>
<section class="tarjeta"><div class="lista">
<?php if (!$items) { echo '<p class="sin">Tu mochila está vacía. Visita la <a href="?p=tienda"><b>tienda</b></a>.</p>'; }
foreach ($items as $i): $acc = $i['tipo'] === 'accesorio'; ?>
  <div class="item">
    <div class="item-icono <?= $acc ? 'azul' : 'verde' ?>"><?= e($i['emoji']) ?></div>
    <div class="item-texto"><h3><?= e($i['nombre']) ?><?= $acc ? '' : ' ×' . (int)$i['cantidad'] ?></h3>
      <p><?= $acc ? e($i['slot']) . ($i['equipado'] ? ' · equipado' : '') : '+' . (int)$i['efecto'] . ' saciedad' ?></p></div>
    <?= $acc
        ? form('equipar', '<input type="hidden" name="id_item" value="' . (int)$i['id'] . '"><input type="hidden" name="equipar" value="' . ($i['equipado'] ? '0' : '1') . '"><button class="boton suave">' . ($i['equipado'] ? 'Quitar' : 'Equipar') . '</button>')
        : form('alimentar', '<input type="hidden" name="id_item" value="' . (int)$i['id'] . '"><button class="boton">Alimentar</button>') ?>
  </div>
<?php endforeach; ?>
</div></section>
<?php cerrar($u); exit; }

/* --- Perfil --- */
abrir('Perfil', $u, $activo, $flash);
$mov = $esAlumno ? filas('SELECT cantidad, motivo, creado FROM transacciones_monedas WHERE id_alumno = ? ORDER BY id DESC LIMIT 20', 'i', [$id]) : []; ?>
<h1 class="titulo-vista">Perfil</h1>
<section class="tarjeta">
  <div class="ajuste"><span>Usuario</span><b><?= e($u['usuario']) ?></b></div>
  <div class="ajuste"><span>Rol</span><b><?= e($u['rol']) ?></b></div>
  <?php if ($esAlumno): ?><div class="ajuste"><span>Monedas</span><b>🪙 <?= (int)$u['monedas'] ?></b></div><?php endif; ?>
  <div class="ajuste"><label for="oscuro">Modo oscuro</label><input type="checkbox" id="oscuro"></div>
  <br><?= form('logout', '<button class="boton suave">Cerrar sesión</button>') ?>
</section>
<?php if ($esAlumno): ?>
<section class="tarjeta"><h2>Movimientos de monedas</h2><br><div class="lista">
<?php if (!$mov) { echo '<p class="sin">Aún no tienes movimientos.</p>'; }
foreach ($mov as $x): ?>
  <div class="item"><div class="item-icono <?= $x['cantidad'] > 0 ? 'verde' : '' ?>"><?= $x['cantidad'] > 0 ? '➕' : '➖' ?></div>
    <div class="item-texto"><h3><?= (int)$x['cantidad'] > 0 ? '+' : '' ?><?= (int)$x['cantidad'] ?> 🪙</h3><p><?= e($x['motivo']) ?></p></div>
    <div class="item-fecha"><?= fmt($x['creado']) ?></div></div>
<?php endforeach; ?>
</div></section>
<?php endif; ?>
<script>
(function(){var c=document.getElementById('oscuro'),h=document.documentElement;
c.checked=h.dataset.theme?h.dataset.theme==='dark':matchMedia('(prefers-color-scheme: dark)').matches;
c.addEventListener('change',function(){var t=c.checked?'dark':'light';h.dataset.theme=t;try{localStorage.setItem('tema',t)}catch(e){}});})();
</script>
<?php cerrar($u);
