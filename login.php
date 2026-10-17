<?php
// PERBAIKAN: Cek session sudah dimulai atau belum
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// PERBAIKAN: Include config dan auth yang benar
require_once 'config/config.php';
require_once 'includes/auth.php';

// Jika sudah login, redirect ke dashboard sesuai role
if (isLoggedIn()) {
    redirectBasedOnRole();
}

// Ambil data koperasi dari database untuk logo dan nama
$koperasi = [];
try {
    require_once 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    $koperasi_query = "SELECT * FROM koperasi WHERE id = 1";
    $koperasi_stmt = $db->prepare($koperasi_query);
    $koperasi_stmt->execute();
    $koperasi = $koperasi_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$koperasi) {
        $koperasi = [
            'nama' => APP_NAME,
            'logo' => null,
            'alamat' => '',
            'telepon' => '',
            'email' => ''
        ];
    }
} catch (Exception $e) {
    error_log("Error mengambil data koperasi: " . $e->getMessage());
    $koperasi = [
        'nama' => APP_NAME,
        'logo' => null,
        'alamat' => '',
        'telepon' => '',
        'email' => ''
    ];
}

// Tentukan path logo yang benar
$logo_path = '';
if (!empty($koperasi['logo'])) {
    // Coba beberapa kemungkinan path
    $possible_paths = [
        'assets/uploads/logo/' . $koperasi['logo'],
        'uploads/logo/' . $koperasi['logo'],
        '../assets/uploads/logo/' . $koperasi['logo'],
        'admin/assets/uploads/logo/' . $koperasi['logo'],
        'admin/uploads/logo/' . $koperasi['logo']
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            $logo_path = $path;
            break;
        }
    }
}

$error = '';
$success = '';
$selected_role = $_GET['role'] ?? 'admin';

