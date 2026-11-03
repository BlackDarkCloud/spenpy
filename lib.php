<?php
/**
 * Shared helpers: configuration, database, login session.
 * Used by mart-api.php (shop), api.php (admin media) and mart-setup.php.
 * Not meant to be opened in a browser.
 */
if (!defined('SPENPY')) { define('SPENPY', 1); }

function cfg() {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        $c = file_exists($f) ? include $f : false;
        if (!is_array($c)) { $c = false; }
    }
    return $c;
}
function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function fail($msg, $code = 400) {
    json_out(['ok' => false, 'error' => $msg], $code);
}
function T($name) {
    $c = cfg();
    $p = ($c && isset($c['prefix'])) ? preg_replace('/[^A-Za-z0-9_]/', '', $c['prefix']) : 'spenpy_';
    return $p . $name;
}
function db() {
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    $c = cfg();
    if (!$c || empty($c['db_name'])) {
        fail('The shop is not set up yet. Open mart-setup.php first.', 503);
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
            $c['db_user'], $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (PDOException $e) {
        fail('Could not connect to the database. Check the details in config.php.', 503);
    }
    return $pdo;
}
function is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}
function start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name('spenpy_sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function current_user() {
    static $u = false;
    start_session();
    if (empty($_SESSION['uid'])) { return null; }
    if ($u === false || $u === null || (int)$u['id'] !== (int)$_SESSION['uid']) {
        $st = db()->prepare('SELECT id, name, email, phone, address, city, role FROM ' . T('users') . ' WHERE id = ?');
        $st->execute([(int)$_SESSION['uid']]);
        $row = $st->fetch();
        if (!$row) { unset($_SESSION['uid']); return null; }
        $u = $row;
    }
    return $u;
}
function require_login() {
    $u = current_user();
    if (!$u) { fail('Please log in first.', 401); }
    return $u;
}
function require_admin() {
    $u = require_login();
    if ($u['role'] !== 'admin') { fail('Admins only.', 403); }
    return $u;
}
/** Mutating requests must come from our own pages (custom header + POST). */
function require_post() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { fail('Unknown request.', 405); }
    $xr = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '';
    if ($xr !== 'spenpy' && $xr !== 'spenpy-admin') { fail('Blocked request.', 403); }
}
function body() {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw === false ? '' : $raw, true);
    return is_array($j) ? $j : [];
}
function now() { return date('Y-m-d H:i:s'); }

function install_schema($pdo) {
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . T('users') . ' (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(40) NOT NULL DEFAULT "",
        address VARCHAR(255) NOT NULL DEFAULT "",
        city VARCHAR(100) NOT NULL DEFAULT "",
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT "customer",
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_email (email)
    ) ' . $opt);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . T('products') . ' (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        category VARCHAR(80) NOT NULL DEFAULT "",
        description TEXT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        stock INT NOT NULL DEFAULT 0,
        image VARCHAR(255) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_active (active)
    ) ' . $opt);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . T('orders') . ' (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT "pending",
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        name VARCHAR(120) NOT NULL,
        phone VARCHAR(40) NOT NULL,
        address VARCHAR(255) NOT NULL,
        city VARCHAR(100) NOT NULL DEFAULT "",
        note TEXT NULL,
        payment VARCHAR(30) NOT NULL DEFAULT "pay_on_delivery",
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_user (user_id),
        KEY idx_status (status)
    ) ' . $opt);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . T('order_items') . ' (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        name VARCHAR(160) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        qty INT NOT NULL,
        KEY idx_order (order_id)
    ) ' . $opt);
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . T('login_attempts') . ' (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        at DATETIME NOT NULL,
        KEY idx_ip (ip)
    ) ' . $opt);
}
