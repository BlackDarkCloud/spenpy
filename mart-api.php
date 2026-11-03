<?php
/**
 * Spenpy Mart API  (used by mart.html and admin.html)
 * Customers: register, login, profile, browse products, place and view orders.
 * Admins (same login): manage products, process orders, see customers.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
require __DIR__ . '/lib.php';

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
$pdo = null;

const ORDER_STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

function clean_str($v, $max) {
    $v = trim((string)$v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}
function user_public($u) {
    return ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'address' => $u['address'], 'city' => $u['city'], 'role' => $u['role']];
}
function product_row($p) {
    return [
        'id' => (int)$p['id'], 'name' => $p['name'], 'category' => $p['category'], 'description' => (string)$p['description'],
        'price' => (float)$p['price'], 'stock' => (int)$p['stock'], 'active' => (int)$p['active'],
        'image' => $p['image'] ? $p['image'] : null, 'v' => strtotime($p['updated_at']),
    ];
}
function load_orders($where, $params) {
    $st = db()->prepare('SELECT o.*, u.email AS user_email FROM ' . T('orders') . ' o LEFT JOIN ' . T('users') . ' u ON u.id = o.user_id ' . $where . ' ORDER BY o.id DESC LIMIT 300');
    $st->execute($params);
    $orders = $st->fetchAll();
    if (!$orders) { return []; }
    $ids = array_map(function ($o) { return (int)$o['id']; }, $orders);
    $in = implode(',', $ids);
    $items = db()->query('SELECT order_id, product_id, name, price, qty FROM ' . T('order_items') . ' WHERE order_id IN (' . $in . ')')->fetchAll();
    $by = [];
    foreach ($items as $it) { $by[(int)$it['order_id']][] = ['product_id' => (int)$it['product_id'], 'name' => $it['name'], 'price' => (float)$it['price'], 'qty' => (int)$it['qty']]; }
    $out = [];
    foreach ($orders as $o) {
        $out[] = [
            'id' => (int)$o['id'], 'status' => $o['status'], 'total' => (float)$o['total'], 'name' => $o['name'], 'phone' => $o['phone'],
            'address' => $o['address'], 'city' => $o['city'], 'note' => (string)$o['note'], 'payment' => $o['payment'],
            'created_at' => $o['created_at'], 'email' => $o['user_email'], 'items' => isset($by[(int)$o['id']]) ? $by[(int)$o['id']] : [],
        ];
    }
    return $out;
}
function restock_order($orderId) {
    $st = db()->prepare('SELECT product_id, qty FROM ' . T('order_items') . ' WHERE order_id = ?');
    $st->execute([$orderId]);
    $up = db()->prepare('UPDATE ' . T('products') . ' SET stock = stock + ? WHERE id = ?');
    foreach ($st->fetchAll() as $it) { $up->execute([(int)$it['qty'], (int)$it['product_id']]); }
}
function client_ip() { return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '0'; }

/* ---------------------------------------------------------------- public */
if ($action === 'config') {
    $c = cfg();
    json_out(['ok' => true, 'currency' => $c ? $c['currency'] : 'GH₵', 'whatsapp' => $c ? $c['whatsapp'] : '', 'ready' => (bool)$c]);
}
if ($action === 'products') {
    $sql = 'SELECT * FROM ' . T('products') . ' WHERE active = 1 ORDER BY id DESC';
    $rows = db()->query($sql)->fetchAll();
    json_out(['ok' => true, 'products' => array_map('product_row', $rows)]);
}
if ($action === 'me') {
    $u = current_user();
    json_out(['ok' => true, 'user' => $u ? user_public($u) : null]);
}

/* ------------------------------------------------------------- customers */
if ($action === 'my_orders') {
    $u = require_login();
    json_out(['ok' => true, 'orders' => load_orders('WHERE o.user_id = ?', [(int)$u['id']])]);
}