// Cek apakah ada error dari session (dari login_process.php)
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Login - <?php echo htmlspecialchars($koperasi['nama']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        .login-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            overflow: hidden;
            width: 100%;
            max-width: 450px;
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 2rem 1.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        @media (max-width: 576px) {
            .login-header {
                padding: 1.5rem 1rem;
            }
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 20px 20px;
            animation: float 20s linear infinite;
        }
        
        @keyframes float {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .logo-container {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.8rem;
            border: 3px solid rgba(255, 255, 255, 0.3);
            position: relative;
            z-index: 2;
            overflow: hidden;
        }
        
        @media (max-width: 576px) {
            .logo-container {
                width: 70px;
                height: 70px;
            }
        }
        
        .logo-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .logo-container i {
            font-size: 2.5rem;
            color: white;
        }
        
        @media (max-width: 576px) {
            .logo-container i {
                font-size: 2rem;
            }
        }
        
        .koperasi-name {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
            position: relative;
            z-index: 2;
            word-break: break-word;
            padding: 0 10px;
        }
        
        @media (max-width: 576px) {
            .koperasi-name {
                font-size: 1.1rem;
            }
        }
        
        .sub-title {
            font-size: 0.9rem;
            opacity: 0.8;
            position: relative;
            z-index: 2;
            margin-bottom: 1rem;
        }
        
        .role-selector {
            display: flex;
            background: rgba(255,255,255,0.2);
            border-radius: 50px;
            padding: 4px;
            margin: 1rem 0 0;
            position: relative;
            z-index: 2;
            backdrop-filter: blur(5px);
        }
        
        .role-option {
            flex: 1;
            text-align: center;
            padding: 0.7rem 0.5rem;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 0.9rem;
            color: rgba(255,255,255,0.8);
            border: none;
            background: transparent;
            -webkit-tap-highlight-color: transparent;
        }
        
        @media (max-width: 576px) {
            .role-option {
                padding: 0.6rem 0.3rem;
                font-size: 0.8rem;
            }
        }
        
        .role-option i {
            margin-right: 5px;
            font-size: 0.9rem;
        }
        
        @media (max-width: 576px) {
            .role-option i {
                margin-right: 3px;
                font-size: 0.8rem;
            }
        }
        
        .role-option.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .role-option.admin.active { color: var(--primary); }
        .role-option.teller.active { color: var(--warning); }
        .role-option.anggota.active { color: var(--success); }
        
        .role-option:active {
            transform: scale(0.95);
        }
        
        .login-body {
            padding: 2rem 2rem;
        }
        
        @media (max-width: 576px) {
            .login-body {
                padding: 1.5rem 1.2rem;
            }
        }
        
        .form-label {
            font-weight: 600;
            font-size: 0.9rem;
            color: #4a5568;
            margin-bottom: 0.4rem;
        }
        
        .input-group {
            position: relative;
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-right: none;
            border-radius: 12px 0 0 12px;
            color: #95a5a6;
            padding: 0.7rem 1rem;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-left: none;
            border-radius: 0 12px 12px 0;
            padding: 0.7rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
            height: auto;
        }
        
        @media (max-width: 576px) {
            .form-control {
                padding: 0.6rem 0.8rem;
                font-size: 0.9rem;
            }
            
            .input-group-text {
                padding: 0.6rem 0.8rem;
            }
        }
        
        .form-control:focus {
            border-color: var(--secondary);
            background: white;
            box-shadow: none;
            outline: none;
        }
        
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #95a5a6;
            cursor: pointer;
            z-index: 10;
            padding: 0.5rem;
            font-size: 1rem;
            transition: color 0.3s ease;
            -webkit-tap-highlight-color: transparent;
        }
        
        .password-toggle:active {
            color: var(--primary);
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
            font-size: 1rem;
            margin: 1rem 0 0.5rem;
            color: white;
        }
        
        @media (max-width: 576px) {
            .btn-login {
                padding: 0.7rem 1.2rem;
                font-size: 0.95rem;
            }
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        /* Role-specific styles */
        .role-admin .btn-login { background: linear-gradient(135deg, #2c3e50, #2980b9); }
        .role-teller .btn-login { background: linear-gradient(135deg, #f39c12, #e67e22); }
        .role-anggota .btn-login { background: linear-gradient(135deg, #27ae60, #229954); }
        
        .role-admin .login-header { background: linear-gradient(135deg, #2c3e50, #2980b9); }
        .role-teller .login-header { background: linear-gradient(135deg, #f39c12, #e67e22); }
        .role-anggota .login-header { background: linear-gradient(135deg, #27ae60, #229954); }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 0.8rem 1rem;
            margin-bottom: 1.2rem;
            font-size: 0.9rem;
            animation: shake 0.3s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-3px); }
            40%, 80% { transform: translateX(3px); }
        }
        
        @media (max-width: 576px) {
            .alert {
                padding: 0.7rem 0.8rem;
                font-size: 0.85rem;
            }
        }
        
        .alert-danger {
            background: #fee;
            color: #c0392b;
            border-left: 4px solid #e74c3c;
        }
        
        .alert-success {
            background: #e8f8f5;
            color: #27ae60;
            border-left: 4px solid #2ecc71;
        }
        
        .support-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 0.8rem;
            margin-top: 1rem;
            text-align: center;
            font-size: 0.85rem;
            color: #6c757d;
            border: 1px solid #e9ecef;
        }
        
        .back-link {
            color: var(--primary);
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            background: #f8f9fa;
            font-size: 0.9rem;
        }
        
        @media (max-width: 576px) {
            .back-link {
                font-size: 0.85rem;
                padding: 0.3rem 0.8rem;
            }
        }
        
        .back-link:hover {
            background: var(--primary);
            color: white;
            transform: translateX(-3px);
        }
        
        .back-link i {
            transition: transform 0.3s ease;
        }
        
        .back-link:hover i {
            transform: translateX(-3px);
        }
        
        /* Prevent zoom on input focus for iOS */
        @media screen and (-webkit-min-device-pixel-ratio:0) { 
            select:focus,
            textarea:focus,
            input:focus {
                font-size: 16px;
            }
        }
        
        /* Touch-friendly */
        .role-option,
        .btn-login,
        .back-link,
        .password-toggle {
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        
        .btn-close {
            font-size: 0.8rem;
        }
    </style>
</head>
<body class="role-<?php echo $selected_role; ?>">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-container">
                    <?php if (!empty($logo_path)): ?>
                        <img src="<?php echo htmlspecialchars($logo_path); ?>" 
                             alt="Logo <?php echo htmlspecialchars($koperasi['nama']); ?>"
                             onerror="this.onerror=null; this.parentNode.innerHTML='<i class=\'fas fa-hand-holding-usd\'></i>';">
                    <?php else: ?>
                        <i class="fas fa-hand-holding-usd"></i>
                    <?php endif; ?>
                </div>
                <div class="koperasi-name">
                    <?php echo htmlspecialchars($koperasi['nama']); ?>
                </div>
                <div class="sub-title">
                    <i class="fas fa-lock me-1"></i> Masuk ke Sistem
                </div>
                
                <!-- Role Selector -->
                <div class="role-selector">
                    <div class="role-option admin <?php echo $selected_role == 'admin' ? 'active' : ''; ?>" 
                         onclick="selectRole('admin')">
                        <i class="fas fa-crown"></i> 
                        <span class="d-none d-sm-inline">Admin</span>
                        <span class="d-sm-none">Admin</span>
                    </div>
                    <div class="role-option teller <?php echo $selected_role == 'teller' ? 'active' : ''; ?>" 
                         onclick="selectRole('teller')">
                        <i class="fas fa-user-tie"></i> 
                        <span class="d-none d-sm-inline">Teller</span>
                        <span class="d-sm-none">Teller</span>
                    </div>
                    <div class="role-option anggota <?php echo $selected_role == 'anggota' ? 'active' : ''; ?>" 
                         onclick="selectRole('anggota')">
                        <i class="fas fa-user"></i> 
                        <span class="d-none d-sm-inline">Anggota</span>
                        <span class="d-sm-none">Anggota</span>
                    </div>
                </div>
            </div>

            <div class="login-body">
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="login_process.php" id="loginForm">
                    <input type="hidden" name="role" id="selectedRole" value="<?php echo $selected_role; ?>">
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            <i class="fas fa-user me-2"></i>Username
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-user"></i>
                            </span>
                            <input type="text" class="form-control" id="username" name="username" 
                                   placeholder="Masukkan username" 
                                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                                   required autofocus>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock me-2"></i>Password
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="Masukkan password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword()" tabindex="-1">
                                <i class="fas fa-eye" id="passwordIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" name="login" class="btn btn-login text-white" id="loginButton">
                        <i class="fas fa-sign-in-alt me-2"></i>
                        <span id="loginText">Masuk sebagai Admin</span>
                    </button>
                </form>
                
                <div class="support-info">
                    <i class="fas fa-headset me-2"></i>
                    Butuh bantuan? Hubungi administrator sistem
                    <br>
                    <small class="text-muted">
                        <i class="fas fa-clock me-1"></i> Support 24/7
                    </small>
                </div>

                <div class="text-center mt-3">
                    <a href="index.php" class="back-link">
                        <i class="fas fa-arrow-left me-2"></i>Kembali ke Beranda
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectRole(role) {
            document.getElementById('selectedRole').value = role;
            document.body.className = 'role-' + role;
            
            // Update active class
            document.querySelectorAll('.role-option').forEach(option => {
                option.classList.remove('active');
            });
            document.querySelector(`.role-option.${role}`).classList.add('active');
            
            // Update login button text
            const roleNames = {
                'admin': 'Admin',
                'teller': 'Teller', 
                'anggota': 'Anggota'
            };
            document.getElementById('loginText').textContent = `Masuk sebagai ${roleNames[role]}`;
            
            // Update placeholder untuk anggota
            const usernameInput = document.getElementById('username');
            if (role === 'anggota') {
                usernameInput.placeholder = 'Masukkan kode anggota';
            } else {
                usernameInput.placeholder = 'Masukkan username';
            }
        }
        
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const passwordIcon = document.getElementById('passwordIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordIcon.classList.remove('fa-eye');
                passwordIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordIcon.classList.remove('fa-eye-slash');
                passwordIcon.classList.add('fa-eye');
            }
        }
        
        // Set initial role text and style
        document.addEventListener('DOMContentLoaded', function() {
            const role = '<?php echo $selected_role; ?>';
            const roleNames = {
                'admin': 'Admin',
                'teller': 'Teller',
                'anggota': 'Anggota'
            };
            document.getElementById('loginText').textContent = `Masuk sebagai ${roleNames[role]}`;
            document.body.className = 'role-' + role;
            
            // Set placeholder untuk anggota
            const usernameInput = document.getElementById('username');
            if (role === 'anggota') {
                usernameInput.placeholder = 'Masukkan kode anggota';
            }
            
            // Prevent double submit
            document.getElementById('loginForm').addEventListener('submit', function(e) {
                const button = document.getElementById('loginButton');
                if (button.disabled) {
                    e.preventDefault();
                    return;
                }
                button.disabled = true;
                
                // Tambahkan loading state
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
            });
        });
    </script>
</body>
</html>