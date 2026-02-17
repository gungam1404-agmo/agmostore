<?php
// =================================================================================
// 1. KONEKSI DATABASE & INITIALIZATION
// =================================================================================
session_start();
// Error reporting dimatikan agar tidak merusak format JSON saat API dipanggil
ini_set('display_errors', 0);
error_reporting(0);

// --- DATABASE CONFIGURATION ---
$host = 'mysql-2e597e63-gungam1404-1126.j.aivencloud.com';
$port = 22289;
$user = 'avnadmin';
$pass = 'AVNS__imhTS0PWxbfaJQhVbP';
$db   = 'defaultdb';

$conn = null;
$db_connected = false;

// KONEKSI DATABASE (SOFT CONNECT)
try {
    $conn = new mysqli($host, $user, $pass, $db, $port);
    if ($conn->connect_error) {
        throw new Exception("Connect Error");
    }
    $db_connected = true;
} catch (Exception $e) {
    $db_connected = false;
}

// =================================================================================
// 2. API HANDLER (BACKEND LOGIC)
// =================================================================================

if (isset($_GET['api'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    // API: GOOGLE AUTH
    if ($_GET['api'] == 'google_auth') {
        if(!$db_connected) { echo json_encode(['status'=>'ok']); exit; } 
        
        $in = json_decode(file_get_contents('php://input'), true);
        $email = $conn->real_escape_string($in['email']);
        $name  = $conn->real_escape_string($in['name']);
        $pic   = isset($in['picture']) ? $conn->real_escape_string($in['picture']) : "";
        
        $check = $conn->query("SELECT * FROM `web_users` WHERE `email`='$email'");
        if ($check && $check->num_rows == 0) {
            $pass_dummy = password_hash(time(), PASSWORD_DEFAULT);
            $conn->query("INSERT INTO `web_users` (`name`, `email`, `password`, `phone`, `avatar`) VALUES ('$name', '$email', '$pass_dummy', '-', '$pic')");
            $uid = $conn->insert_id; $phone = '-';
        } elseif($check) {
            $data = $check->fetch_assoc();
            $uid = $data['id']; $phone = $data['phone'];
            $conn->query("UPDATE `web_users` SET `avatar`='$pic' WHERE `id`=$uid");
        } else {
            $uid=1; $phone='-';
        }
        $_SESSION['uid'] = $uid; $_SESSION['uname'] = $name; $_SESSION['uemail'] = $email; $_SESSION['uphone'] = $phone; $_SESSION['uphoto'] = $pic;
        echo json_encode(['status'=>'ok']); exit;
    }

    // API: CREATE ORDER
    if ($_GET['api'] == 'create_order') {
        $in = json_decode(file_get_contents('php://input'), true);
        
        // --- GENERATE NOMOR FAKTUR UNIK ---
        // Format: AGMO-HariBulanTahun-AngkaAcak (Contoh: AGMO-010226-4029)
        $inv_ref = "AGMO-" . date("dmy") . "-" . rand(1000, 9999);

        // JIKA DATABASE CONNECTED, SIMPAN BENERAN
        if ($db_connected) {
            $name   = $conn->real_escape_string($in['name']);
            $wa     = $conn->real_escape_string($in['wa']);
            $items  = $conn->real_escape_string($in['items']);
            $total  = (int)$in['total'];
            $method = $in['method'];
            $email  = isset($_SESSION['uemail']) ? $_SESSION['uemail'] : '-';

            $sql = "INSERT INTO `orders` (`customer_name`, `customer_wa`, `email`, `items`, `total_price`, `payment_method`, `status`, `account_info`, `created_at`) 
                    VALUES ('$name', '$wa', '$email', '$items', '$total', '$method', 'pending', '', NOW())";
            
            if($conn->query($sql)) {
                $oid = $conn->insert_id;
                echo json_encode(['status' => 'ok', 'order_id' => $oid, 'invoice_ref' => $inv_ref]);
                exit;
            }
        }
        
        // JIKA DB OFFLINE, TETAP KIRIM REF CODE AGAR BISA DITANGKAP JS
        echo json_encode([
            'status' => 'error', 
            'message' => 'DB Offline', 
            'fallback_ref' => $inv_ref // Kirim ref code meskipun offline
        ]); 
        exit;
    }

    // API: CHECK STATUS
    if ($_GET['api'] == 'check_status') {
        if(!$db_connected) { echo json_encode(['status' => 'pending']); exit; }
        $id = (int)$_GET['id'];
        $q = $conn->query("SELECT `status`, `account_info` FROM `orders` WHERE `id`=$id");
        if($q && $q->num_rows > 0) { echo json_encode($q->fetch_assoc()); } 
        else { echo json_encode(['status' => 'not_found']); }
        exit;
    }

    // API: HISTORY
    if ($_GET['api'] == 'check_history') {
        if(!$db_connected || !isset($_SESSION['uemail'])) { echo json_encode([]); exit; }
        $res = $conn->query("SELECT * FROM `orders` WHERE `email`='{$_SESSION['uemail']}' ORDER BY `id` DESC LIMIT 20");
        $data=[]; 
        if($res) { while($r=$res->fetch_assoc()){ $r['formatted_date'] = date("d M H:i", strtotime($r['created_at'])); $data[]=$r; } }
        echo json_encode($data); exit;
    }

    // API: LOGOUT
    if ($_GET['api'] == 'logout') { session_destroy(); echo json_encode(['status'=>'ok']); exit; }
}

// =================================================================================
// 3. LOGIC AUTH MANUAL
// =================================================================================
$auth_notification = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['act']) && $db_connected) {
    if ($_POST['act'] == 'login') {
        $e = $conn->real_escape_string($_POST['email']);
        $p = $_POST['password'];
        $r = $conn->query("SELECT * FROM `web_users` WHERE `email`='$e'");
        if($r && $r->num_rows > 0){
            $u = $r->fetch_assoc();
            if(password_verify($p, $u['password'])){
                $_SESSION['uid']=$u['id']; $_SESSION['uname']=$u['name']; $_SESSION['uemail']=$u['email']; 
                $_SESSION['uphone']=$u['phone']; $_SESSION['uphoto']= ($u['avatar']=='default' || empty($u['avatar'])) ? "https://ui-avatars.com/api/?name=".urlencode($u['name']) : $u['avatar'];
                header("Location: index.php"); exit;
            } else { $auth_notification = "Password Salah!"; }
        } else { $auth_notification = "Email Tidak Ditemukan!"; }
    }
    if ($_POST['act'] == 'register') {
        $n=$conn->real_escape_string($_POST['name']); $e=$conn->real_escape_string($_POST['email']); 
        $p=password_hash($_POST['password'], PASSWORD_DEFAULT); $ph=$conn->real_escape_string($_POST['phone']);
        $cek = $conn->query("SELECT * FROM `web_users` WHERE `email`='$e'");
        if($cek->num_rows > 0) { $auth_notification = "Email Sudah Terdaftar!"; } 
        else {
            $conn->query("INSERT INTO `web_users` (`name`, `email`, `password`, `phone`, `avatar`) VALUES ('$n', '$e', '$p', '$ph', 'default')");
            header("Location: index.php"); exit;
        }
    }
}

