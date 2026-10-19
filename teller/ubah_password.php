<?php
require_once '../includes/auth.php';
require_once '../config/config.php';

$page_title = "Ubah Password";

// Initialize variables
$error = '';
$success = '';

// Hanya teller; mengubah password milik sendiri saja ($_SESSION['user_id'])
if (!isTeller()) {
    header('Location: ../dashboard.php');
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        ubahPasswordPengguna(
            $db,
            $_SESSION['user_id'],
            isset($_POST['current_password']) ? $_POST['current_password'] : '',
            isset($_POST['new_password']) ? $_POST['new_password'] : '',
            isset($_POST['confirm_password']) ? $_POST['confirm_password'] : ''
        );
        $success = "Password berhasil diubah! Gunakan password baru saat login berikutnya.";
        $_POST = array();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .password-strength {
            height: 5px;
            border-radius: 5px;
            margin-top: 5px;
            transition: all 0.3s ease;
        }
        
        .strength-weak { background-color: #e74c3c; width: 25%; }
        .strength-fair { background-color: #f39c12; width: 50%; }
        .strength-good { background-color: #3498db; width: 75%; }
        .strength-strong { background-color: #27ae60; width: 100%; }
        
        .password-criteria {
            font-size: 0.85rem;
        }
        
        .criteria-met {
            color: #27ae60;
        }
        
        .criteria-not-met {
            color: #7f8c8d;
        }
        
        .security-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #3498db, #2c3e50);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 2rem;
        }
    </style>
</head>
<body style="background-color: #f8f9fa;">
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1"><i class="fas fa-lock me-2 text-primary"></i><?php echo $page_title; ?></h1>
                        <p class="text-muted mb-0">Kelola keamanan akun Anda dengan mengubah password secara berkala</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="profil.php" class="btn btn-outline-primary">
                            <i class="fas fa-user me-2"></i>Kembali ke Profil
                        </a>
                    </div>
                </div>

                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-shield-alt me-2 text-primary"></i>
                                    Keamanan Akun
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- Security Info -->
                                <div class="row align-items-center mb-4">
                                    <div class="col-md-2 text-center">
                                        <div class="security-icon">
                                            <i class="fas fa-user-shield"></i>
                                        </div>
                                    </div>
                                    <div class="col-md-10">
                                        <h5 class="text-primary">Tips Keamanan Password</h5>
                                        <p class="text-muted mb-0">
                                            Gunakan password yang kuat dengan kombinasi huruf, angka, dan simbol. 
                                            Jangan gunakan password yang sama dengan akun lainnya dan ubah password secara berkala.
                                        </p>
                                    </div>
                                </div>

                                <?php if ($error): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <?php echo $error; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($success): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <?php echo $success; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                                <?php endif; ?>

                                <!-- Change Password Form -->
                                <form method="POST" id="changePasswordForm"><?php echo layananToken(); ?>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <!-- Current Password -->
                                            <div class="mb-4">
                                                <label class="form-label fw-semibold">Password Saat Ini</label>
                                                <div class="input-group">
                                                    <input type="password" class="form-control" name="current_password" 
                                                           id="current_password" placeholder="Masukkan password saat ini" required>
                                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('current_password')">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- New Password -->
                                            <div class="mb-4">
                                                <label class="form-label fw-semibold">Password Baru</label>
                                                <div class="input-group">
                                                    <input type="password" class="form-control" name="new_password" 
                                                           id="new_password" placeholder="Masukkan password baru" required
                                                           onkeyup="checkPasswordStrength()">
                                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('new_password')">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                                <div class="password-strength" id="passwordStrength"></div>
                                            </div>

                                            <!-- Confirm Password -->
                                            <div class="mb-4">
                                                <label class="form-label fw-semibold">Konfirmasi Password Baru</label>
                                                <div class="input-group">
                                                    <input type="password" class="form-control" name="confirm_password" 
                                                           id="confirm_password" placeholder="Konfirmasi password baru" required
                                                           onkeyup="checkPasswordMatch()">
                                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('confirm_password')">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                                <div class="mt-1">
                                                    <small id="passwordMatch" class="text-muted"></small>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <!-- Password Criteria -->
                                            <div class="card bg-light">
                                                <div class="card-body">
                                                    <h6 class="card-title mb-3">
                                                        <i class="fas fa-list-check me-2 text-primary"></i>
                                                        Kriteria Password yang Baik
                                                    </h6>
                                                    <div class="password-criteria">
                                                        <div class="d-flex align-items-center mb-2" id="criteriaLength">
                                                            <i class="fas fa-circle me-2 criteria-not-met"></i>
                                                            <span>Minimal 8 karakter</span>
                                                        </div>
                                                        <div class="d-flex align-items-center mb-2" id="criteriaUppercase">
                                                            <i class="fas fa-circle me-2 criteria-not-met"></i>
                                                            <span>Mengandung huruf besar</span>
                                                        </div>
                                                        <div class="d-flex align-items-center mb-2" id="criteriaLowercase">
                                                            <i class="fas fa-circle me-2 criteria-not-met"></i>
                                                            <span>Mengandung huruf kecil</span>
                                                        </div>
                                                        <div class="d-flex align-items-center mb-2" id="criteriaNumber">
                                                            <i class="fas fa-circle me-2 criteria-not-met"></i>
                                                            <span>Mengandung angka</span>
                                                        </div>
                                                        <div class="d-flex align-items-center" id="criteriaSpecial">
                                                            <i class="fas fa-circle me-2 criteria-not-met"></i>
                                                            <span>Mengandung simbol</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Security Tips -->
                                            <div class="mt-4">
                                                <h6 class="fw-semibold mb-3">
                                                    <i class="fas fa-lightbulb me-2 text-warning"></i>
                                                    Tips Keamanan
                                                </h6>
                                                <ul class="list-unstyled text-muted small">
                                                    <li class="mb-2">
                                                        <i class="fas fa-check text-success me-2"></i>
                                                        Jangan gunakan informasi pribadi
                                                    </li>
                                                    <li class="mb-2">
                                                        <i class="fas fa-check text-success me-2"></i>
                                                        Gunkan kombinasi yang unik
                                                    </li>
                                                    <li class="mb-2">
                                                        <i class="fas fa-check text-success me-2"></i>
                                                        Ubah password setiap 3 bulan
                                                    </li>
                                                    <li>
                                                        <i class="fas fa-check text-success me-2"></i>
                                                        Jangan bagikan password ke siapapun
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Action Buttons -->
                                    <div class="row mt-4">
                                        <div class="col-12">
                                            <div class="d-flex justify-content-end gap-2">
                                                <button type="reset" class="btn btn-outline-secondary">
                                                    <i class="fas fa-undo me-2"></i>Reset
                                                </button>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-save me-2"></i>Simpan Password Baru
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Last Password Change -->
                        <div class="card mt-4">
                            <div class="card-header bg-white">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-history me-2 text-muted"></i>
                                    Riwayat Keamanan
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-4 mb-3">
                                        <div class="border rounded p-3">
                                            <div class="text-primary fw-bold fs-5"><?php echo date('d M Y'); ?></div>
                                            <small class="text-muted">Login Terakhir</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="border rounded p-3">
                                            <div class="text-success fw-bold fs-5">-</div>
                                            <small class="text-muted">Password Diubah</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <div class="border rounded p-3">
                                            <div class="text-info fw-bold fs-5">Aktif</div>
                                            <small class="text-muted">Status Akun</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = field.nextElementSibling.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthBar = document.getElementById('passwordStrength');
            const criteria = {
                length: document.getElementById('criteriaLength'),
                uppercase: document.getElementById('criteriaLength'),
                lowercase: document.getElementById('criteriaLowercase'),
                number: document.getElementById('criteriaNumber'),
                special: document.getElementById('criteriaSpecial')
            };
            
            let strength = 0;
            let messages = [];
            
            // Check length
            if (password.length >= 8) {
                strength += 1;
                criteria.length.querySelector('i').className = 'fas fa-check-circle me-2 criteria-met';
            } else {
                criteria.length.querySelector('i').className = 'fas fa-circle me-2 criteria-not-met';
            }
            
            // Check uppercase
            if (/[A-Z]/.test(password)) {
                strength += 1;
                criteria.uppercase.querySelector('i').className = 'fas fa-check-circle me-2 criteria-met';
            } else {
                criteria.uppercase.querySelector('i').className = 'fas fa-circle me-2 criteria-not-met';
            }
            
            // Check lowercase
            if (/[a-z]/.test(password)) {
                strength += 1;
                criteria.lowercase.querySelector('i').className = 'fas fa-check-circle me-2 criteria-met';
            } else {
                criteria.lowercase.querySelector('i').className = 'fas fa-circle me-2 criteria-not-met';
            }
            
            // Check numbers
            if (/[0-9]/.test(password)) {
                strength += 1;
                criteria.number.querySelector('i').className = 'fas fa-check-circle me-2 criteria-met';
            } else {
                criteria.number.querySelector('i').className = 'fas fa-circle me-2 criteria-not-met';
            }
            
            // Check special characters
            if (/[^A-Za-z0-9]/.test(password)) {
                strength += 1;
                criteria.special.querySelector('i').className = 'fas fa-check-circle me-2 criteria-met';
            } else {
                criteria.special.querySelector('i').className = 'fas fa-circle me-2 criteria-not-met';
            }
            
            // Update strength bar
            strengthBar.className = 'password-strength ';
            if (strength <= 1) {
                strengthBar.className += 'strength-weak';
            } else if (strength <= 2) {
                strengthBar.className += 'strength-fair';
            } else if (strength <= 3) {
                strengthBar.className += 'strength-good';
            } else {
                strengthBar.className += 'strength-strong';
            }
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('passwordMatch');
            
            if (confirmPassword === '') {
                matchText.textContent = '';
                matchText.className = 'text-muted';
            } else if (password === confirmPassword) {
                matchText.textContent = 'Password cocok ✓';
                matchText.className = 'text-success';
            } else {
                matchText.textContent = 'Password tidak cocok ✗';
                matchText.className = 'text-danger';
            }
        }
        
        // Form validation
        document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Password baru dan konfirmasi password tidak cocok!');
                return false;
            }
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Password baru harus minimal 8 karakter!');
                return false;
            }
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            checkPasswordStrength();
            checkPasswordMatch();
        });
    </script>
</body>
</html>