/* ---------------------------------------------------------------- admin GET */
if ($action === 'admin_products') {
    require_admin();
    $rows = db()->query('SELECT * FROM ' . T('products') . ' ORDER BY id DESC')->fetchAll();
    json_out(['ok' => true, 'products' => array_map('product_row', $rows)]);
}
if ($action === 'admin_orders') {
    require_admin();
    $s = isset($_GET['status']) ? (string)$_GET['status'] : '';
    if ($s !== '' && in_array($s, ORDER_STATUSES, true)) {
        json_out(['ok' => true, 'orders' => load_orders('WHERE o.status = ?', [$s])]);
    }
    json_out(['ok' => true, 'orders' => load_orders('', [])]);
}
if ($action === 'admin_customers') {
    require_admin();
    $rows = db()->query('SELECT u.id, u.name, u.email, u.phone, u.address, u.city, u.role, u.created_at,
        (SELECT COUNT(*) FROM ' . T('orders') . ' o WHERE o.user_id = u.id) AS orders
        FROM ' . T('users') . ' u ORDER BY u.id DESC LIMIT 500')->fetchAll();
    json_out(['ok' => true, 'customers' => $rows]);
}
if ($action === 'admin_stats') {
    require_admin();
    $p = db()->query('SELECT COUNT(*) AS n FROM ' . T('products'))->fetch();
    $o = db()->query('SELECT COUNT(*) AS n, SUM(status="pending") AS pending, COALESCE(SUM(CASE WHEN status<>"cancelled" THEN total END),0) AS revenue FROM ' . T('orders'))->fetch();
    $c = db()->query('SELECT COUNT(*) AS n FROM ' . T('users'))->fetch();
    json_out(['ok' => true, 'products' => (int)$p['n'], 'orders' => (int)$o['n'], 'pending' => (int)$o['pending'], 'revenue' => (float)$o['revenue'], 'customers' => (int)$c['n']]);
}

/* ------------------------------------------------------------------ POST */
require_post();
$in = body();

if ($action === 'register') {
    $name  = clean_str(isset($in['name']) ? $in['name'] : '', 120);
    $email = strtolower(clean_str(isset($in['email']) ? $in['email'] : '', 190));
    $phone = clean_str(isset($in['phone']) ? $in['phone'] : '', 40);
    $pass  = isset($in['password']) ? (string)$in['password'] : '';
    if ($name === '') { fail('Please enter your name.'); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fail('Please enter a valid email address.'); }
    if (strlen($pass) < 8) { fail('Your password must be at least 8 characters.'); }
    $st = db()->prepare('SELECT id FROM ' . T('users') . ' WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) { fail('That email is already registered. Please log in.'); }
    $ins = db()->prepare('INSERT INTO ' . T('users') . ' (name, email, phone, password_hash, role, created_at) VALUES (?,?,?,?, "customer", ?)');
    $ins->execute([$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), now()]);
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)db()->lastInsertId();
    json_out(['ok' => true, 'user' => user_public(current_user())]);
}

if ($action === 'login') {
    $email = strtolower(clean_str(isset($in['email']) ? $in['email'] : '', 190));
    $pass  = isset($in['password']) ? (string)$in['password'] : '';
    $ip = client_ip();
    db()->prepare('DELETE FROM ' . T('login_attempts') . ' WHERE at < ?')->execute([date('Y-m-d H:i:s', time() - 900)]);
    $cnt = db()->prepare('SELECT COUNT(*) AS n FROM ' . T('login_attempts') . ' WHERE ip = ?');
    $cnt->execute([$ip]);
    $n = $cnt->fetch();
    if ((int)$n['n'] >= 10) { fail('Too many attempts. Please wait 15 minutes and try again.', 429); }
    $st = db()->prepare('SELECT * FROM ' . T('users') . ' WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    if (!$row || !password_verify($pass, $row['password_hash'])) {
        db()->prepare('INSERT INTO ' . T('login_attempts') . ' (ip, at) VALUES (?, ?)')->execute([$ip, now()]);
        usleep(400000);
        fail('Incorrect email or password.', 401);
    }
    db()->prepare('DELETE FROM ' . T('login_attempts') . ' WHERE ip = ?')->execute([$ip]);
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    json_out(['ok' => true, 'user' => user_public($row)]);
}

if ($action === 'logout') {
    start_session();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
    json_out(['ok' => true]);
}

if ($action === 'profile') {
    $u = require_login();
    $name = clean_str(isset($in['name']) ? $in['name'] : '', 120);
    if ($name === '') { fail('Please enter your name.'); }
    $st = db()->prepare('UPDATE ' . T('users') . ' SET name = ?, phone = ?, address = ?, city = ? WHERE id = ?');
    $st->execute([$name, clean_str(isset($in['phone']) ? $in['phone'] : '', 40), clean_str(isset($in['address']) ? $in['address'] : '', 255), clean_str(isset($in['city']) ? $in['city'] : '', 100), (int)$u['id']]);
    $st2 = db()->prepare('SELECT id, name, email, phone, address, city, role FROM ' . T('users') . ' WHERE id = ?');
    $st2->execute([(int)$u['id']]);
    json_out(['ok' => true, 'user' => user_public($st2->fetch())]);
}

