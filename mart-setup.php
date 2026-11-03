<?php
/**
 * One-time setup for the Spenpy Mart:
 * creates the database tables and your admin account.
 * Needs config.php (copy of config.sample.php) with your database details and a setup key.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
require __DIR__ . '/lib.php';
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$lock = __DIR__ . '/setup.lock';
$msg = ''; $ok = false; $c = cfg();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $c && !file_exists($lock)) {
    $key   = isset($_POST['key']) ? (string)$_POST['key'] : '';
    $name  = trim(isset($_POST['name']) ? (string)$_POST['name'] : '');
    $email = strtolower(trim(isset($_POST['email']) ? (string)$_POST['email'] : ''));
    $pass  = isset($_POST['password']) ? (string)$_POST['password'] : '';
    if (empty($c['setup_key']) || $c['setup_key'] === 'CHANGE-THIS-TO-A-LONG-SECRET') {
        $msg = 'Please set your own secret word as "setup_key" in config.php first.';
    } elseif (!hash_equals((string)$c['setup_key'], $key)) {
        usleep(600000);
        $msg = 'The setup key is not correct.';
    } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
        $msg = 'Please enter your name, a valid email and a password of at least 8 characters.';
    } else {
        try {
            $pdo = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            install_schema($pdo);
            $st = $pdo->prepare('SELECT id FROM ' . T('users') . ' WHERE email = ?');
            $st->execute([$email]);
            $row = $st->fetch();
            if ($row) {
                $pdo->prepare('UPDATE ' . T('users') . ' SET name = ?, password_hash = ?, role = "admin" WHERE id = ?')
                    ->execute([$name, password_hash($pass, PASSWORD_DEFAULT), (int)$row['id']]);
            } else {
                $pdo->prepare('INSERT INTO ' . T('users') . ' (name, email, password_hash, role, created_at) VALUES (?,?,?, "admin", ?)')
                    ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            }
            @file_put_contents($lock, 'setup done ' . date('c'));
            $ok = true;
        } catch (PDOException $e) {
            $msg = 'Could not connect or create the tables. Check the database details in config.php. (' . h($e->getMessage()) . ')';
        }
    }
}
$done = file_exists($lock);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Spenpy Mart · Setup</title>
<style>
body{margin:0;font-family:system-ui,Segoe UI,Roboto,sans-serif;background:#eef4fa;color:#04223a;display:grid;place-items:center;min-height:100vh;padding:20px}
.box{background:#fff;border:1px solid #dbe5ef;border-radius:20px;padding:30px;max-width:460px;width:100%;box-shadow:0 20px 50px rgba(8,47,80,.12)}
h1{margin:0 0 6px;font-size:1.5rem;color:#083b63}p{color:#5c7285;line-height:1.55}label{display:block;font-weight:700;font-size:13.5px;margin:14px 0 5px}
input{width:100%;padding:12px 14px;border:1px solid #cfdbe7;border-radius:12px;font:inherit;box-sizing:border-box}
button,.btn{display:inline-block;margin-top:18px;padding:13px 20px;border:0;border-radius:12px;background:#ff6b00;color:#fff;font-weight:800;font-size:15px;cursor:pointer;text-decoration:none}
.err{background:#fdecec;border:1px solid #f3b9b9;color:#7b1d1d;padding:10px 14px;border-radius:12px;margin:14px 0}.good{background:#e8f7f0;border:1px solid #b6e3cf;color:#0b5c40;padding:12px 14px;border-radius:12px;margin:14px 0}
code{background:#f1f5f9;padding:2px 6px;border-radius:6px}
</style></head><body><div class="box">
<h1>Spenpy Mart setup</h1>
<?php if ($ok): ?>
  <div class="good"><b>All done ✓</b><br>The shop tables were created and your admin account is ready.</div>
  <p>Now log in to the admin panel with the email and password you just chose.</p>
  <a class="btn" href="admin.html">Open the admin panel</a>
<?php elseif ($done): ?>
  <div class="good"><b>Setup is already complete.</b></div>
  <p>For safety this page is locked. To run it again, delete the file <code>setup.lock</code> from the website folder.</p>
  <a class="btn" href="admin.html">Open the admin panel</a>
<?php elseif (!$c): ?>
  <div class="err"><b>config.php not found</b></div>
  <p>1. In the website folder, copy <code>config.sample.php</code> and name the copy <code>config.php</code>.<br>
  2. Open it and fill in your database name, user and password (create them in cPanel → MySQL Databases), and choose a secret <code>setup_key</code>.<br>
  3. Reload this page.</p>
<?php else: ?>
  <p>This creates the shop tables and your admin account. You only do this once.</p>
  <?php if ($msg): ?><div class="err"><?php echo h($msg); ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <label>Setup key <small style="font-weight:400;color:#5c7285">(the secret word in config.php)</small></label><input type="password" name="key" required>
    <label>Your name</label><input name="name" required value="<?php echo h(isset($_POST['name']) ? $_POST['name'] : ''); ?>">
    <label>Admin email</label><input type="email" name="email" required value="<?php echo h(isset($_POST['email']) ? $_POST['email'] : ''); ?>">
    <label>Admin password <small style="font-weight:400;color:#5c7285">(8+ characters)</small></label><input type="password" name="password" minlength="8" required>
    <button type="submit">Create tables &amp; admin</button>
  </form>
<?php endif; ?>
</div></body></html>
