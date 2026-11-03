<?php
/**
 * Spenpy media manager API  (used by admin.html)
 *
 * - Only logged-in admins can use it (same login as the Mart)
 * - Only touches files inside /images and /videos
 * - Only allows jpg, png, webp, mp4, webm (content is verified, not just the name)
 * - Keeps a copy of every original in /_media_backup so "Restore original" works
 */

const MAX_BYTES = 314572800;            // 300 MB per file
const IMG_EXT   = ['jpg', 'jpeg', 'png', 'webp'];
const VID_EXT   = ['mp4', 'webm'];

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$ROOT   = __DIR__;
$BACKUP = $ROOT . '/_media_backup';
$TMP    = $ROOT . '/_media_tmp';

function out($data, $code = 200) {
    json_out($data, $code);
}

/* ---------- admin login required (same login as the shop) ---------- */
require __DIR__ . '/lib.php';
$__c = cfg();
if (!$__c) {
    fail('Set up the shop login first: open mart-setup.php (see ADMIN-GUIDE.txt).', 503);
}
require_admin();

/* ---------- basic request protection ---------- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $xr = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '';
    if ($xr !== 'spenpy-admin') {
        fail('Blocked request.', 403);
    }
}

$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

/* ---------- helpers ---------- */
function ext_of($p) {
    return strtolower(pathinfo($p, PATHINFO_EXTENSION));
}
function clean_path($p) {
    $p = str_replace('\\', '/', trim((string)$p));
    if (!preg_match('#^(images|videos)/([A-Za-z0-9_\-]+/)*[A-Za-z0-9_\-]+\.[A-Za-z0-9]{2,4}$#', $p)) {
        fail('That file path is not allowed.');
    }
    $e = ext_of($p);
    if (!in_array($e, IMG_EXT, true) && !in_array($e, VID_EXT, true)) {
        fail('That file type is not allowed. Use JPG, PNG, WEBP or MP4.');
    }
    return $p;
}
function ensure_dir($dir) {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            fail('The server could not create a folder. Check that the website folder is writable.', 500);
        }
    }
}
function guard_backup_dir($dir) {
    ensure_dir($dir);
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
}
function move_file($from, $to) {
    if (@rename($from, $to)) return true;
    if (@copy($from, $to)) { @unlink($from); return true; }
    return false;
}
function backup_state($rel) {
    global $BACKUP;
    return file_exists($BACKUP . '/' . $rel) || file_exists($BACKUP . '/' . $rel . '.none');
}
/** Remember what the file looked like before the first change. */
function remember_original($rel) {
    global $ROOT, $BACKUP;
    if (backup_state($rel)) return;
    guard_backup_dir($BACKUP);
    $src = $ROOT . '/' . $rel;
    if (file_exists($src)) {
        $dst = $BACKUP . '/' . $rel;
        ensure_dir(dirname($dst));
        @copy($src, $dst);
    } else {
        $mk = $BACKUP . '/' . $rel . '.none';
        ensure_dir(dirname($mk));
        @file_put_contents($mk, 'new');
    }
}
function verify_content($file, $ext) {
    if (in_array($ext, IMG_EXT, true)) {
        $info = @getimagesize($file);
        if (!$info) return 'This file is not a valid image.';
        $t = $info[2];
        $ok = ($ext === 'png' && $t === IMAGETYPE_PNG)
           || (($ext === 'jpg' || $ext === 'jpeg') && $t === IMAGETYPE_JPEG)
           || ($ext === 'webp' && defined('IMAGETYPE_WEBP') && $t === IMAGETYPE_WEBP);
        return $ok ? '' : 'The image format does not match the slot (' . strtoupper($ext) . ').';
    }
    $h = @file_get_contents($file, false, null, 0, 12);
    if ($h === false || strlen($h) < 8) return 'This file is not a valid video.';
    if ($ext === 'mp4') {
        return substr($h, 4, 4) === 'ftyp' ? '' : 'This file is not a valid MP4 video.';
    }
    if ($ext === 'webm') {
        return substr($h, 0, 4) === "\x1A\x45\xDF\xA3" ? '' : 'This file is not a valid WEBM video.';
    }
    return 'Unsupported file.';
}
function scan_dir($base, $root, &$list) {
    global $BACKUP;
    if (!is_dir($base)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        $e = ext_of($rel);
        if (!in_array($e, IMG_EXT, true) && !in_array($e, VID_EXT, true)) continue;
        $list[] = [
            'path'   => $rel,
            'size'   => $f->getSize(),
            'mtime'  => $f->getMTime(),
            'type'   => in_array($e, VID_EXT, true) ? 'video' : 'image',
            'custom' => backup_state($rel),
        ];
    }
    // files that were deleted from the server but can be restored
}