if ($action === 'password') {
    $u = require_login();
    $cur = isset($in['current']) ? (string)$in['current'] : '';
    $new = isset($in['new']) ? (string)$in['new'] : '';
    if (strlen($new) < 8) { fail('Your new password must be at least 8 characters.'); }
    $st = db()->prepare('SELECT password_hash FROM ' . T('users') . ' WHERE id = ?');
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    if (!$row || !password_verify($cur, $row['password_hash'])) { fail('Your current password is not correct.', 401); }
    db()->prepare('UPDATE ' . T('users') . ' SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int)$u['id']]);
    json_out(['ok' => true]);
}

if ($action === 'order_create') {
    $u = require_login();
    $items = isset($in['items']) && is_array($in['items']) ? $in['items'] : [];
    if (!$items || count($items) > 50) { fail('Your cart is empty.'); }
    $name = clean_str(isset($in['name']) ? $in['name'] : '', 120);
    $phone = clean_str(isset($in['phone']) ? $in['phone'] : '', 40);
    $address = clean_str(isset($in['address']) ? $in['address'] : '', 255);
    $city = clean_str(isset($in['city']) ? $in['city'] : '', 100);
    $note = clean_str(isset($in['note']) ? $in['note'] : '', 1000);
    $pay = (isset($in['payment']) && $in['payment'] === 'mobile_money') ? 'mobile_money' : 'pay_on_delivery';
    if ($name === '' || $phone === '' || $address === '') { fail('Please fill in your name, phone number and delivery address.'); }

    $merged = [];
    foreach ($items as $it) {
        $id = isset($it['id']) ? (int)$it['id'] : 0;
        $q = isset($it['qty']) ? (int)$it['qty'] : 0;
        if ($id < 1 || $q < 1) { continue; }
        $merged[$id] = (isset($merged[$id]) ? $merged[$id] : 0) + min($q, 99);
    }
    if (!$merged) { fail('Your cart is empty.'); }
    ksort($merged);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $sel = $pdo->prepare('SELECT id, name, price, stock, active FROM ' . T('products') . ' WHERE id = ? FOR UPDATE');
        $dec = $pdo->prepare('UPDATE ' . T('products') . ' SET stock = stock - ? WHERE id = ?');
        $lines = []; $total = 0.0;
        foreach ($merged as $pid => $qty) {
            $sel->execute([$pid]);
            $p = $sel->fetch();
            if (!$p || (int)$p['active'] !== 1) { throw new RuntimeException('Sorry, an item in your cart is no longer available.'); }
            if ((int)$p['stock'] < $qty) { throw new RuntimeException('Sorry, only ' . (int)$p['stock'] . ' of "' . $p['name'] . '" left in stock.'); }
            $dec->execute([$qty, $pid]);
            $lines[] = [$pid, $p['name'], (float)$p['price'], $qty];
            $total += (float)$p['price'] * $qty;
        }
        $now = now();
        $ins = $pdo->prepare('INSERT INTO ' . T('orders') . ' (user_id, status, total, name, phone, address, city, note, payment, created_at, updated_at) VALUES (?, "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([(int)$u['id'], round($total, 2), $name, $phone, $address, $city, $note, $pay, $now, $now]);
        $oid = (int)$pdo->lastInsertId();
        $li = $pdo->prepare('INSERT INTO ' . T('order_items') . ' (order_id, product_id, name, price, qty) VALUES (?,?,?,?,?)');
        foreach ($lines as $l) { $li->execute([$oid, $l[0], $l[1], $l[2], $l[3]]); }
        // remember delivery details for next time
        $pdo->prepare('UPDATE ' . T('users') . ' SET phone = IF(phone = "", ?, phone), address = IF(address = "", ?, address), city = IF(city = "", ?, city) WHERE id = ?')->execute([$phone, $address, $city, (int)$u['id']]);
        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        fail($e->getMessage(), 409);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        fail('Sorry, we could not place your order. Please try again.', 500);
    }
    $o = load_orders('WHERE o.id = ?', [$oid]);
    json_out(['ok' => true, 'order' => $o[0]]);
}

if ($action === 'order_cancel') {
    $u = require_login();
    $id = isset($in['id']) ? (int)$in['id'] : 0;
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id, status FROM ' . T('orders') . ' WHERE id = ? AND user_id = ? FOR UPDATE');
        $st->execute([$id, (int)$u['id']]);
        $o = $st->fetch();
        if (!$o) { throw new RuntimeException('Order not found.'); }
        if ($o['status'] !== 'pending') { throw new RuntimeException('Only orders that are still pending can be cancelled. Please contact us.'); }
        restock_order($id);
        $pdo->prepare('UPDATE ' . T('orders') . ' SET status = "cancelled", updated_at = ? WHERE id = ?')->execute([now(), $id]);
        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        fail($e->getMessage(), 409);
    }
    json_out(['ok' => true]);
}

