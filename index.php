<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);

define('DB_HOST', 'sql101.infinityfree.com');  // database host 
define('DB_USER', 'if0_41388936');  //database username
define('DB_PASS', 'BoutiquePass101');   //database password
define('DB_NAME', 'if0_41388936_micahboutique'); //database name    
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'uploads/');

session_start();

function db() {
    static $conn = null;
    if ($conn) return $conn;
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) die(json_encode(['error' => 'DB error: ' . $conn->connect_error]));
    $conn->set_charset('utf8mb4');
    return $conn;
}

// Status is 100% driven by quantity vs threshold
function calcStatus($qty, $threshold) {
    if ($qty <= 0)          return 'Out of Stock';
    if ($qty <= $threshold) return 'Low Stock';
    return 'In Stock';
}

function install() {
    $db = db();
    $db->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        category VARCHAR(100) NOT NULL,
        sizes TEXT,
        colors TEXT,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 0,
        low_stock_threshold INT NOT NULL DEFAULT 5,
        status VARCHAR(20) NOT NULL DEFAULT 'Out of Stock',
        shop ENUM('Shop 1','Shop 2') NOT NULL DEFAULT 'Shop 1',
        img VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Safe migration: only add columns if they don't already exist
    $cols = [];
    $colRes = $db->query("SHOW COLUMNS FROM products");
    if ($colRes) { while ($row = $colRes->fetch_assoc()) $cols[] = $row['Field']; }
    if (!in_array('quantity', $cols))
        $db->query("ALTER TABLE products ADD COLUMN quantity INT NOT NULL DEFAULT 0 AFTER price");
    if (!in_array('low_stock_threshold', $cols))
        $db->query("ALTER TABLE products ADD COLUMN low_stock_threshold INT NOT NULL DEFAULT 5 AFTER quantity");

    $db->query("CREATE TABLE IF NOT EXISTS sold_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT,
        product_name VARCHAR(200),
        category VARCHAR(100),
        price DECIMAL(12,2),
        quantity_sold INT NOT NULL DEFAULT 1,
        shop VARCHAR(20),
        sold_by VARCHAR(80),
        sold_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed users
    $check = $db->query("SELECT id FROM users LIMIT 1");
    if ($check->num_rows === 0) {
        $a = password_hash('admin123', PASSWORD_DEFAULT);
        $s = password_hash('staff123', PASSWORD_DEFAULT);
        $db->query("INSERT INTO users (username,password,role) VALUES ('admin','$a','admin'),('staff','$s','staff')");
    }

    // Seed demo products [name, cat, sizes, colors, price, qty, threshold, shop]
    $chk = $db->query("SELECT id FROM products LIMIT 1");
    if ($chk->num_rows === 0) {
        $demos = [
            ["Velvet Wrap Dress",  "Dresses",   "S,M,L",          "Black,Burgundy",    7800,  25, 5, "Shop 1"],
            ["Structured Blazer",  "Outerwear", "XS,S,M,L,XL",    "Camel,Black",      12500,   4, 5, "Shop 2"],
            ["Silk Camisole Top",  "Tops",      "S,M,L",          "Ivory,Gold,Black",  3200,  18, 5, "Shop 1"],
            ["Wide Leg Trousers",  "Bottoms",   "S,M,L,XL",       "Chocolate,Black",   5400,   0, 5, "Shop 2"],
            ["Chain Tote Bag",     "Bags",      "One Size",        "Gold,Black",        9900,  12, 5, "Shop 1"],
            ["Heeled Mules",       "Shoes",     "36,37,38,39,40",  "Nude,Black",        6700,   3, 5, "Shop 2"],
        ];
        $stmt = $db->prepare("INSERT INTO products (name,category,sizes,colors,price,quantity,low_stock_threshold,status,shop) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($demos as $d) {
            $status = calcStatus((int)$d[5], (int)$d[6]);
            $n = $d[0]; $c = $d[1]; $sz = $d[2]; $cl = $d[3];
            $pr = (float)$d[4]; $qty = (int)$d[5]; $thr = (int)$d[6]; $sh = $d[7];
            $stmt->bind_param("ssssdiiss", $n, $c, $sz, $cl, $pr, $qty, $thr, $status, $sh);
            $stmt->execute();
        }
    }

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
}
install();

function isAdmin()    { return ($_SESSION['role'] ?? '') === 'admin'; }
function isLoggedIn() { return isset($_SESSION['user_id']); }
function jsonOut($d) { header('Content-Type: application/json'); echo json_encode($d); exit; }

// ─── AJAX API ─────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $action = $_GET['api'];

    if ($action === 'login') {
        $body = json_decode(file_get_contents('php://input'), true);
        $user = trim($body['username'] ?? '');
        $pass = $body['password'] ?? '';
        $db   = db();
        $stmt = $db->prepare("SELECT id,username,password,role FROM users WHERE username=? LIMIT 1");
        $stmt->bind_param("s", $user); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && password_verify($pass, $row['password'])) {
            $_SESSION['user_id']  = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role']     = $row['role'];
            jsonOut(['ok'=>true,'role'=>$row['role'],'username'=>$row['username']]);
        }
        jsonOut(['ok'=>false,'msg'=>'Invalid credentials']);
    }

    if ($action === 'logout') { session_destroy(); jsonOut(['ok'=>true]); }

    if ($action === 'session') {
        jsonOut(isLoggedIn()
            ? ['ok'=>true,'role'=>$_SESSION['role'],'username'=>$_SESSION['username']]
            : ['ok'=>false]);
    }

    if (!isLoggedIn()) jsonOut(['error'=>'Unauthorized']);

    // GET PRODUCTS
    if ($action === 'products') {
        $db = db(); $where=[]; $params=[]; $types='';
        if (!empty($_GET['q'])) {
            $q = '%'.$_GET['q'].'%';
            $where[] = "(name LIKE ? OR category LIKE ? OR colors LIKE ?)";
            $params = array_merge($params,[$q,$q,$q]); $types .= 'sss';
        }
        if (!empty($_GET['category'])) { $where[]="category=?"; $params[]=$_GET['category']; $types.='s'; }
        if (!empty($_GET['shop']) && $_GET['shop']!=='all') { $where[]="shop=?"; $params[]=$_GET['shop']; $types.='s'; }
        // Status filter — compare computed status
        if (!empty($_GET['status'])) { $where[]="status=?"; $params[]=$_GET['status']; $types.='s'; }
        $sql = "SELECT * FROM products".($where?" WHERE ".implode(" AND ",$where):"")." ORDER BY created_at DESC";
        $stmt = $db->prepare($sql);
        if ($types) $stmt->bind_param($types,...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['sizes']  = $r['sizes']  ? explode(',', $r['sizes'])  : [];
            $r['colors'] = $r['colors'] ? explode(',', $r['colors']) : [];
            // Always re-compute status from qty so it is always accurate
            $r['status'] = calcStatus((int)$r['quantity'], (int)$r['low_stock_threshold']);
        }
        jsonOut($rows);
    }

    // ADD PRODUCT (admin only)
    if ($action === 'add_product') {
        if (!isAdmin()) jsonOut(['error'=>'Forbidden']);
        $name      = trim($_POST['name']      ?? '');
        $category  = trim($_POST['category']  ?? '');
        $sizes     = trim($_POST['sizes']     ?? '');
        $colors    = trim($_POST['colors']    ?? '');
        $price     = floatval($_POST['price'] ?? 0);
        $quantity  = intval($_POST['quantity']  ?? 0);
        $threshold = intval($_POST['threshold'] ?? 5);
        $shop      = $_POST['shop'] ?? 'Shop 1';
        if (!$name || !$category || !$shop) jsonOut(['error'=>'Missing required fields']);
        $status = calcStatus($quantity, $threshold);
        $img = '';
        if (!empty($_FILES['img']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext,['jpg','jpeg','png','gif','webp'])) jsonOut(['error'=>'Invalid image type']);
            $filename = uniqid('prod_').'.'.$ext;
            move_uploaded_file($_FILES['img']['tmp_name'], UPLOAD_DIR.$filename);
            $img = UPLOAD_URL.$filename;
        }
        $db   = db();
        $stmt = $db->prepare("INSERT INTO products (name,category,sizes,colors,price,quantity,low_stock_threshold,status,shop,img) VALUES (?,?,?,?,?,?,?,?,?,?)");
        // types: s=name, s=category, s=sizes, s=colors, d=price, i=quantity, i=threshold, s=status, s=shop, s=img
        $stmt->bind_param("ssssdiisss", $name,$category,$sizes,$colors,$price,$quantity,$threshold,$status,$shop,$img);
        $stmt->execute();
        jsonOut(['ok'=>true,'id'=>$db->insert_id,'status'=>$status]);
    }

    // UPDATE PRODUCT (admin only)
    if ($action === 'update_product') {
        if (!isAdmin()) jsonOut(['error'=>'Forbidden']);
        $id        = intval($_POST['id']        ?? 0);
        $name      = trim($_POST['name']        ?? '');
        $category  = trim($_POST['category']    ?? '');
        $sizes     = trim($_POST['sizes']       ?? '');
        $colors    = trim($_POST['colors']      ?? '');
        $price     = floatval($_POST['price']   ?? 0);
        $quantity  = intval($_POST['quantity']  ?? 0);
        $threshold = intval($_POST['threshold'] ?? 5);
        $shop      = $_POST['shop'] ?? 'Shop 1';
        if (!$id || !$name || !$category) jsonOut(['error'=>'Missing fields']);
        $status = calcStatus($quantity, $threshold);
        $db = db();
        $imgSql = '';
        if (!empty($_FILES['img']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION));
            if (in_array($ext,['jpg','jpeg','png','gif','webp'])) {
                $old = $db->query("SELECT img FROM products WHERE id=$id")->fetch_assoc();
                if ($old && $old['img'] && file_exists(__DIR__.'/'.$old['img'])) @unlink(__DIR__.'/'.$old['img']);
                $filename = uniqid('prod_').'.'.$ext;
                move_uploaded_file($_FILES['img']['tmp_name'], UPLOAD_DIR.$filename);
                $ni = $db->real_escape_string(UPLOAD_URL.$filename);
                $imgSql = ", img='$ni'";
            }
        }
        $stmt = $db->prepare("UPDATE products SET name=?,category=?,sizes=?,colors=?,price=?,quantity=?,low_stock_threshold=?,status=?,shop=?{$imgSql} WHERE id=?");
        // types: s=name, s=category, s=sizes, s=colors, d=price, i=quantity, i=threshold, s=status, s=shop, i=id
        $stmt->bind_param("ssssdiissi", $name,$category,$sizes,$colors,$price,$quantity,$threshold,$status,$shop,$id);
        $stmt->execute();
        jsonOut(['ok'=>true,'status'=>$status]);
    }

    // DELETE PRODUCT (admin only)
    if ($action === 'delete_product') {
        if (!isAdmin()) jsonOut(['error'=>'Forbidden']);
        $body = json_decode(file_get_contents('php://input'), true);
        $id   = intval($body['id'] ?? 0);
        if (!$id) jsonOut(['error'=>'No ID']);
        $db  = db();
        $old = $db->query("SELECT img FROM products WHERE id=$id")->fetch_assoc();
        if ($old && $old['img'] && file_exists(__DIR__.'/'.$old['img'])) @unlink(__DIR__.'/'.$old['img']);
        $db->query("DELETE FROM products WHERE id=$id");
        jsonOut(['ok'=>true]);
    }

    // MARK SOLD — deducts 1 unit, recomputes status
    if ($action === 'mark_sold') {
        $body = json_decode(file_get_contents('php://input'), true);
        $pid  = intval($body['id'] ?? 0);
        if (!$pid) jsonOut(['error'=>'No ID']);
        $db  = db();
        $row = $db->query("SELECT * FROM products WHERE id=$pid")->fetch_assoc();
        if (!$row) jsonOut(['error'=>'Product not found']);
        if ((int)$row['quantity'] <= 0) jsonOut(['error'=>'Out of stock — cannot mark as sold']);
        $newQty    = (int)$row['quantity'] - 1;
        $newStatus = calcStatus($newQty, (int)$row['low_stock_threshold']);
        $db->query("UPDATE products SET quantity=$newQty, status='$newStatus' WHERE id=$pid");
        $by   = $_SESSION['username'];
        $stmt = $db->prepare("INSERT INTO sold_log (product_id,product_name,category,price,quantity_sold,shop,sold_by) VALUES (?,?,?,?,1,?,?)");
        $stmt->bind_param("issdss", $pid,$row['name'],$row['category'],$row['price'],$row['shop'],$by);
        $stmt->execute();
        jsonOut(['ok'=>true,'new_quantity'=>$newQty,'new_status'=>$newStatus]);
    }

    // SOLD LOG (admin only)
    if ($action === 'sold_log') {
        if (!isAdmin()) jsonOut(['error'=>'Forbidden']);
        jsonOut(db()->query("SELECT * FROM sold_log ORDER BY sold_at DESC")->fetch_all(MYSQLI_ASSOC));
    }

    // CLEAR LOG (admin only)
    if ($action === 'clear_log') {
        if (!isAdmin()) jsonOut(['error'=>'Forbidden']);
        db()->query("DELETE FROM sold_log");
        jsonOut(['ok'=>true]);
    }

    jsonOut(['error'=>'Unknown action']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Micah Boutique</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=Josefin+Sans:wght@300;400;600&display=swap" rel="stylesheet">
<style>
:root{--black:#0a0806;--deep-brown:#2c1a0e;--brown:#5c3d1e;--mid-brown:#8b5e3c;--light-brown:#c49a6c;--gold:#d4a843;--gold-light:#f0c96b;--gold-shine:#ffe99a;--cream:#f5efe6;--danger:#c0392b;--success:#27ae60;--warning:#e67e22;--overlay:rgba(10,8,6,0.85)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Josefin Sans',sans-serif;background:var(--black);color:var(--cream);min-height:100vh;overflow-x:hidden}
::-webkit-scrollbar{width:6px}::-webkit-scrollbar-track{background:var(--deep-brown)}::-webkit-scrollbar-thumb{background:var(--gold);border-radius:3px}

/* LOGIN */
#loginPage{min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(ellipse at 60% 40%,#2c1a0e 0%,#0a0806 70%);position:relative;overflow:hidden}
#loginPage::before{content:'';position:absolute;inset:0;background:repeating-linear-gradient(45deg,transparent,transparent 80px,rgba(212,168,67,.03) 80px,rgba(212,168,67,.03) 81px),repeating-linear-gradient(-45deg,transparent,transparent 80px,rgba(212,168,67,.03) 80px,rgba(212,168,67,.03) 81px)}
.login-box{background:linear-gradient(160deg,#1a0f07,#0d0905);border:1px solid rgba(212,168,67,.3);border-radius:2px;padding:52px 48px 48px;width:420px;position:relative;box-shadow:0 30px 80px rgba(0,0,0,.8),inset 0 1px 0 rgba(212,168,67,.2);animation:fadeUp .7s ease both}
@keyframes fadeUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:none}}
.login-box::before{content:'';position:absolute;top:0;left:10%;right:10%;height:1px;background:linear-gradient(90deg,transparent,var(--gold),transparent)}
.login-logo{text-align:center;margin-bottom:36px}
.login-logo .brand{font-family:'Cormorant Garamond',serif;font-size:38px;font-weight:300;letter-spacing:8px;color:var(--gold-light);text-transform:uppercase}
.login-logo .sub{font-size:10px;letter-spacing:5px;color:var(--mid-brown);margin-top:4px;text-transform:uppercase}
.role-tabs{display:flex;gap:2px;margin-bottom:32px;background:rgba(255,255,255,.04);padding:3px;border-radius:2px}
.role-tab{flex:1;padding:10px;text-align:center;font-size:11px;letter-spacing:3px;text-transform:uppercase;cursor:pointer;border-radius:1px;transition:all .3s;color:var(--mid-brown)}
.role-tab.active{background:var(--gold);color:var(--black);font-weight:600}
.form-group{margin-bottom:20px}
.form-group label{display:block;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:var(--light-brown);margin-bottom:8px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:12px 14px;background:rgba(255,255,255,.05);border:1px solid rgba(212,168,67,.2);border-radius:2px;color:var(--cream);font-family:'Josefin Sans',sans-serif;font-size:13px;letter-spacing:1px;transition:border-color .3s,box-shadow .3s;outline:none}
.form-group input:focus,.form-group select:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,168,67,.1)}
.form-group select option{background:var(--deep-brown)}
.btn-primary{width:100%;padding:14px;background:linear-gradient(135deg,var(--gold) 0%,var(--gold-light) 50%,var(--gold) 100%);border:none;border-radius:2px;color:var(--black);font-family:'Josefin Sans',sans-serif;font-size:11px;font-weight:600;letter-spacing:4px;text-transform:uppercase;cursor:pointer;transition:all .3s;box-shadow:0 4px 20px rgba(212,168,67,.3)}
.btn-primary:hover{box-shadow:0 6px 30px rgba(212,168,67,.5);transform:translateY(-1px)}
.login-err{color:var(--danger);font-size:12px;letter-spacing:1px;margin-top:12px;text-align:center;min-height:18px}