// =================================================================================
// 4. DATA FETCHING
// =================================================================================
$products = [];
if ($db_connected) {
    $checkTable = $conn->query("SHOW TABLES LIKE 'products'");
    if($checkTable && $checkTable->num_rows > 0) {
        $res_prod = $conn->query("SELECT * FROM `products` ORDER BY `name` ASC");
        if($res_prod) { while($row = $res_prod->fetch_assoc()) { $row['price'] = (int)$row['price']; $products[] = $row; } }
    }
}
// Jika DB Kosong/Offline, gunakan DATA DUMMY
if (empty($products)) {
    $products = [
        ['id'=>1, 'name'=>'Mobile Legends 86 Diamonds (OFFLINE)', 'price'=>20000, 'category'=>'Games'],
        ['id'=>2, 'name'=>'Mobile Legends 172 Diamonds (OFFLINE)', 'price'=>40000, 'category'=>'Games'],
        ['id'=>3, 'name'=>'Free Fire 140 Diamonds (OFFLINE)', 'price'=>19500, 'category'=>'Games'],
        ['id'=>8, 'name'=>'Spotify Premium 1 Bulan (OFFLINE)', 'price'=>25000, 'category'=>'Premium'],
        ['id'=>9, 'name'=>'Netflix Sharing 1 Bulan (OFFLINE)', 'price'=>35000, 'category'=>'Premium'],
    ];
}
$jsonData = json_encode($products);