/* ---------- actions ---------- */
if ($action === 'ping') {
    $to_bytes = function ($v) {
        $v = trim((string)$v); if ($v === '') return 0;
        $n = (float)$v; $u = strtolower(substr($v, -1));
        if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
        return (int)$n;
    };
    out([
        'ok'          => true,
        'php'         => PHP_VERSION,
        'writable'    => is_writable($ROOT),
        'imagesDir'   => is_dir($ROOT . '/images') ? is_writable($ROOT . '/images') : null,
        'videosDir'   => is_dir($ROOT . '/videos') ? is_writable($ROOT . '/videos') : null,
        'uploadMax'   => $to_bytes(ini_get('upload_max_filesize')),
        'postMax'     => $to_bytes(ini_get('post_max_size')),
        'maxBytes'    => MAX_BYTES,
    ]);
}

if ($action === 'list') {
    $list = [];
    scan_dir($ROOT . '/images', $ROOT, $list);
    scan_dir($ROOT . '/videos', $ROOT, $list);
    // include files that were removed through the admin and can be restored
    $removed = [];
    if (is_dir($BACKUP)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($BACKUP, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($BACKUP) + 1));
            if (substr($rel, -5) === '.none' || basename($rel) === '.htaccess') continue;
            if (!file_exists($ROOT . '/' . $rel)) $removed[] = $rel;
        }
    }
    out(['ok' => true, 'files' => $list, 'removed' => $removed]);
}

if ($method !== 'POST') {
    fail('Unknown request.', 404);
}

if ($action === 'upload') {
    $path  = clean_path(isset($_POST['path']) ? $_POST['path'] : '');
    $ext   = ext_of($path);
    $id    = isset($_POST['id']) ? (string)$_POST['id'] : '';
    $index = isset($_POST['index']) ? (int)$_POST['index'] : -1;
    $total = isset($_POST['total']) ? (int)$_POST['total'] : 0;
    if (!preg_match('/^[A-Za-z0-9]{8,40}$/', $id) || $index < 0 || $total < 1 || $index >= $total) {
        fail('Bad upload request.');
    }
    if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        $code = isset($_FILES['chunk']) ? $_FILES['chunk']['error'] : -1;
        fail('The upload was interrupted (code ' . $code . '). Try again.');
    }
    guard_backup_dir($TMP);
    $part = $TMP . '/' . $id . '.part';
    if ($index === 0 && file_exists($part)) @unlink($part);
    if ($index > 0 && !file_exists($part)) fail('Upload session expired. Please try again.');

    $in  = @fopen($_FILES['chunk']['tmp_name'], 'rb');
    $outh = @fopen($part, 'ab');
    if (!$in || !$outh) fail('The server could not store the upload.', 500);
    stream_copy_to_stream($in, $outh);
    fclose($in); fclose($outh);
    clearstatcache(true, $part);
    if (filesize($part) > MAX_BYTES) {
        @unlink($part);
        fail('That file is too large (limit 300 MB).');
    }
    if ($index + 1 < $total) {
        out(['ok' => true, 'done' => false]);
    }

    // last chunk: verify and publish
    $problem = verify_content($part, $ext);
    if ($problem !== '') { @unlink($part); fail($problem); }

    remember_original($path);
    $dest = $ROOT . '/' . $path;
    ensure_dir(dirname($dest));
    if (!move_file($part, $dest)) {
        @unlink($part);
        fail('The server could not save the file. Check folder permissions.', 500);
    }
    @chmod($dest, 0644);
    clearstatcache(true, $dest);
    out(['ok' => true, 'done' => true, 'path' => $path, 'size' => filesize($dest), 'mtime' => filemtime($dest), 'custom' => true]);
}

if ($action === 'delete') {
    $path = clean_path(isset($_POST['path']) ? $_POST['path'] : '');
    $dest = $ROOT . '/' . $path;
    if (!is_file($dest)) fail('That file does not exist.', 404);
    remember_original($path);
    if (!@unlink($dest)) fail('The server could not delete the file.', 500);
    out(['ok' => true, 'path' => $path]);
}

if ($action === 'restore') {
    $path = clean_path(isset($_POST['path']) ? $_POST['path'] : '');
    $dest = $ROOT . '/' . $path;
    $bak  = $BACKUP . '/' . $path;
    if (file_exists($bak)) {
        ensure_dir(dirname($dest));
        if (!@copy($bak, $dest)) fail('The server could not restore the file.', 500);
        @chmod($dest, 0644);
        @unlink($bak);
        out(['ok' => true, 'path' => $path, 'restored' => true]);
    }
    if (file_exists($bak . '.none')) {
        if (is_file($dest)) @unlink($dest);
        @unlink($bak . '.none');
        out(['ok' => true, 'path' => $path, 'restored' => false, 'removed' => true]);
    }
    fail('There is no earlier version of this file to restore.', 404);
}

fail('Unknown request.', 404);