/* APP */
#app{display:none;flex-direction:column;min-height:100vh}
header{background:linear-gradient(90deg,#0d0905,#1a0f07,#0d0905);border-bottom:1px solid rgba(212,168,67,.25);padding:0 32px;display:flex;align-items:center;justify-content:space-between;height:64px;position:sticky;top:0;z-index:100;box-shadow:0 4px 24px rgba(0,0,0,.6)}
.header-brand{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:300;letter-spacing:6px;color:var(--gold-light);cursor:pointer}
.header-brand span{color:var(--light-brown)}
.header-nav{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.nav-btn{padding:8px 16px;background:transparent;border:1px solid rgba(212,168,67,.2);border-radius:1px;color:var(--light-brown);font-family:'Josefin Sans',sans-serif;font-size:10px;letter-spacing:2px;text-transform:uppercase;cursor:pointer;transition:all .3s}
.nav-btn:hover,.nav-btn.active{background:rgba(212,168,67,.1);border-color:var(--gold);color:var(--gold-light)}
.nav-btn.gold{background:var(--gold);color:var(--black);border-color:var(--gold);font-weight:600}
.nav-btn.gold:hover{background:var(--gold-light)}
.user-badge{display:flex;align-items:center;gap:10px;padding:6px 14px;background:rgba(255,255,255,.04);border-radius:20px;border:1px solid rgba(212,168,67,.15)}
.role-dot{width:8px;height:8px;border-radius:50%}
.role-admin{background:var(--gold);box-shadow:0 0 6px var(--gold)}
.role-staff{background:var(--mid-brown)}
.user-badge span{font-size:11px;letter-spacing:1px;color:var(--light-brown)}

/* SEARCH */
.search-wrap{padding:20px 32px;background:rgba(255,255,255,.02);border-bottom:1px solid rgba(212,168,67,.1);display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.search-input-wrap{flex:1;min-width:260px;position:relative}
.search-input-wrap input{width:100%;padding:11px 42px 11px 16px;background:rgba(255,255,255,.05);border:1px solid rgba(212,168,67,.2);border-radius:2px;color:var(--cream);font-family:'Josefin Sans',sans-serif;font-size:13px;outline:none;transition:all .3s}
.search-input-wrap input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,168,67,.1)}
.search-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--gold);font-size:16px;pointer-events:none}
.filter-select{padding:11px 14px;background:rgba(255,255,255,.05);border:1px solid rgba(212,168,67,.2);border-radius:2px;color:var(--cream);font-family:'Josefin Sans',sans-serif;font-size:12px;outline:none;cursor:pointer;transition:border-color .3s}
.filter-select:focus{border-color:var(--gold)}
.filter-select option{background:var(--deep-brown)}