$isLogin = isset($_SESSION['uid']);
$uName = $isLogin ? $_SESSION['uname'] : '';
$uEmail = $isLogin ? $_SESSION['uemail'] : '';
$uPhone = $isLogin ? $_SESSION['uphone'] : '';
$uPhoto = isset($_SESSION['uphoto']) ? $_SESSION['uphoto'] : "https://cdn-icons-png.flaticon.com/512/3135/3135715.png";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#000000">
    <title>Agmo Store Premium</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://accounts.google.com/gsi/client" async defer></script>

    <style>
        :root {
            --bg-dark: #000000;
            --glass-bg: rgba(20, 20, 20, 0.65);
            --glass-border: rgba(255, 255, 255, 0.12);
            --primary: #ffffff;
            --accent: #FF0055; 
            --accent-glow: rgba(255, 0, 85, 0.4);
            --neon-blue: #00f2ff;
            --text-main: #ffffff;
            --text-muted: #a3a3a3;
            --radius-xl: 24px;
            --radius-md: 16px;
            --sekali-blue: #0060ff;
            --sekali-dark: #0f1219;
            --sekali-header-grad: linear-gradient(90deg, #0033cc, #001155);
        }

        .hidden { display: none !important; }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; -webkit-tap-highlight-color: transparent; }
        
        body {
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(100, 0, 255, 0.25), transparent 60%),
                radial-gradient(circle at 90% 80%, rgba(255, 0, 85, 0.2), transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(0, 0, 0, 0.8), transparent 100%);
            color: var(--text-main);
            min-height: 100vh;
            overflow-x: hidden;
            padding-top: 80px;     
            padding-bottom: 120px;
        }

        .page { display: none; opacity: 0; transition: transform 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), opacity 0.4s ease; transform: translateX(20px); }
        .page.active { display: block; opacity: 1; transform: translateX(0); }
        .page.exit { opacity: 0; transform: translateX(-20px); }

        @keyframes float { 0% { transform: translateY(0px); } 50% { transform: translateY(-10px); } 100% { transform: translateY(0px); } }

        /* NAVBAR */
        .navbar {
            position: fixed; top: 0; left: 0; right: 0; height: 75px; z-index: 1000;
            background: rgba(0, 0, 0, 0.8); backdrop-filter: blur(25px); -webkit-backdrop-filter: blur(25px);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 5%;
        }
        .logo-text { font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px; display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .logo-icon { width: 35px; height: 35px; background: var(--accent); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; box-shadow: 0 0 15px var(--accent-glow); }

        .search-bar-wrap { flex: 1; margin: 0 20px; max-width: 500px; position: relative; display: none; }
        @media(min-width: 768px) { .search-bar-wrap { display: block; } }
        
        .search-input { width: 100%; padding: 12px 20px 12px 45px; border-radius: 50px; background: rgba(255,255,255,0.1); border: none; color: white; outline: none; transition: 0.3s; }
        .search-input:focus { background: rgba(255,255,255,0.2); box-shadow: 0 0 15px rgba(255,255,255,0.1); }
        .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #aaa; }

        .nav-actions { display: flex; align-items: center; gap: 18px; }
        .nav-btn { font-size: 1.3rem; color: #fff; cursor: pointer; position: relative; transition: 0.2s; }
        .nav-btn:hover { color: var(--accent); transform: scale(1.1); }
        .cart-badge { position: absolute; top: -5px; right: -8px; background: var(--accent); font-size: 0.7rem; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .user-pic { width: 38px; height: 38px; border-radius: 50%; border: 2px solid var(--accent); cursor: pointer; object-fit: cover; }

        /* CONTAINER & GRID */
        .container { width: 92%; max-width: 1200px; margin: 0 auto; }
        .grid-layout { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        @media(min-width: 600px) { .grid-layout { grid-template-columns: repeat(3, 1fr); } }
        @media(min-width: 900px) { .grid-layout { grid-template-columns: repeat(5, 1fr); gap: 25px; } }

        .card-glass {
            background: linear-gradient(145deg, rgba(255,255,255,0.08), rgba(255,255,255,0.02));
            backdrop-filter: blur(20px); border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl); padding: 20px; text-align: center;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); cursor: pointer;
            position: relative; overflow: hidden;
        }
        .card-glass:hover { transform: translateY(-8px) scale(1.02); border-color: rgba(255,255,255,0.3); box-shadow: 0 15px 30px rgba(0,0,0,0.5); }
        .card-img { width: 70px; height: 70px; object-fit: contain; margin-bottom: 15px; filter: drop-shadow(0 5px 15px rgba(0,0,0,0.5)); transition: 0.4s; }
        .card-glass:hover .card-img { transform: scale(1.1) rotate(-3deg); }
        .card-title { font-weight: 700; font-size: 1rem; margin-bottom: 5px; color: white; }
        .card-sub { font-size: 0.8rem; color: var(--text-muted); }

        .list-glass { display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 20px; border-radius: var(--radius-md); margin-bottom: 12px; cursor: pointer; transition: 0.2s; position: relative;}
        .list-glass:hover { background: rgba(255,255,255,0.08); border-color: var(--accent); }
        
        .detail-wrapper { display: flex; flex-direction: column; gap: 25px; }
        @media(min-width: 800px) { .detail-wrapper { flex-direction: row; align-items: flex-start; } .det-left { width: 35%; position: sticky; top: 100px; } .det-right { width: 65%; } }
        .big-img-box { width: 100%; aspect-ratio: 1/1; border-radius: var(--radius-xl); background: radial-gradient(circle, rgba(255,255,255,0.1), transparent 70%); display: flex; align-items: center; justify-content: center; border: 1px solid var(--glass-border); }
        .big-img { width: 60%; filter: drop-shadow(0 0 30px rgba(255, 0, 85, 0.3)); animation: float 6s ease-in-out infinite; }
        .det-title { font-size: 2rem; font-weight: 800; line-height: 1.1; margin-bottom: 10px; }
        .det-price { font-size: 2.5rem; font-weight: 900; color: var(--neon-blue); text-shadow: 0 0 20px rgba(0, 242, 255, 0.4); margin-bottom: 20px; }
        .glass-box { background: rgba(30, 30, 30, 0.4); backdrop-filter: blur(20px); border-radius: var(--radius-md); padding: 20px; border: 1px solid var(--glass-border); margin-bottom: 20px; }

        .inp-group { background: rgba(255,255,255,0.03); padding: 20px; border-radius: 16px; border: 1px solid var(--glass-border); margin-bottom: 20px; }
        .inp { width: 100%; padding: 14px; background: rgba(0,0,0,0.4); border: 1px solid #444; border-radius: 10px; color: white; margin-top: 5px; outline: none; transition: 0.3s; }
        .inp:focus { border-color: var(--neon); background: rgba(0,0,0,0.6); }
        
        .pay-card { display: flex; align-items: center; gap: 15px; padding: 15px; border-radius: 15px; background: rgba(255,255,255,0.05); border: 1px solid transparent; cursor: pointer; margin-bottom: 10px; transition: 0.3s; }
        .pay-card.active { border-color: var(--accent); background: rgba(255,0,85,0.1); transform: scale(1.01); }
        .pay-icon { font-size: 1.5rem; width: 40px; text-align: center; }

        .invoice-container { max-width: 600px; margin: 0 auto; margin-top: 20px; padding: 0 10px; }
        .invoice-header-box { background: var(--sekali-header-grad); padding: 30px 20px; border-radius: 16px 16px 0 0; text-align: center; border: 1px solid rgba(255,255,255,0.1); border-bottom: none; position: relative; overflow: hidden; }
        .invoice-header-box::after { content: ''; position: absolute; width: 200px; height: 200px; background: rgba(255,255,255,0.1); border-radius: 50%; top: -100px; left: -50px; filter: blur(50px); }
        .invoice-body-box { background: var(--sekali-dark); padding: 25px; border-radius: 0 0 16px 16px; border: 1px solid rgba(255,255,255,0.1); border-top: none; }
        .inv-timer-text { font-size: 2rem; font-weight: 800; color: #fff; margin: 5px 0; letter-spacing: 2px; text-shadow: 0 0 10px rgba(0,0,255,0.5); }
        .inv-sub { font-size: 0.8rem; color: #aaccff; opacity: 0.8; }
        .inv-row { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; border-bottom: 1px dashed rgba(255,255,255,0.1); font-size: 0.95rem; }
        .inv-row:last-child { border-bottom: none; }
        .inv-label { color: #888; }
        .inv-val { font-weight: 600; color: #fff; }
        .inv-ref-box { background: rgba(0,0,0,0.4); padding: 12px; border-radius: 8px; font-family: monospace; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border: 1px solid #333; }
        .success-state { display: none; text-align: center; padding: 20px; background: rgba(0, 255, 136, 0.05); border: 1px solid #00ff88; border-radius: 16px; margin-bottom: 20px; animation: fadeIn 0.5s ease; }
        .account-data-display { background: #000; padding: 15px; border-radius: 8px; margin-top: 15px; border: 1px solid #333; font-family: monospace; color: #00ff88; text-align: left; word-break: break-all; }
        .inv-btn-pay { width: 100%; padding: 16px; border-radius: 12px; background: var(--sekali-blue); color: white; font-weight: bold; font-size: 1.1rem; border: none; cursor: pointer; margin-top: 25px; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 5px 25px rgba(0, 96, 255, 0.4); transition: 0.3s; }
        .inv-btn-pay:hover { background: #004ecc; transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0, 96, 255, 0.6); }
        .btn-action { padding: 12px 35px; border-radius: 50px; font-weight: 800; border: none; cursor: pointer; background: linear-gradient(90deg, #ff0055, #ff5500); color: white; box-shadow: 0 0 20px rgba(255, 0, 85, 0.5); font-size: 1rem; }
        .btn-outline { background: transparent; border: 2px solid var(--accent); color: var(--accent); }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(0, 0, 0, 0.9); backdrop-filter: blur(30px); padding: 15px 5%; border-top: 1px solid rgba(255,255,255,0.1); display: flex; justify-content: space-between; align-items: center; z-index: 900; }
        .total-display { font-size: 1.3rem; font-weight: bold; color: white; }
        .overlay-glass { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(15px); z-index: 2000; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; transition: 0.5s; }
        .overlay-glass.hidden { opacity: 0; pointer-events: none; transform: scale(1.1); }
        .modal-box { background: rgba(20, 20, 20, 0.9); border: 1px solid var(--glass-border); border-radius: 30px; padding: 30px; width: 100%; max-width: 400px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.8); animation: float 5s ease-in-out infinite; }
        .btn-manual { background: transparent; border: 1px solid rgba(255,255,255,0.2); color: white; border-radius: 50px; padding: 12px; width: 100%; font-weight: bold; cursor: pointer; }
        .section-header { font-size: 1.5rem; font-weight: 800; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .section-header::before { content:''; width: 5px; height: 25px; background: var(--accent); border-radius: 5px; }
        .review-item { border-bottom: 1px solid rgba(255,255,255,0.05); padding: 15px 0; display: flex; gap: 15px; }
        .rev-avatar { width: 40px; height: 40px; background: #333; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: var(--accent); }
        .rev-img-post { width: 80px; height: 80px; object-fit: cover; border-radius: 10px; margin-top: 10px; border: 1px solid #555; cursor: pointer; }

        /* Custom Checkbox Styles */
        .cart-checkbox { width: 25px; height: 25px; accent-color: var(--accent); cursor: pointer; margin-right: 15px; }
    </style>
</head>
<body>
    <audio id="sfx-pop" src="https://assets.mixkit.co/active_storage/sfx/2571/2571-preview.mp3"></audio>
    <audio id="sfx-chime" src="https://assets.mixkit.co/active_storage/sfx/2019/2019-preview.mp3"></audio>

    <?php if(!$isLogin): ?>
    <div id="auth-overlay" class="overlay-glass">
        <?php if($auth_notification): ?>
            <div style="background:rgba(255,0,50,0.2); border:1px solid red; padding:10px 20px; border-radius:20px; margin-bottom:20px; color:#ff5555;">
                <i class="fas fa-exclamation-triangle"></i> <?= $auth_notification ?>
            </div>
        <?php endif; ?>

        <div id="auth-menu" class="modal-box">
            <div class="logo-icon" style="width:60px; height:60px; font-size:2rem; margin:0 auto 20px;">⚡</div>
            <h2 style="margin-bottom:10px;">AGMO STORE</h2>
            <p style="color:#888; margin-bottom:30px;">Top Up Termurah & Terpercaya</p>
            <div id="g_id_onload" data-client_id="556161451018-i98acet4c8gq6apidq4b47fhggndhkp1.apps.googleusercontent.com" data-callback="handleCredentialResponse"></div>
            <div class="g_id_signin" data-type="standard" data-shape="pill" data-theme="filled_black" data-text="signin_with" data-size="large" data-width="320" data-logo_alignment="left"></div>
            <div style="margin:20px 0; font-size:0.8rem; color:#555;">ATAU</div>
            <button class="btn-manual" onclick="showAuthForm('login')">Login Email</button>
            <p style="margin-top:20px; color:#888; font-size:0.9rem;">Belum punya akun? <span onclick="showAuthForm('register')" style="color:var(--accent); cursor:pointer; font-weight:bold;">Daftar</span></p>
        </div>

        <div id="auth-login" class="modal-box hidden" style="text-align:left;">
            <h3 style="margin-bottom:20px;">Masuk Akun</h3>
            <form method="POST">
                <input type="hidden" name="act" value="login">
                <div style="margin-bottom:15px;">
                    <label style="font-size:0.8rem; color:#aaa;">Email</label>
                    <input type="email" name="email" class="inp" style="background:rgba(0,0,0,0.5);" required>
                </div>
                <div style="margin-bottom:25px;">
                    <label style="font-size:0.8rem; color:#aaa;">Password</label>
                    <input type="password" name="password" class="inp" style="background:rgba(0,0,0,0.5);" required>
                </div>
                <button type="submit" class="btn-action" style="width:100%;">MASUK</button>
            </form>
            <div onclick="showAuthForm('menu')" style="text-align:center; margin-top:20px; color:#aaa; cursor:pointer;">Kembali</div>
        </div>

        <div id="auth-register" class="modal-box hidden" style="text-align:left;">
            <h3 style="margin-bottom:20px;">Buat Akun</h3>
            <form method="POST">
                <input type="hidden" name="act" value="register">
                <input type="text" name="name" placeholder="Nama Lengkap" class="inp" style="margin-bottom:10px; background:rgba(0,0,0,0.5);" required>
                <input type="number" name="phone" placeholder="No. WhatsApp" class="inp" style="margin-bottom:10px; background:rgba(0,0,0,0.5);" required>
                <input type="email" name="email" placeholder="Email" class="inp" style="margin-bottom:10px; background:rgba(0,0,0,0.5);" required>
                <input type="password" name="password" placeholder="Password" class="inp" style="margin-bottom:20px; background:rgba(0,0,0,0.5);" required>
                <button type="submit" class="btn-action" style="width:100%;">DAFTAR SEKARANG</button>
            </form>
            <div onclick="showAuthForm('menu')" style="text-align:center; margin-top:20px; color:#aaa; cursor:pointer;">Kembali</div>
        </div>
    </div>
    <?php endif; ?>

    <nav class="navbar">
        <div class="logo-text" onclick="goHome()">
            <div class="logo-icon">⚡</div> AGMO STORE
        </div>
        <div class="search-bar-wrap">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="global-search" class="search-input" placeholder="Cari game atau aplikasi..." onkeyup="doSearch()">
        </div>
        <div class="nav-actions">
            <div class="nav-btn" onclick="openHistory()"><i class="fas fa-history"></i></div>
            <div class="nav-btn" onclick="goPage('cart')">
                <i class="fas fa-shopping-bag"></i>
                <div class="cart-badge" id="cart-count">0</div>
            </div>
            <?php if($isLogin): ?>
                <img src="<?= $uPhoto ?>" class="user-pic" onclick="logoutConfirm()">
            <?php endif; ?>
        </div>
    </nav>

    <div class="container">
        
        <div id="page-home" class="page active">
            
            <div id="home-category-area">
                <div class="section-header">Pilih Kategori</div>
                <div id="grid-category" class="grid-layout"></div>
            </div>
            
            <div id="search-results" class="hidden">
                <div class="section-header" style="margin-top:30px;">Hasil Pencarian</div>
                <div id="grid-search" class="grid-layout"></div>
            </div>
        </div>

        <div id="page-variant" class="page">
            <div style="margin-bottom:20px; cursor:pointer; color:#aaa;" onclick="goHome()"><i class="fas fa-arrow-left"></i> Kembali</div>
            <h2 id="variant-title" style="margin-bottom:20px; font-size:2rem; font-weight:800;">Variants</h2>
            <div id="list-variant"></div>
        </div>

        <div id="page-detail" class="page">
            <div style="margin-bottom:20px; cursor:pointer; color:#aaa;" onclick="goPage('variant')"><i class="fas fa-arrow-left"></i> Kembali</div>
            
            <div class="detail-wrapper">
                <div class="det-left">
                    <div class="big-img-box"><img id="det-img" src="" class="big-img"></div>
                </div>

                <div class="det-right">
                    <div class="det-title" id="det-name">Product Name</div>
                    <div class="det-price" id="det-price">Rp 0</div>
                    
                    <div class="glass-box">
                        <h4 style="margin-bottom:10px; color:#aaa;">Deskripsi Produk</h4>
                        <p style="line-height:1.6; color:#ddd;">
                            ✅ <strong>Legal & Resmi 100%</strong><br>
                            ✅ Proses Cepat (1-10 Menit)<br>
                            ✅ Garansi Uang Kembali<br>
                            Silahkan pilih metode pembayaran di halaman checkout.
                        </p>
                    </div>

                    <div class="glass-box">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                            <h4>Ulasan Pelanggan</h4>
                            <span style="color:gold;">⭐ 4.9/5.0</span>
                        </div>
                        <div class="review-input-area">
                            <input type="text" id="rev-text" class="inp-review" placeholder="Tulis pengalamanmu..." style="width:70%; padding:10px; border-radius:10px; border:none;">
                            <button class="btn-icon btn-cam" onclick="document.getElementById('rev-file').click()"><i class="fas fa-camera"></i></button>
                            <input type="file" id="rev-file" hidden accept="image/*" onchange="previewRevImg(this)">
                            <button class="btn-icon btn-send" onclick="submitReview()"><i class="fas fa-paper-plane"></i></button>
                        </div>
                        <img id="rev-preview" style="width:60px; height:60px; border-radius:10px; margin-top:10px; display:none;">
                        <div id="review-list" style="margin-top:20px; max-height:300px; overflow-y:auto;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div id="page-cart" class="page">
            <div style="margin-bottom:20px; cursor:pointer; color:#aaa;" onclick="goHome()"><i class="fas fa-arrow-left"></i> Kembali ke Menu Utama</div>
            <h2 style="margin-bottom:20px;">Keranjang Belanja</h2>
            <div id="cart-container"></div>
        </div>

        <div id="page-checkout" class="page">
            <div style="margin-bottom:20px; cursor:pointer; color:#aaa;" onclick="goPage('cart')"><i class="fas fa-arrow-left"></i> Kembali</div>
            <h2 style="margin-bottom:20px;">Checkout & Pembayaran</h2>
            
            <div class="glass-box">
                <h4 style="margin-bottom:15px; color:#aaa;">Data Pengiriman</h4>
                <div style="margin-bottom:10px;"><input type="text" id="cx-name" class="inp" placeholder="Nama Lengkap" value="<?= $uName ?>"></div>
                <div style="margin-bottom:10px;"><input type="number" id="cx-wa" class="inp" placeholder="Nomor WhatsApp (Aktif)" value="<?= $uPhone ?>"></div>
                <div><textarea id="cx-note" class="inp" rows="2" placeholder="Catatan Tambahan (Opsional)"></textarea></div>
            </div>

            <div class="glass-box">
                <h4 style="margin-bottom:15px; color:#aaa;">Metode Pembayaran</h4>
                <div class="pay-card active" onclick="selPay(this, 'wallet')">
                    <div class="pay-icon" style="color:#00c6ff;"><i class="fas fa-wallet"></i></div>
                    <div><b>E-Wallet / QRIS</b><br><small style="color:#aaa;">Otomatis via LinkAja/Dana/Gopay</small></div>
                </div>
                <div class="pay-card" onclick="selPay(this, 'admin')">
                    <div class="pay-icon" style="color:#00ff9d;"><i class="fab fa-whatsapp"></i></div>
                    <div><b>Transfer Admin</b><br><small style="color:#aaa;">Chat Manual ke Admin</small></div>
                </div>
                <div class="pay-card" onclick="selPay(this, 'bot')">
                    <div class="pay-icon" style="color:#bc13fe;"><i class="fas fa-robot"></i></div>
                    <div><b>Bayar via Bot</b><br><small style="color:#aaa;">Otomatis via Bot</small></div>
                </div>
            </div>
        </div>

        <div id="page-invoice" class="page">
            <div class="invoice-container">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div style="width:30px; height:30px; background:var(--accent); border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold;">⚡</div>
                        <span style="font-weight:bold; font-size:1.2rem; color:#fff;">Agmo Store Payment</span>
                    </div>
                    <div style="background:#0a1e3f; padding:5px 15px; border-radius:20px; color:#0060ff; font-size:0.8rem;">
                        <i class="fas fa-shield-alt"></i> Payment Protected
                    </div>
                </div>

                <div class="invoice-header-box">
                    <div class="inv-sub">Waktu Tersisa</div>
                    <div class="inv-timer-text" id="inv-timer">09:59</div>
                    <div class="inv-sub" style="margin-top:5px; font-size:0.75rem;">Selesaikan pembayaran sebelum waktu habis.</div>
                </div>

                <div class="invoice-body-box">
                    
                    <div id="inv-success-area" class="success-state">
                        <i class="fas fa-check-circle" style="font-size:3.5rem; color:#00ff88; margin-bottom:10px;"></i>
                        <h3 style="margin-bottom:10px;">Transaksi Berhasil!</h3>
                        <p style="font-size:0.9rem; color:#aaa;">Pesanan kamu telah diproses.</p>
                        
                        <div class="account-data-display">
                            <div style="font-size:0.8rem; color:#888; margin-bottom:5px;">DATA AKUN / VOUCHER:</div>
                            <div id="inv-account-data" style="font-size:1rem; font-weight:bold;">Menunggu Data...</div>
                            <div style="text-align:right; margin-top:10px;">
                                <small style="color:var(--sekali-blue); cursor:pointer;" onclick="copyDataAccount()"><i class="fas fa-copy"></i> Salin</small>
                            </div>
                        </div>
                        
                        <button class="inv-btn-pay" onclick="goHome()" style="background:#333; margin-top:20px;">
                            <i class="fas fa-home"></i> KEMBALI KE BERANDA
                        </button>
                    </div>

                    <div id="inv-pending-area">
                        <h3 style="margin-bottom:15px;">Faktur Pembelian</h3>
                        <div class="inv-ref-box">
                            <span id="inv-ref-text" style="color:#ddd;">AGMO...</span>
                            <i class="fas fa-copy" style="cursor:pointer; color:var(--sekali-blue);" onclick="copyRef()"></i>
                        </div>

                        <div style="background:rgba(255,255,255,0.03); border-radius:10px; padding:15px; margin-bottom:20px;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:15px;">
                                <h4 style="margin:0;">Detail Pembayaran</h4>
                                <i class="fas fa-chevron-up"></i>
                            </div>
                            <div class="inv-row"><span class="inv-label">Tanggal</span> <span class="inv-val" id="inv-date">...</span></div>
                            <div class="inv-row"><span class="inv-label">Status</span> <span class="inv-val" style="background:rgba(255,165,0,0.2); color:orange; padding:2px 8px; border-radius:4px; font-size:0.8rem;">Menunggu Pembayaran</span></div>
                            <div class="inv-row"><span class="inv-label">Merchant</span> <span class="inv-val">Agmo Store</span></div>
                            <div class="inv-row"><span class="inv-label">Biaya Layanan</span> <span class="inv-val">Rp 150</span></div>
                            <div class="inv-row" style="border-top:1px solid #333; margin-top:10px; padding-top:10px;">
                                <span class="inv-label" style="color:white;">Total Bayar</span> 
                                <span class="inv-val" style="color:#0060ff; font-size:1.1rem;" id="inv-total">Rp 0</span>
                            </div>
                            <div class="inv-row"><span class="inv-label">Metode</span> <span class="inv-val" style="color:#00c6ff;" id="inv-method">DANA</span></div>
                        </div>

                        <button class="inv-btn-pay" onclick="app_doPaymentRedirect()">
                            <i class="fas fa-lock"></i> BAYAR SEKARANG
                        </button>
                        
                        <div style="text-align:center; margin-top:15px; color:#555; font-size:0.8rem; cursor:pointer;" onclick="location.reload()">Batalkan Transaksi</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div id="bottom-bar" class="bottom-nav" style="display:none;">
        <div id="bottom-bar-content" style="width:100%; display:flex; justify-content:space-between; align-items:center;">
            </div>
    </div>

    <div id="history-modal" class="overlay-glass hidden">
        <div class="modal-box" style="max-width:500px; text-align:left; height:80vh; display:flex; flex-direction:column;">
            <div style="display:flex; justify-content:space-between; margin-bottom:20px;">
                <h3>Riwayat Pesanan</h3>
                <span onclick="closeHistory()" style="cursor:pointer; font-size:1.5rem;">&times;</span>
            </div>
            <div id="history-content" style="overflow-y:auto; flex:1;"></div>
        </div>
    </div>

    <script>
        const products = <?= $jsonData ?>;
        const appIcon = "https://cdn-icons-png.flaticon.com/512/3616/3616930.png";
        
        let state = { 
            page: 'home', 
            cart: [], 
            detail: null, 
            qty: 1, 
            payment: 'wallet', 
            tempRevImg: null,
            currentInvoice: null,
            pollingInterval: null,
            timerInterval: null
        };
        
        const sfxPop = document.getElementById('sfx-pop');
        const sfxChime = document.getElementById('sfx-chime');
        function playSfx(type) { type==='pop'?sfxPop.play():sfxChime.play(); }

        // --- AUTH ---
        function showAuthForm(id) {
            document.getElementById('auth-menu').classList.add('hidden');
            document.getElementById('auth-login').classList.add('hidden');
            document.getElementById('auth-register').classList.add('hidden');
            document.getElementById('auth-'+id).classList.remove('hidden');
        }
        function handleCredentialResponse(res) {
            let p = JSON.parse(atob(res.credential.split('.')[1]));
            fetch('?api=google_auth', { method:'POST', body:JSON.stringify(p) })
            .then(r=>r.json()).then(d=>{ if(d.status==='ok') location.reload(); });
        }
        function logoutConfirm() {
            Swal.fire({title:'Logout?', icon:'warning', showCancelButton:true, confirmButtonText:'Ya', confirmButtonColor:'#ff0055', background:'#111', color:'#fff'}).then(r=>{
                if(r.isConfirmed) fetch('?api=logout').then(()=>location.reload());
            });
        }

        // --- NAVIGATION ---
        function goPage(pageId) {
            playSfx('pop');
            if(state.pollingInterval && pageId !== 'invoice') {
                clearInterval(state.pollingInterval);
                state.pollingInterval = null;
            }
            document.querySelectorAll('.page').forEach(el => {
                el.classList.remove('active');
                el.classList.add('exit');
                setTimeout(() => el.style.display = 'none', 300);
            });
            setTimeout(() => {
                const target = document.getElementById('page-'+pageId);
                target.style.display = 'block';
                target.classList.remove('exit');
                setTimeout(() => target.classList.add('active'), 10);
                window.scrollTo(0,0);
            }, 300);
            state.page = pageId;
            updateBottomBar();
        }
        function goHome() { goPage('home'); }

        // --- RENDER HOME ---
        function renderHome() {
            const cats = {};
            products.forEach(p => { 
                let n = p.name.split(' ').slice(0,2).join(' '); 
                if(p.type === 'promo') n = 'PROMO 🔥';
                cats[n] = (cats[n]||0)+1; 
            });
            const grid = document.getElementById('grid-category');
            grid.innerHTML = '';
            
            const icons = {
                'Games': 'https://cdn-icons-png.flaticon.com/512/686/686589.png',
                'Premium': 'https://cdn-icons-png.flaticon.com/512/3669/3669986.png',
                'PROMO 🔥': 'https://cdn-icons-png.flaticon.com/512/726/726476.png'
            };
            for(let c in cats) {
                let icn = icons[c] || appIcon;
                grid.innerHTML += `<div class="card-glass" onclick="openVariant('${c}')"><img src="${icn}" class="card-img"><div class="card-title">${c}</div><div class="card-sub">${cats[c]} Produk</div></div>`;
            }
        }
        
        function doSearch() {
            const q = document.getElementById('global-search').value.toLowerCase();
            const homeCat = document.getElementById('home-category-area');
            const searchRes = document.getElementById('search-results');

            if(q.length > 0) {
                homeCat.classList.add('hidden');
                searchRes.classList.remove('hidden');
                
                const grid = document.getElementById('grid-search'); grid.innerHTML = '';
                products.filter(p=>p.name.toLowerCase().includes(q)).forEach(p => {
                    grid.innerHTML += `<div class="card-glass" onclick='openDetail(${JSON.stringify(p)})'><img src="${appIcon}" class="card-img"><div class="card-title">${p.name}</div><div style="color:var(--neon-blue);">Rp ${parseInt(p.price).toLocaleString()}</div></div>`;
                });
            } else {
                homeCat.classList.remove('hidden');
                searchRes.classList.add('hidden');
            }
        }

        // --- VARIANT ---
        function openVariant(cat) {
            document.getElementById('variant-title').innerText = cat;
            const list = document.getElementById('list-variant'); list.innerHTML = '';
            
            products.filter(p => {
                let pCat = p.name.split(' ').slice(0,2).join(' ');
                if(p.type === 'promo') pCat = 'PROMO 🔥';
                return pCat === cat || p.name.includes(cat);
            }).forEach(p => {
                list.innerHTML += `<div class="list-glass" onclick='openDetail(${JSON.stringify(p)})'><div><div style="font-weight:bold; font-size:1.1rem;">${p.name}</div><div style="color:#aaa; font-size:0.9rem;">Stok Tersedia</div></div><div style="color:var(--neon-blue); font-weight:bold; font-size:1.2rem;">Rp ${parseInt(p.price).toLocaleString()}</div></div>`;
            });
            goPage('variant');
        }

        // --- DETAIL ---
        function openDetail(p) {
            state.detail = p; state.qty = 1;
            document.getElementById('det-name').innerText = p.name;
            document.getElementById('det-price').innerText = "Rp " + parseInt(p.price).toLocaleString();
            document.getElementById('det-img').src = appIcon;
            loadReviews(p.name);
            goPage('detail');
        }

        // --- REVIEWS ---
        function loadReviews(pName) {
            const key = 'rev_' + pName;
            const revs = JSON.parse(localStorage.getItem(key) || '[]');
            if(revs.length===0) revs.push({name:'Budi Santoso', text:'Proses kilat, mantap min!', img:null}, {name:'User123', text:'Harganya paling murah disini.', img:null});
            const list = document.getElementById('review-list'); list.innerHTML = '';
            revs.forEach(r => {
                list.innerHTML += `<div class="review-item"><div class="rev-avatar">${r.name.charAt(0)}</div><div><div style="font-weight:bold; font-size:0.9rem;">${r.name}</div><div style="color:#ccc; font-size:0.9rem; margin-top:2px;">${r.text}</div>${r.img ? `<img src="${r.img}" class="rev-img-post" onclick="Swal.fire({imageUrl:'${r.img}', showConfirmButton:false, background:'#222'})">` : ''}</div></div>`;
            });
        }
        function previewRevImg(input) {
            if(input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => { state.tempRevImg = e.target.result; document.getElementById('rev-preview').src = e.target.result; document.getElementById('rev-preview').style.display = 'block'; };
                reader.readAsDataURL(input.files[0]);
            }
        }
        function submitReview() {
            const txt = document.getElementById('rev-text').value; if(!txt) return;
            const key = 'rev_' + state.detail.name;
            const revs = JSON.parse(localStorage.getItem(key) || '[]');
            revs.unshift({ name: 'Saya', text: txt, img: state.tempRevImg });
            localStorage.setItem(key, JSON.stringify(revs));
            document.getElementById('rev-text').value = ''; 
            document.getElementById('rev-preview').style.display = 'none'; state.tempRevImg = null;
            loadReviews(state.detail.name);
            Swal.fire({toast:true, title:'Ulasan Terkirim', icon:'success', position:'top', showConfirmButton:false, timer:1500, background:'#222', color:'#fff'});
        }

        // --- CART ---
        function addToCart(redirect = false) {
            state.cart.push({...state.detail, qty: 1, checked: true});
            document.getElementById('cart-count').innerText = state.cart.length;
            if (redirect) {
                goPage('cart');
            } else {
                Swal.fire({toast:true, title:'Berhasil Masuk Keranjang', icon:'success', position:'top', showConfirmButton:false, timer:1500, background:'#222', color:'#fff'});
            }
        }
        
        function toggleCartItem(index) {
            state.cart[index].checked = !state.cart[index].checked;
            updateBottomBar();
        }

        function updateBottomBar() {
            const bar = document.getElementById('bottom-bar');
            const content = document.getElementById('bottom-bar-content');

            if(state.page === 'detail') {
                bar.style.display = 'flex';
                content.innerHTML = `
                    <div style="font-weight:bold; font-size:1.2rem; color:white;">${document.getElementById('det-price').innerText}</div>
                    <div style="display:flex; gap:10px;">
                        <button class="btn-action btn-outline" onclick="addToCart(false)">Tambah Keranjang</button>
                        <button class="btn-action" onclick="addToCart(true)">Beli Sekarang</button>
                    </div>
                `;
            } else if(state.page === 'cart') {
                renderCart(); bar.style.display = 'flex';
                // Filter hanya yang checked
                const checkedItems = state.cart.filter(item => item.checked);
                const total = checkedItems.reduce((sum, item) => sum + parseInt(item.price), 0);
                
                content.innerHTML = `
                    <div class="total-display">Total: Rp ${total.toLocaleString()}</div>
                    <button class="btn-action" onclick="goCheckoutCheck()">Checkout (${checkedItems.length})</button>
                `;
            } else if(state.page === 'checkout') {
                bar.style.display = 'flex';
                const checkedItems = state.cart.filter(item => item.checked);
                const total = checkedItems.reduce((sum, item) => sum + parseInt(item.price), 0);
                
                content.innerHTML = `
                    <div class="total-display">Bayar: Rp ${total.toLocaleString()}</div>
                    <button id="btn-main-action" class="btn-action" onclick="processOrder()">Buat Pesanan</button>
                `;
            } else {
                bar.style.display = 'none';
            }
        }
        
        function goCheckoutCheck() {
            const checkedItems = state.cart.filter(item => item.checked);
            if(checkedItems.length > 0) {
                goPage('checkout');
            } else {
                Swal.fire({title:'Pilih Item', text:'Silahkan centang barang yang ingin dibeli.', icon:'warning', background:'#222', color:'#fff'});
            }
        }

        function renderCart() {
            const c = document.getElementById('cart-container'); c.innerHTML = '';
            if(state.cart.length === 0) c.innerHTML = '<div style="text-align:center; padding:50px; color:#555;">Keranjang Kosong</div>';
            state.cart.forEach((item, i) => {
                const isChecked = item.checked ? 'checked' : '';
                c.innerHTML += `
                <div class="list-glass">
                    <div style="display:flex; align-items:center;">
                        <input type="checkbox" class="cart-checkbox" ${isChecked} onchange="toggleCartItem(${i})">
                        <div>
                            <div style="font-weight:bold;">${item.name}</div>
                            <div style="color:var(--neon-blue);">Rp ${parseInt(item.price).toLocaleString()}</div>
                        </div>
                    </div>
                    <div style="color:red; font-size:1.2rem; cursor:pointer;" onclick="state.cart.splice(${i},1); updateBottomBar();">
                        <i class="fas fa-trash"></i>
                    </div>
                </div>`;
            });
        }

        // --- CHECKOUT ---
        function selPay(el, method) {
            document.querySelectorAll('.pay-card').forEach(c=>c.classList.remove('active'));
            el.classList.add('active'); state.payment = method;
        }

        // --- CORE: ORDER & INVOICE (MODIFIED TO BYPASS DB ERRORS) ---
        function processOrder() {
            const name = document.getElementById('cx-name').value;
            const wa = document.getElementById('cx-wa').value;
            if(!name || !wa) return Swal.fire({title:'Isi Data!', text:'Nama & WA wajib diisi', icon:'error', background:'#222', color:'#fff'});
            
            // Hanya proses item yang dicentang
            const itemsToBuy = state.cart.filter(item => item.checked);
            const total = itemsToBuy.reduce((s,i)=>s+parseInt(i.price), 0);
            const itemsName = itemsToBuy.map(i=>i.name).join(', ');
            
            const btn = document.getElementById('btn-main-action');
            btn.innerText = "Memproses..."; btn.disabled = true;

            // KITA COBA REQUEST KE SERVER
            fetch('?api=create_order', {
                method: 'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ name, wa, items: itemsName, total, method: state.payment })
            })
            .then(r=>r.json()).then(res => {
                btn.disabled = false;
                if(res.status === 'ok') {
                    // Sukses DB Online
                    handleSuccess(res.order_id, res.invoice_ref, total);
                } else if (res.status === 'error' && res.fallback_ref) {
                    // DB Offline/Error tapi ada Fallback Ref
                    console.warn("DB Error, switching to Offline Mode");
                    handleSuccess(99999, res.fallback_ref, total);
                } else {
                    // Benar-benar error fatal
                     console.warn("Fatal Error");
                     const dummyRef = "AGMO-" + Math.floor(1000 + Math.random() * 9000);
                     handleSuccess(99999, dummyRef, total);
                }
            })
            .catch(err => {
                // JIKA REQUEST GAGAL (INTERNET MATI / SCRIPT ERROR), TETAP LANJUT (Bypass)
                console.error("Connection Failed, switching to Offline Mode");
                btn.disabled = false;
                const dummyRef = "AGMO-OFF-" + Math.floor(1000 + Math.random() * 9000);
                handleSuccess(99999, dummyRef, total);
            });
        }
        
        function handleSuccess(oid, ref, total) {
            playSfx('chime');
            state.currentInvoice = {
                id: oid, ref: ref, total: total, method: state.payment, date: new Date().toLocaleString()
            };
            finalizeOrder();
        }

        function finalizeOrder() {
            populateInvoicePage();
            goPage('invoice');
            
            // Hapus item yang sudah dibeli dari keranjang
            state.cart = state.cart.filter(item => !item.checked);
            document.getElementById('cart-count').innerText = state.cart.length;
            
            // Hanya polling jika ID-nya valid (bukan dummy)
            if(state.currentInvoice.id !== 99999) {
                startPollingStatus(state.currentInvoice.id);
            }
        }
        
        function populateInvoicePage() {
            if(!state.currentInvoice) return;
            document.getElementById('inv-ref-text').innerText = state.currentInvoice.ref;
            document.getElementById('inv-date').innerText = state.currentInvoice.date;
            document.getElementById('inv-total').innerText = "Rp " + (state.currentInvoice.total + 150).toLocaleString(); 
            
            let methodText = "QRIS / E-Wallet";
            if(state.currentInvoice.method == 'admin') methodText = "Transfer Admin";
            if(state.currentInvoice.method == 'bot') methodText = "Bayar via Bot";
            document.getElementById('inv-method').innerText = methodText;
            
            document.getElementById('inv-success-area').style.display = 'none';
            document.getElementById('inv-pending-area').style.display = 'block';
            
            // TIMER DIUBAH MENJADI 10 MENIT (10 * 60)
            startInvoiceTimer(10 * 60);
        }

        // --- POLLING STATUS ---
        function startPollingStatus(orderId) {
            if(state.pollingInterval) clearInterval(state.pollingInterval);
            
            state.pollingInterval = setInterval(() => {
                fetch('?api=check_status&id=' + orderId)
                .then(r => r.json())
                .then(data => {
                    if(data.status === 'success') {
                        clearInterval(state.pollingInterval);
                        showSuccessState(data.account_info);
                    }
                })
                .catch(err => console.log("Polling...", err));
            }, 3000);
        }

        function showSuccessState(accountInfo) {
            playSfx('chime');
            document.getElementById('inv-pending-area').style.display = 'none';
            document.getElementById('inv-success-area').style.display = 'block';
            document.getElementById('inv-account-data').innerText = accountInfo ? accountInfo : "Proses berhasil! Cek WA/Email.";
        }

        // --- REDIRECTS ---
        function app_doPaymentRedirect() {
            const method = state.currentInvoice.method;
            const ref = state.currentInvoice.ref;
            const total = state.currentInvoice.total;
            
            if(method === 'wallet') {
                // LINK DANA YANG ANDA MINTA
                window.location.href = 'https://link.dana.id/minta?full_url=https://qr.dana.id/v1/281012012022022699505763'; 
            } else if(method === 'admin') {
                const msg = `Halo Min, saya mau bayar invoice *${ref}* senilai Rp ${total}. Mohon diproses.`;
                window.open(`https://wa.me/6282363774494?text=${encodeURIComponent(msg)}`, '_blank');
            } else {
                const msg = `.bayar ${ref}`;
                window.open(`https://wa.me/6281226415518?text=${encodeURIComponent(msg)}`, '_blank');
            }
        };
        window.app_doPaymentRedirect = app_doPaymentRedirect;

        // --- TIMER & HISTORY ---
        function startInvoiceTimer(duration) {
            let timer = duration, m, s;
            if(state.timerInterval) clearInterval(state.timerInterval);
            state.timerInterval = setInterval(function () {
                m = parseInt(timer / 60, 10); s = parseInt(timer % 60, 10);
                m = m < 10 ? "0" + m : m; s = s < 10 ? "0" + s : s;
                document.getElementById('inv-timer').innerText = m + ":" + s;
                if (--timer < 0) { clearInterval(state.timerInterval); document.getElementById('inv-timer').innerText = "EXPIRED"; }
            }, 1000);
        }
        
        function copyRef() { navigator.clipboard.writeText(document.getElementById('inv-ref-text').innerText); Swal.fire({toast:true, title:'Disalin', icon:'success', position:'top', showConfirmButton:false, timer:1000, background:'#222', color:'#fff'}); }
        function copyDataAccount() { navigator.clipboard.writeText(document.getElementById('inv-account-data').innerText); Swal.fire({toast:true, title:'Data Disalin', icon:'success', position:'top', showConfirmButton:false, timer:1000, background:'#222', color:'#fff'}); }

        function openHistory() {
            document.getElementById('history-modal').classList.remove('hidden');
            const c = document.getElementById('history-content');
            c.innerHTML = '<div style="padding:20px; text-align:center;">Loading...</div>';
            
            fetch('?api=check_history').then(r=>r.json()).then(data => {
                c.innerHTML = '';
                if(data.length === 0) c.innerHTML = '<div style="padding:20px; text-align:center; color:#777;">Belum ada pesanan.</div>';
                data.forEach(o => {
                    const color = o.status==='success'?'#00ff88':(o.status==='pending'?'orange':'red');
                    c.innerHTML += `<div style="background:rgba(255,255,255,0.05); padding:15px; border-radius:15px; margin-bottom:10px; border-left:4px solid ${color};"><div style="display:flex; justify-content:space-between;"><b style="color:${color}; text-transform:uppercase;">${o.status}</b><small>${o.formatted_date}</small></div><div style="font-weight:bold; margin:5px 0;">${o.items}</div><div style="display:flex; justify-content:space-between; align-items:center;"><span>Rp ${parseInt(o.total_price).toLocaleString()}</span>${o.account_info ? `<span style="background:#333; padding:2px 8px; border-radius:5px; font-size:0.8rem; cursor:pointer;" onclick="navigator.clipboard.writeText('${o.account_info}'); Swal.fire({toast:true,title:'Disalin',icon:'success',background:'#222',color:'#fff'})"><i class="fas fa-copy"></i> Data</span>` : ''}</div></div>`;
                });
            });
        }
        function closeHistory() { document.getElementById('history-modal').classList.add('hidden'); }

        renderHome();
    </script>
</body>
</html>