/* ------------------------------------------------------------ admin POST */
if ($action === 'product_save') {
    require_admin();
    $id = isset($in['id']) ? (int)$in['id'] : 0;
    $name = clean_str(isset($in['name']) ? $in['name'] : '', 160);
    if ($name === '') { fail('Please enter a product name.'); }
    $cat = clean_str(isset($in['category']) ? $in['category'] : '', 80);
    $desc = clean_str(isset($in['description']) ? $in['description'] : '', 4000);
    $price = isset($in['price']) ? (float)$in['price'] : 0;
    $stock = isset($in['stock']) ? (int)$in['stock'] : 0;
    $active = !empty($in['active']) ? 1 : 0;
    if ($price < 0 || $price > 99999999) { fail('Please enter a valid price.'); }
    if ($stock < 0) { $stock = 0; }
    $image = null;
    if (isset($in['image']) && $in['image'] !== null && $in['image'] !== '') {
        $image = (string)$in['image'];
        if (!preg_match('#^images/products/[A-Za-z0-9_\-]+\.(jpg|png|webp)$#', $image)) { fail('Invalid image path.'); }
    }
    $now = now();
    if ($id > 0) {
        $st = db()->prepare('SELECT id FROM ' . T('products') . ' WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) { fail('Product not found.', 404); }
        if (array_key_exists('image', $in)) {
            db()->prepare('UPDATE ' . T('products') . ' SET name=?, category=?, description=?, price=?, stock=?, active=?, image=?, updated_at=? WHERE id=?')
                ->execute([$name, $cat, $desc, $price, $stock, $active, $image, $now, $id]);
        } else {
            db()->prepare('UPDATE ' . T('products') . ' SET name=?, category=?, description=?, price=?, stock=?, active=?, updated_at=? WHERE id=?')
                ->execute([$name, $cat, $desc, $price, $stock, $active, $now, $id]);
        }
    } else {
        db()->prepare('INSERT INTO ' . T('products') . ' (name, category, description, price, stock, image, active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$name, $cat, $desc, $price, $stock, $image, $active, $now, $now]);
        $id = (int)db()->lastInsertId();
    }
    $st = db()->prepare('SELECT * FROM ' . T('products') . ' WHERE id = ?');
    $st->execute([$id]);
    json_out(['ok' => true, 'product' => product_row($st->fetch())]);
}

if ($action === 'product_delete') {
    require_admin();
    $id = isset($in['id']) ? (int)$in['id'] : 0;
    db()->prepare('DELETE FROM ' . T('products') . ' WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

if ($action === 'order_status') {
    require_admin();
    $id = isset($in['id']) ? (int)$in['id'] : 0;
    $status = isset($in['status']) ? (string)$in['status'] : '';
    if (!in_array($status, ORDER_STATUSES, true)) { fail('Unknown status.'); }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id, status FROM ' . T('orders') . ' WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $o = $st->fetch();
        if (!$o) { throw new RuntimeException('Order not found.'); }
        if ($o['status'] === 'cancelled' && $status !== 'cancelled') { throw new RuntimeException('A cancelled order cannot be reopened. Ask the customer to place a new order.'); }
        if ($o['status'] !== 'cancelled' && $status === 'cancelled') { restock_order($id); }
        $pdo->prepare('UPDATE ' . T('orders') . ' SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, now(), $id]);
        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        fail($e->getMessage(), 409);
    }
    json_out(['ok' => true]);
}

fail('Unknown request.', 404);