/* SHOP TABS */
.shop-tabs{padding:20px 32px 0;display:flex;gap:2px}
.shop-tab{padding:10px 24px;font-size:11px;letter-spacing:3px;text-transform:uppercase;cursor:pointer;border-bottom:2px solid transparent;transition:all .3s;color:var(--mid-brown)}
.shop-tab.active{color:var(--gold-light);border-bottom-color:var(--gold)}

/* GRID */
.products-section{padding:24px 32px;flex:1}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.section-title{font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:300;letter-spacing:3px;color:var(--gold-light)}
.count-badge{font-size:11px;letter-spacing:2px;color:var(--mid-brown)}
.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:20px}

/* CARD */
.product-card{background:linear-gradient(160deg,#160d06,#0d0905);border:1px solid rgba(212,168,67,.12);border-radius:2px;overflow:hidden;cursor:pointer;transition:all .35s;position:relative;box-shadow:0 4px 20px rgba(0,0,0,.4)}
.product-card:hover{transform:translateY(-4px);border-color:rgba(212,168,67,.4);box-shadow:0 12px 40px rgba(0,0,0,.6)}
.card-img{width:100%;height:220px;background:linear-gradient(135deg,#1a0f07,#2c1a0e);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.card-img img{width:100%;height:100%;object-fit:cover;transition:transform .5s}
.product-card:hover .card-img img{transform:scale(1.05)}
.card-img .no-img{font-size:40px;opacity:.3}
.status-badge{position:absolute;top:10px;left:10px;padding:4px 10px;border-radius:1px;font-size:9px;letter-spacing:2px;text-transform:uppercase;font-weight:600}
.status-in{background:rgba(39,174,96,.85);color:#fff}
.status-out{background:rgba(192,57,43,.85);color:#fff}
.status-low{background:rgba(230,126,34,.85);color:#fff}
.shop-tag{position:absolute;top:10px;right:10px;padding:4px 10px;background:rgba(212,168,67,.85);color:var(--black);font-size:9px;letter-spacing:2px;border-radius:1px;font-weight:600}
.card-body{padding:16px}
.card-category{font-size:9px;letter-spacing:3px;text-transform:uppercase;color:var(--mid-brown);margin-bottom:6px}
.card-name{font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:400;color:var(--cream);margin-bottom:8px;line-height:1.3}
.card-price{font-size:16px;font-weight:600;color:var(--gold);letter-spacing:1px;margin-bottom:10px}
.qty-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:10px;border:1px solid rgba(212,168,67,.2);color:var(--light-brown);font-size:10px}
.qty-pill strong{font-size:13px;color:var(--cream)}
.card-colors{display:flex;gap:5px;margin-top:10px;flex-wrap:wrap}
.color-dot{width:14px;height:14px;border-radius:50%;border:1px solid rgba(255,255,255,.2);flex-shrink:0}
.card-actions{display:flex;gap:6px;padding:12px 16px;border-top:1px solid rgba(212,168,67,.1)}
.btn-sold{flex:1;padding:9px;background:linear-gradient(135deg,var(--gold),var(--gold-light));border:none;border-radius:1px;color:var(--black);font-family:'Josefin Sans',sans-serif;font-size:10px;font-weight:600;letter-spacing:2px;text-transform:uppercase;cursor:pointer;transition:all .3s}
.btn-sold:hover{box-shadow:0 4px 16px rgba(212,168,67,.4);transform:translateY(-1px)}
.btn-sold:disabled{opacity:.4;cursor:not-allowed;transform:none;box-shadow:none;background:#555}
.btn-edit{padding:9px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(212,168,67,.2);border-radius:1px;color:var(--light-brown);font-size:14px;cursor:pointer;transition:all .3s}
.btn-edit:hover{background:rgba(212,168,67,.1);border-color:var(--gold)}
.btn-delete{padding:9px 12px;background:rgba(192,57,43,.1);border:1px solid rgba(192,57,43,.2);border-radius:1px;color:#e74c3c;font-size:14px;cursor:pointer;transition:all .3s}
.btn-delete:hover{background:rgba(192,57,43,.2);border-color:#e74c3c}

/* MODALS */
.modal-overlay{position:fixed;inset:0;background:var(--overlay);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;animation:fadeIn .3s ease}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal-box{background:linear-gradient(160deg,#1a0f07,#0d0905);border:1px solid rgba(212,168,67,.25);border-radius:2px;max-height:90vh;overflow-y:auto;position:relative;box-shadow:0 40px 100px rgba(0,0,0,.9);animation:slideUp .4s cubic-bezier(.22,.68,0,1.2) both}
@keyframes slideUp{from{opacity:0;transform:translateY(40px) scale(.97)}to{opacity:1;transform:none}}
.modal-close{position:absolute;top:16px;right:16px;background:rgba(255,255,255,.06);border:1px solid rgba(212,168,67,.2);border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--light-brown);font-size:16px;transition:all .3s;z-index:5}
.modal-close:hover{background:rgba(212,168,67,.15);color:var(--gold-light)}

/* DETAIL */
.detail-layout{display:grid;grid-template-columns:1fr 1fr}
.detail-img{background:linear-gradient(135deg,#1a0f07,#2c1a0e);min-height:400px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.detail-img img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.detail-img .no-img-lg{font-size:80px;opacity:.2}
.detail-body{padding:36px 32px;overflow-y:auto}
.detail-shop{font-size:10px;letter-spacing:3px;text-transform:uppercase;color:var(--gold);margin-bottom:8px}
.detail-category{font-size:10px;letter-spacing:3px;text-transform:uppercase;color:var(--mid-brown);margin-bottom:10px}
.detail-name{font-family:'Cormorant Garamond',serif;font-size:30px;font-weight:300;color:var(--cream);margin-bottom:14px;line-height:1.2}
.detail-price{font-size:24px;font-weight:600;color:var(--gold);margin-bottom:16px}
/* Quantity display */
.qty-card{display:flex;align-items:stretch;gap:0;border:1px solid rgba(212,168,67,.2);border-radius:2px;overflow:hidden;margin-bottom:20px}
.qty-card-block{flex:1;padding:14px 16px}
.qty-card-block + .qty-card-block{border-left:1px solid rgba(212,168,67,.15)}
.qty-card-label{font-size:9px;letter-spacing:3px;text-transform:uppercase;color:var(--mid-brown);margin-bottom:6px}
.qty-card-val{font-size:22px;font-weight:600;color:var(--cream)}
.qty-card-unit{font-size:10px;color:var(--mid-brown);letter-spacing:1px;margin-top:2px}
.detail-status-badge{display:inline-block;padding:6px 16px;border-radius:1px;font-size:10px;letter-spacing:2px;text-transform:uppercase;font-weight:600;margin-bottom:20px}
.detail-divider{height:1px;background:linear-gradient(90deg,rgba(212,168,67,.3),transparent);margin:16px 0}
.detail-label{font-size:9px;letter-spacing:3px;text-transform:uppercase;color:var(--mid-brown);margin-bottom:8px}
.detail-sizes{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.size-chip{padding:6px 12px;border:1px solid rgba(212,168,67,.25);border-radius:1px;font-size:11px;letter-spacing:1px;color:var(--cream)}
.detail-colors{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.color-chip{display:flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid rgba(212,168,67,.2);border-radius:10px;font-size:11px;color:var(--light-brown)}
.color-swatch{width:14px;height:14px;border-radius:50%;border:1px solid rgba(255,255,255,.15)}
.sold-btn-lg{width:100%;padding:14px;background:linear-gradient(135deg,var(--gold),var(--gold-light));border:none;border-radius:2px;color:var(--black);font-family:'Josefin Sans',sans-serif;font-size:11px;font-weight:600;letter-spacing:3px;text-transform:uppercase;cursor:pointer;transition:all .3s;box-shadow:0 4px 20px rgba(212,168,67,.3)}
.sold-btn-lg:hover{box-shadow:0 8px 30px rgba(212,168,67,.5);transform:translateY(-1px)}
.sold-btn-lg:disabled{opacity:.4;cursor:not-allowed;transform:none;box-shadow:none;background:#555;color:#999}

/* FORM */
.form-modal{max-width:640px;padding:40px;width:100%}
.form-modal h2{font-family:'Cormorant Garamond',serif;font-size:28px;font-weight:300;color:var(--gold-light);margin-bottom:8px;letter-spacing:2px}
.form-modal .sub{font-size:11px;letter-spacing:2px;color:var(--mid-brown);margin-bottom:32px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid .full{grid-column:1/-1}
.qty-hint{font-size:10px;color:var(--mid-brown);letter-spacing:.5px;margin-top:5px;line-height:1.4}
.status-preview{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:1px;font-size:10px;letter-spacing:2px;text-transform:uppercase;font-weight:600;margin-top:10px}
.img-upload{border:2px dashed rgba(212,168,67,.25);border-radius:2px;padding:24px;text-align:center;cursor:pointer;transition:all .3s;position:relative}
.img-upload:hover{border-color:var(--gold);background:rgba(212,168,67,.04)}
.img-upload input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.img-upload .icon{font-size:28px;color:var(--gold);margin-bottom:8px}
.img-upload p{font-size:11px;letter-spacing:1px;color:var(--mid-brown)}
.img-preview-thumb{width:100%;max-height:160px;object-fit:cover;border-radius:2px;margin-top:10px;display:none;border:1px solid rgba(212,168,67,.2)}
.form-actions{display:flex;gap:10px;margin-top:28px}
.btn-save{flex:1;padding:12px;background:linear-gradient(135deg,var(--gold),var(--gold-light));border:none;border-radius:2px;color:var(--black);font-family:'Josefin Sans',sans-serif;font-size:10px;font-weight:600;letter-spacing:3px;text-transform:uppercase;cursor:pointer;transition:all .3s;box-shadow:0 4px 16px rgba(212,168,67,.25)}
.btn-save:hover{box-shadow:0 6px 24px rgba(212,168,67,.4);transform:translateY(-1px)}
.btn-cancel{padding:12px 20px;background:transparent;border:1px solid rgba(212,168,67,.2);border-radius:2px;color:var(--light-brown);font-family:'Josefin Sans',sans-serif;font-size:10px;letter-spacing:2px;text-transform:uppercase;cursor:pointer;transition:all .3s}
.btn-cancel:hover{border-color:var(--gold);color:var(--gold-light)}

/* CONFIRM */
.confirm-box{max-width:380px;padding:36px 32px;text-align:center}
.confirm-box h3{font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:300;color:var(--cream);margin-bottom:12px}
.confirm-box p{font-size:12px;color:var(--mid-brown);margin-bottom:28px}
.confirm-actions{display:flex;gap:10px;justify-content:center}
.btn-danger{padding:11px 24px;background:rgba(192,57,43,.15);border:1px solid rgba(192,57,43,.4);border-radius:2px;color:#e74c3c;font-family:'Josefin Sans',sans-serif;font-size:10px;font-weight:600;letter-spacing:2px;text-transform:uppercase;cursor:pointer;transition:all .3s}
.btn-danger:hover{background:rgba(192,57,43,.25)}

/* HISTORY */
.sold-history{padding:24px 32px}
.sold-table{width:100%;border-collapse:collapse}
.sold-table th{text-align:left;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:var(--gold);padding:12px 16px;border-bottom:1px solid rgba(212,168,67,.2)}
.sold-table td{padding:14px 16px;font-size:13px;color:var(--light-brown);border-bottom:1px solid rgba(255,255,255,.04)}
.sold-table tr:hover td{background:rgba(212,168,67,.04)}

/* TOAST / SPINNER / EMPTY */
.toast{position:fixed;bottom:32px;right:32px;padding:14px 22px;background:linear-gradient(135deg,#1a0f07,#2c1a0e);border:1px solid rgba(212,168,67,.4);border-radius:2px;color:var(--cream);font-size:12px;letter-spacing:1px;z-index:999;animation:toastIn .4s ease both;box-shadow:0 8px 30px rgba(0,0,0,.6);max-width:340px}
.toast.hide{animation:toastOut .3s ease both}
@keyframes toastIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:none}}
@keyframes toastOut{to{opacity:0;transform:translateX(30px)}}
.spinner{display:inline-block;width:18px;height:18px;border:2px solid rgba(212,168,67,.2);border-top-color:var(--gold);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle}
@keyframes spin{to{transform:rotate(360deg)}}
.empty-state{text-align:center;padding:80px 20px;grid-column:1/-1}
.empty-state .icon{font-size:60px;opacity:.2;margin-bottom:16px}
.empty-state p{font-size:13px;letter-spacing:2px;color:var(--mid-brown)}

@media(max-width:640px){
  .detail-layout{grid-template-columns:1fr}
  .form-grid{grid-template-columns:1fr}.form-grid .full{grid-column:1}
  header{padding:0 16px}
  .products-section,.search-wrap,.shop-tabs,.sold-history{padding-left:16px;padding-right:16px}
  .login-box{padding:36px 24px;width:100%}
  .modal-box{margin:0 8px}
}
</style>
</head>
<body>

<!-- LOGIN -->
<div id="loginPage">
  <div class="login-box">
    <div class="login-logo">
      <div class="brand">MICAH PRODUCTS</div>
      <div class="sub">Boutique Management System</div>
    </div>
    <div class="role-tabs">
      <div class="role-tab active" onclick="setLoginRole('admin')">Admin</div>
      <div class="role-tab" onclick="setLoginRole('staff')">Staff</div>
    </div>
    <div class="form-group">
      <label>Username</label>
      <input type="text" id="loginUser" placeholder="Enter username" />
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" id="loginPass" placeholder="Enter password" />
    </div>
    <button class="btn-primary" id="loginBtn" onclick="doLogin()">Sign In</button>
    <div class="login-err" id="loginErr"></div>
    <div style="margin-top:20px;text-align:center;font-size:11px;color:var(--mid-brown);letter-spacing:1px">Admin: admin / admin123 &nbsp;|&nbsp; Staff: staff / staff123</div>
  </div>
</div>

<!-- APP -->
<div id="app">
  <header>
    <div class="header-brand" onclick="showView('products')">MICAH <span>BOUTIQUE</span></div>
    <div class="header-nav">
      <button class="nav-btn active" onclick="showView('products')">Products</button>
      <button class="nav-btn" id="historyBtn" onclick="showView('history')" style="display:none">Sales Log</button>
      <button class="nav-btn gold" id="addBtn" onclick="openAddModal()" style="display:none">+ Add Product</button>
      <div class="user-badge"><div class="role-dot" id="roleDot"></div><span id="userLabel">User</span></div>
      <button class="nav-btn" onclick="doLogout()">Logout</button>
    </div>
  </header>

  <div class="search-wrap">
    <div class="search-input-wrap">
      <input type="text" id="searchInput" placeholder="Search by name, category, colour…" oninput="debounceSearch()" />
      <span class="search-icon">⌕</span>
    </div>
    <select class="filter-select" id="filterCat" onchange="loadProducts()">
      <option value="">All Categories</option>
      <option>Dresses</option><option>Tops</option><option>Bottoms</option>
      <option>Outerwear</option><option>Accessories</option><option>Shoes</option>
      <option>Bags</option><option>Jewellery</option>
    </select>
    <select class="filter-select" id="filterStatus" onchange="loadProducts()">
      <option value="">All Status</option>
      <option>In Stock</option><option>Out of Stock</option><option>Low Stock</option>
    </select>
  </div>

  <div id="viewProducts">
    <div class="shop-tabs">
      <div class="shop-tab active" onclick="setShop('all',this)">All Shops</div>
      <div class="shop-tab" onclick="setShop('Shop 1',this)">Shop 1</div>
      <div class="shop-tab" onclick="setShop('Shop 2',this)">Shop 2</div>
    </div>
    <div class="products-section">
      <div class="section-header">
        <div class="section-title">Our Collection</div>
        <div class="count-badge" id="prodCount"></div>
      </div>
      <div class="products-grid" id="productsGrid"><div class="empty-state"><div class="spinner"></div></div></div>
    </div>
  </div>

  <div id="viewHistory" style="display:none">
    <div class="sold-history">
      <div class="section-header">
        <div class="section-title">Sales Log</div>
        <button class="btn-cancel" onclick="clearLog()">Clear All</button>
      </div>
      <div style="overflow-x:auto">
        <table class="sold-table">
          <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Units Sold</th><th>Shop</th><th>Sold By</th><th>Date &amp; Time</th></tr></thead>
          <tbody id="historyBody"></tbody>
        </table>
      </div>
      <div id="historyEmpty" class="empty-state" style="display:none"><div class="icon">📋</div><p>No sales recorded yet</p></div>
    </div>
  </div>
</div>

<!-- DETAIL MODAL -->
<div class="modal-overlay" id="detailModal" style="display:none" onclick="overlayClose(event,'detailModal')">
  <div class="modal-box" style="max-width:800px;width:100%">
    <button class="modal-close" onclick="closeModal('detailModal')">✕</button>
    <div class="detail-layout">
      <div class="detail-img">
        <div class="no-img-lg" id="dNoImg">👗</div>
        <img id="dImg" src="" alt="" style="display:none" />
      </div>
      <div class="detail-body">
        <div class="detail-shop"     id="dShop"></div>
        <div class="detail-category" id="dCat"></div>
        <div class="detail-name"     id="dName"></div>
        <div class="detail-price"    id="dPrice"></div>

        <!-- Quantity + threshold block -->
        <div class="qty-card">
          <div class="qty-card-block">
            <div class="qty-card-label">Quantity in Stock</div>
            <div class="qty-card-val" id="dQty">0</div>
            <div class="qty-card-unit">units remaining</div>
          </div>
          <div class="qty-card-block">
            <div class="qty-card-label">Low Stock Threshold</div>
            <div class="qty-card-val" id="dThreshold">5</div>
            <div class="qty-card-unit">units trigger warning</div>
          </div>
        </div>

        <div class="detail-status-badge" id="dStatus"></div>

        <div class="detail-divider"></div>
        <div class="detail-label">Available Sizes</div>
        <div class="detail-sizes" id="dSizes"></div>
        <div class="detail-label">Colours</div>
        <div class="detail-colors" id="dColors"></div>
        <div class="detail-divider"></div>
        <button class="sold-btn-lg" id="detailSoldBtn" onclick="markSoldDetail()">
          ✔ Mark as Sold &nbsp;<span style="opacity:.6;font-size:10px;letter-spacing:1px">(deducts 1 unit)</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ADD/EDIT MODAL -->
<div class="modal-overlay" id="formModal" style="display:none" onclick="overlayClose(event,'formModal')">
  <div class="modal-box form-modal">
    <button class="modal-close" onclick="closeModal('formModal')">✕</button>
    <h2 id="formTitle">Add Product</h2>
    <div class="sub" id="formSub">Fill in the details below</div>
    <div class="form-grid">
      <input type="hidden" id="fId" />
      <div class="form-group full">
        <label>Product Name *</label>
        <input type="text" id="fName" placeholder="e.g. Silk Evening Gown" />
      </div>
      <div class="form-group">
        <label>Category *</label>
        <select id="fCat">
          <option value="">Select…</option>
          <option>Dresses</option><option>Tops</option><option>Bottoms</option>
          <option>Outerwear</option><option>Accessories</option>
          <option>Shoes</option><option>Bags</option><option>Jewellery</option>
        </select>
      </div>
      <div class="form-group">
        <label>Shop *</label>
        <select id="fShop">
          <option value="">Select…</option>
          <option>Shop 1</option><option>Shop 2</option>
        </select>
      </div>
      <div class="form-group full">
        <label>Sizes (comma-separated)</label>
        <input type="text" id="fSizes" placeholder="S, M, L, XL" />
      </div>
      <div class="form-group full">
        <label>Colours (comma-separated)</label>
        <input type="text" id="fColors" placeholder="Black, Brown, Gold" />
      </div>
      <div class="form-group">
        <label>Price (KES) *</label>
        <input type="number" id="fPrice" placeholder="e.g. 4500" min="0" step="1" />
      </div>
      <div class="form-group">
        <label>Quantity in Stock *</label>
        <input type="number" id="fQty" placeholder="e.g. 20" min="0" step="1" oninput="updateStatusPreview()" />
        <div class="qty-hint">Enter 0 if the item is not yet in stock.</div>
      </div>
      <div class="form-group full">
        <label>Low Stock Warning Threshold *</label>
        <input type="number" id="fThreshold" placeholder="e.g. 5" min="1" step="1" value="5" oninput="updateStatusPreview()" />
        <div class="qty-hint">When stock falls to this number or below, the status automatically becomes "Low Stock".</div>
        <div id="statusPreview" class="status-preview" style="display:none"></div>
      </div>
      <div class="form-group full">
        <label>Product Image</label>
        <div class="img-upload">
          <input type="file" id="fImg" accept="image/*" onchange="previewImg(event)" />
          <div class="icon">📷</div>
          <p>Click or drag to upload image</p>
        </div>
        <img id="imgPreview" class="img-preview-thumb" src="" alt="Preview" />
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-cancel" onclick="closeModal('formModal')">Cancel</button>
      <button class="btn-save" id="saveBtn" onclick="saveProduct()">Save Product</button>
    </div>
  </div>
</div>

<!-- CONFIRM DELETE -->
<div class="modal-overlay" id="confirmModal" style="display:none" onclick="overlayClose(event,'confirmModal')">
  <div class="modal-box confirm-box">
    <button class="modal-close" onclick="closeModal('confirmModal')">✕</button>
    <h3>Delete Product?</h3>
    <p>This action cannot be undone. The product will be permanently removed from the database.</p>
    <div class="confirm-actions">
      <button class="btn-cancel" onclick="closeModal('confirmModal')">Cancel</button>
      <button class="btn-danger" onclick="confirmDelete()">Delete</button>
    </div>
  </div>
</div>

<script>
let currentRole='', currentUser='', currentDetailId=null, deletingId=null, editingId=null;
let activeShop='all', searchTimer=null, productCache={};

async function api(action, opts={}){
  try{ return await (await fetch(`?api=${action}`,opts)).json(); }
  catch(e){ showToast('⚠️ Network error'); return {error:e.message}; }
}

// Boot
(async()=>{ const s=await api('session'); if(s.ok) bootApp(s.role,s.username); })();

function bootApp(role,username){
  currentRole=role; currentUser=username;
  document.getElementById('loginPage').style.display='none';
  document.getElementById('app').style.cssText='display:flex;flex-direction:column;min-height:100vh';
  document.getElementById('userLabel').textContent=username;
  document.getElementById('roleDot').className='role-dot '+(role==='admin'?'role-admin':'role-staff');
  if(role==='admin'){ document.getElementById('addBtn').style.display=''; document.getElementById('historyBtn').style.display=''; }
  loadProducts();
}

// Login / Logout
let loginRoleSelected='admin';
function setLoginRole(r){ loginRoleSelected=r; document.querySelectorAll('.role-tab').forEach((t,i)=>t.classList.toggle('active',(i===0&&r==='admin')||(i===1&&r==='staff'))); }
async function doLogin(){
  const username=document.getElementById('loginUser').value.trim();
  const password=document.getElementById('loginPass').value;
  if(!username||!password){document.getElementById('loginErr').textContent='Please enter credentials.';return;}
  const btn=document.getElementById('loginBtn'); btn.innerHTML='<span class="spinner"></span>'; btn.disabled=true;
  const d=await api('login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username,password})});
  btn.innerHTML='Sign In'; btn.disabled=false;
  if(d.ok){document.getElementById('loginErr').textContent='';bootApp(d.role,d.username);}
  else document.getElementById('loginErr').textContent=d.msg||'Invalid credentials';
}
async function doLogout(){
  await api('logout'); currentRole=''; currentUser='';
  document.getElementById('app').style.display='none';
  document.getElementById('loginPage').style.display='flex';
  ['loginUser','loginPass'].forEach(id=>document.getElementById(id).value='');
  ['addBtn','historyBtn'].forEach(id=>document.getElementById(id).style.display='none');
}
document.getElementById('loginPass').addEventListener('keydown',e=>{ if(e.key==='Enter') doLogin(); });

// Nav
function showView(v){
  document.getElementById('viewProducts').style.display=v==='products'?'block':'none';
  document.getElementById('viewHistory').style.display=v==='history'?'block':'none';
  if(v==='history') loadHistory();
}
function setShop(shop,el){ activeShop=shop; document.querySelectorAll('.shop-tab').forEach(t=>t.classList.remove('active')); el.classList.add('active'); loadProducts(); }

// Status logic (mirrors PHP)
function calcStatus(qty,threshold){ if(qty<=0) return 'Out of Stock'; if(qty<=threshold) return 'Low Stock'; return 'In Stock'; }
function statusClass(s){ return s==='In Stock'?'status-in':s==='Out of Stock'?'status-out':'status-low'; }

// Load products
async function loadProducts(){
  const q   =encodeURIComponent(document.getElementById('searchInput').value.trim());
  const cat =encodeURIComponent(document.getElementById('filterCat').value);
  const sta =encodeURIComponent(document.getElementById('filterStatus').value);
  const shop=encodeURIComponent(activeShop);
  const grid=document.getElementById('productsGrid');
  grid.innerHTML='<div class="empty-state"><div class="spinner"></div></div>';
  const products=await api(`products&q=${q}&category=${cat}&status=${sta}&shop=${shop}`);
  if(!Array.isArray(products)){grid.innerHTML='<div class="empty-state"><p>Error loading products</p></div>';return;}
  products.forEach(p=>productCache[p.id]=p);
  document.getElementById('prodCount').textContent=`${products.length} item${products.length!==1?'s':''}`;
  if(!products.length){grid.innerHTML='<div class="empty-state"><div class="icon">🔍</div><p>No products found</p></div>';return;}
  grid.innerHTML=products.map(productCard).join('');
}
function debounceSearch(){ clearTimeout(searchTimer); searchTimer=setTimeout(loadProducts,350); }

function productCard(p){
  const qty      = Number(p.quantity);
  const threshold= Number(p.low_stock_threshold);
  const status   = calcStatus(qty, threshold);
  const sc       = statusClass(status);
  const outOfStock = qty<=0;
  const dots     = (p.colors||[]).map(c=>`<div class="color-dot" style="background:${colorHex(c)}" title="${c}"></div>`).join('');
  const imgH     = p.img?`<img src="${p.img}" alt="${esc(p.name)}" />`:`<div class="no-img">👗</div>`;
  const adminBtns= currentRole==='admin'
    ?`<button class="btn-edit"   onclick="event.stopPropagation();openEdit(${p.id})"   title="Edit">✏️</button>
      <button class="btn-delete" onclick="event.stopPropagation();openDelete(${p.id})" title="Delete">🗑️</button>`:'';
  return `<div class="product-card" onclick="openDetail(${p.id})">
    <div class="card-img">${imgH}
      <div class="status-badge ${sc}">${status}</div>
      <div class="shop-tag">${p.shop}</div>
    </div>
    <div class="card-body">
      <div class="card-category">${p.category}</div>
      <div class="card-name">${esc(p.name)}</div>
      <div class="card-price">KES ${Number(p.price).toLocaleString()}</div>
      <div class="qty-pill">📦 <strong>${qty}</strong> units &nbsp;·&nbsp; low @ ${threshold}</div>
      <div class="card-colors">${dots}</div>
    </div>
    <div class="card-actions">
      <button class="btn-sold" ${outOfStock?'disabled':''} onclick="event.stopPropagation();markSold(${p.id},'${esc(p.name)}')" >
        ${outOfStock?'✖ Out of Stock':'✔ Sold'}
      </button>
      ${adminBtns}
    </div>
  </div>`;
}

// Detail modal
async function openDetail(id){
  const list=await api('products');
  if(Array.isArray(list)) list.forEach(p=>productCache[p.id]=p);
  const p=productCache[id]; if(!p) return;
  currentDetailId=id;
  const qty=Number(p.quantity), threshold=Number(p.low_stock_threshold);
  const status=calcStatus(qty,threshold);
  document.getElementById('dShop').textContent    = p.shop;
  document.getElementById('dCat').textContent     = p.category;
  document.getElementById('dName').textContent    = p.name;
  document.getElementById('dPrice').textContent   = `KES ${Number(p.price).toLocaleString()}`;
  document.getElementById('dQty').textContent     = qty;
  document.getElementById('dThreshold').textContent= threshold;
  const ds=document.getElementById('dStatus'); ds.textContent=status; ds.className='detail-status-badge '+statusClass(status);
  document.getElementById('dSizes').innerHTML     = (p.sizes||[]).map(s=>`<div class="size-chip">${s}</div>`).join('')||'<span style="color:var(--mid-brown)">—</span>';
  document.getElementById('dColors').innerHTML    = (p.colors||[]).map(c=>`<div class="color-chip"><div class="color-swatch" style="background:${colorHex(c)}"></div>${c}</div>`).join('')||'<span style="color:var(--mid-brown)">—</span>';
  document.getElementById('detailSoldBtn').disabled = qty<=0;
  if(p.img){document.getElementById('dImg').src=p.img;document.getElementById('dImg').style.display='block';document.getElementById('dNoImg').style.display='none';}
  else{document.getElementById('dImg').style.display='none';document.getElementById('dNoImg').style.display='block';}
  openModal('detailModal');
}
async function markSoldDetail(){ await markSold(currentDetailId, productCache[currentDetailId]?.name||''); closeModal('detailModal'); }

// Add / Edit
function openAddModal(){
  editingId=null;
  document.getElementById('formTitle').textContent='Add Product';
  document.getElementById('formSub').textContent='Fill in the details below';
  document.getElementById('fId').value='';
  ['fName','fSizes','fColors','fPrice','fQty'].forEach(id=>document.getElementById(id).value='');
  ['fCat','fShop'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('fThreshold').value='5';
  document.getElementById('fImg').value='';
  document.getElementById('imgPreview').style.display='none';
  document.getElementById('statusPreview').style.display='none';
  openModal('formModal');
}
async function openEdit(id){
  const list=await api('products'); if(Array.isArray(list)) list.forEach(p=>productCache[p.id]=p);
  const p=productCache[id]; if(!p) return;
  editingId=id;
  document.getElementById('formTitle').textContent='Edit Product';
  document.getElementById('formSub').textContent='Update the product details';
  document.getElementById('fId').value        = p.id;
  document.getElementById('fName').value      = p.name;
  document.getElementById('fCat').value       = p.category;
  document.getElementById('fShop').value      = p.shop;
  document.getElementById('fSizes').value     = (p.sizes||[]).join(', ');
  document.getElementById('fColors').value    = (p.colors||[]).join(', ');
  document.getElementById('fPrice').value     = p.price;
  document.getElementById('fQty').value       = p.quantity;
  document.getElementById('fThreshold').value = p.low_stock_threshold;
  if(p.img){const pr=document.getElementById('imgPreview');pr.src=p.img;pr.style.display='block';}
  else document.getElementById('imgPreview').style.display='none';
  updateStatusPreview();
  openModal('formModal');
}

// Live preview as admin types
function updateStatusPreview(){
  const qty=parseInt(document.getElementById('fQty').value)||0;
  const thr=parseInt(document.getElementById('fThreshold').value)||5;
  const status=calcStatus(qty,thr);
  const el=document.getElementById('statusPreview');
  el.textContent='⚡ Auto Status: '+status; el.style.display='inline-flex';
  el.className='status-preview '+statusClass(status);
}

function previewImg(e){
  const f=e.target.files[0]; if(!f) return;
  const r=new FileReader(); r.onload=ev=>{const pr=document.getElementById('imgPreview');pr.src=ev.target.result;pr.style.display='block';}; r.readAsDataURL(f);
}

async function saveProduct(){
  const name      = document.getElementById('fName').value.trim();
  const cat       = document.getElementById('fCat').value;
  const shop      = document.getElementById('fShop').value;
  const price     = document.getElementById('fPrice').value;
  const qty       = document.getElementById('fQty').value;
  const threshold = document.getElementById('fThreshold').value;
  if(!name||!cat||!shop||!price||qty===''||!threshold){showToast('⚠️ Please fill all required fields');return;}
  const fd=new FormData();
  fd.append('name',      name);
  fd.append('category',  cat);
  fd.append('shop',      shop);
  fd.append('sizes',     document.getElementById('fSizes').value);
  fd.append('colors',    document.getElementById('fColors').value);
  fd.append('price',     price);
  fd.append('quantity',  qty);
  fd.append('threshold', threshold);
  const imgFile=document.getElementById('fImg').files[0];
  if(imgFile) fd.append('img',imgFile);
  const btn=document.getElementById('saveBtn'); btn.innerHTML='<span class="spinner"></span>'; btn.disabled=true;
  let data;
  if(editingId){fd.append('id',editingId); data=await api('update_product',{method:'POST',body:fd});}
  else data=await api('add_product',{method:'POST',body:fd});
  btn.innerHTML='Save Product'; btn.disabled=false;
  if(data.ok||data.id){showToast((editingId?'✅ Product updated! ':'✅ Product added! ')+'Status: '+(data.status||''));closeModal('formModal');loadProducts();}
  else showToast('⚠️ '+(data.error||'Save failed'));
}

// Delete
function openDelete(id){deletingId=id;openModal('confirmModal');}
async function confirmDelete(){
  const d=await api('delete_product',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:deletingId})});
  closeModal('confirmModal');
  if(d.ok){showToast('🗑️ Product deleted.');loadProducts();}
  else showToast('⚠️ Delete failed');
}

// Mark sold — deducts 1 unit
async function markSold(id,name){
  const d=await api('mark_sold',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
  if(d.ok){showToast(`✔ "${name}" sold! Stock: ${d.new_quantity} left · ${d.new_status}`);loadProducts();}
  else showToast('⚠️ '+(d.error||'Error'));
}

// History
async function loadHistory(){
  const rows=await api('sold_log');
  const body=document.getElementById('historyBody');
  const empty=document.getElementById('historyEmpty');
  if(!Array.isArray(rows)||!rows.length){body.innerHTML='';empty.style.display='block';return;}
  empty.style.display='none';
  body.innerHTML=rows.map(r=>`<tr>
    <td style="color:var(--cream)">${esc(r.product_name)}</td>
    <td>${esc(r.category)}</td>
    <td style="color:var(--gold)">KES ${Number(r.price).toLocaleString()}</td>
    <td style="color:var(--gold-light);font-weight:600;text-align:center">${r.quantity_sold}</td>
    <td>${r.shop}</td>
    <td>${esc(r.sold_by)}</td>
    <td style="font-size:11px">${r.sold_at}</td>
  </tr>`).join('');
}
async function clearLog(){
  const d=await api('clear_log',{method:'POST'});
  if(d.ok){showToast('🗑️ Sales log cleared.');loadHistory();}
}

// Modal helpers
function openModal(id){document.getElementById(id).style.display='flex';}
function closeModal(id){document.getElementById(id).style.display='none';}
function overlayClose(e,id){if(e.target===document.getElementById(id))closeModal(id);}
document.addEventListener('keydown',e=>{if(e.key==='Escape')['detailModal','formModal','confirmModal'].forEach(closeModal);});

// Utils
function colorHex(n){const m={black:'#1a1a1a',white:'#f8f8f8',brown:'#8b4513',gold:'#d4a843',red:'#c0392b',blue:'#2980b9',green:'#27ae60',pink:'#e91e8c',purple:'#9b59b6',grey:'#95a5a6',gray:'#95a5a6',beige:'#f5deb3',camel:'#c19a6b',ivory:'#fffff0',nude:'#e8c9a0',burgundy:'#800020',chocolate:'#7b3f00',cream:'#fffdd0'};return m[(n||'').toLowerCase()]||'#8b5e3c';}
function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
let toastTimer;
function showToast(msg){let t=document.querySelector('.toast');if(t)t.remove();t=document.createElement('div');t.className='toast';t.textContent=msg;document.body.appendChild(t);clearTimeout(toastTimer);toastTimer=setTimeout(()=>{t.classList.add('hide');setTimeout(()=>t.remove(),400);},3800);}
</script>
</body>
</html>
