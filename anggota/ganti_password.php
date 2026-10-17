<?php
require_once '../includes/auth.php';
checkAnggota();

$anggota_id = $_SESSION['user_id'];
$errors = [];
$success = '';

// Handle password change
if ($_POST && isset($_POST['ganti_password'])) {
    $password_lama = $_POST['password_lama'] ?? '';
    $password_baru = $_POST['password_baru'] ?? '';
    $konfirmasi_password = $_POST['konfirmasi_password'] ?? '';
    
    // Validation
    if (empty($password_lama) || empty($password_baru) || empty($konfirmasi_password)) {
        $errors[] = "Semua field harus diisi";
    } elseif ($password_baru !== $konfirmasi_password) {
        $errors[] = "Konfirmasi password tidak sesuai";
    } elseif (strlen($password_baru) < 6) {
        $errors[] = "Password baru minimal 6 karakter";
    } else {
        try {
            // Verify current password
            $query_user = "SELECT password FROM users WHERE id = :user_id";
            $stmt_user = $db->prepare($query_user);
            $stmt_user->bindParam(':user_id', $anggota_id);
            $stmt_user->execute();
            $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
            
            if ($user_data && password_verify($password_lama, $user_data['password'])) {
                // Update password
                $hashed_password = password_hash($password_baru, PASSWORD_DEFAULT);
                $query_update = "UPDATE users SET password = :password WHERE id = :user_id";
                $stmt_update = $db->prepare($query_update);
                $stmt_update->bindParam(':password', $hashed_password);
                $stmt_update->bindParam(':user_id', $anggota_id);
                
                if ($stmt_update->execute()) {
                    $success = "Password berhasil diubah";
                } else {
                    $errors[] = "Gagal mengubah password";
                }
            } else {
                $errors[] = "Password lama tidak sesuai";
            }
        } catch (Exception $e) {
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ganti Password - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .main-content {
            margin-left: 280px;
            margin-top: 10px; /* DIKURANGI DARI 70px MENJADI 10px */
            padding: 15px;
        }
        .page-header {
            padding-top: 5px;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .password-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .password-strength {
            height: 5px;
            margin-top: 5px;
            border-radius: 3px;
        }
        .strength-weak { background-color: #dc3545; width: 25%; }
        .strength-medium { background-color: #ffc107; width: 50%; }
        .strength-strong { background-color: #28a745; width: 75%; }
        .strength-very-strong { background-color: #20c997; width: 100%; }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 60px;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center page-header border-bottom">
                    <h1 class="h3">
                        <i class="fas fa-key me-2"></i>Ganti Password
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="profil.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-arrow-left me-1"></i> Kembali ke Profil
                        </a>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($errors)): ?>
                    <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-lg-6">
                        <div class="card password-card">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-lock me-2"></i>Form Ganti Password
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <form method="POST" id="passwordForm">
                                    <div class="mb-3">
                                        <label class="form-label small">Password Lama</label>
                                        <div class="input-group input-group-sm">
                                            <input type="password" name="password_lama" class="form-control" required 
                                                   placeholder="Masukkan password lama" id="passwordLama">
                                            <button type="button" class="btn btn-outline-secondary toggle-password" 
                                                    data-target="passwordLama">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label small">Password Baru</label>
                                        <div class="input-group input-group-sm">
                                            <input type="password" name="password_baru" class="form-control" required 
                                                   placeholder="Masukkan password baru (minimal 6 karakter)" 
                                                   id="passwordBaru" minlength="6">
                                            <button type="button" class="btn btn-outline-secondary toggle-password" 
                                                    data-target="passwordBaru">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="password-strength mt-1" id="passwordStrength"></div>
                                        <div class="form-text">
                                            <small>Password harus minimal 6 karakter</small>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label small">Konfirmasi Password Baru</label>
                                        <div class="input-group input-group-sm">
                                            <input type="password" name="konfirmasi_password" class="form-control" required 
                                                   placeholder="Konfirmasi password baru" id="konfirmasiPassword">
                                            <button type="button" class="btn btn-outline-secondary toggle-password" 
                                                    data-target="konfirmasiPassword">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">
                                            <small id="passwordMatch" class="text-muted"></small>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info py-2">
                                        <small>
                                            <i class="fas fa-info-circle me-1"></i>
                                            Pastikan password baru Anda kuat dan mudah diingat.
                                        </small>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="ganti_password" class="btn btn-warning btn-sm">
                                            <i class="fas fa-save me-1"></i> Ganti Password
                                        </button>
                                        <a href="profil.php" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-times me-1"></i> Batal
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Password Tips -->
                        <div class="card password-card mt-3">
                            <div class="card-header py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-lightbulb me-2"></i>Tips Password Aman
                                </h6>
                            </div>
                            <div class="card-body p-3">
                                <ul class="list-unstyled small mb-0">
                                    <li class="mb-1">
                                        <i class="fas fa-check text-success me-2"></i>
                                        Gunakan kombinasi huruf besar, kecil, angka, dan simbol
                                    </li>
                                    <li class="mb-1">
                                        <i class="fas fa-check text-success me-2"></i>
                                        Minimal 8 karakter untuk keamanan optimal
                                    </li>
                                    <li class="mb-1">
                                        <i class="fas fa-check text-success me-2"></i>
                                        Hindari menggunakan informasi pribadi yang mudah ditebak
                                    </li>
                                    <li class="mb-1">
                                        <i class="fas fa-check text-success me-2"></i>
                                        Jangan gunakan password yang sama untuk banyak akun
                                    </li>
                                    <li class="mb-0">
                                        <i class="fas fa-check text-success me-2"></i>
                                        Ganti password secara berkala
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const passwordInput = document.getElementById(targetId);
                const icon = this.querySelector('i');
                
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    passwordInput.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });

        // Password strength indicator
        document.getElementById('passwordBaru').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('passwordStrength');
            const strength = checkPasswordStrength(password);
            
            strengthBar.className = 'password-strength';
            if (password.length > 0) {
                strengthBar.classList.add('strength-' + strength.level);
            }
        });

        // Password confirmation check
        document.getElementById('konfirmasiPassword').addEventListener('input', function() {
            const password = document.getElementById('passwordBaru').value;
            const confirmPassword = this.value;
            const matchText = document.getElementById('passwordMatch');
            
            if (confirmPassword.length === 0) {
                matchText.textContent = '';
                matchText.className = 'form-text text-muted';
            } else if (password === confirmPassword) {
                matchText.textContent = '✓ Password cocok';
                matchText.className = 'form-text text-success';
            } else {
                matchText.textContent = '✗ Password tidak cocok';
                matchText.className = 'form-text text-danger';
            }
        });

        // Form validation
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const passwordBaru = document.getElementById('passwordBaru').value;
            const konfirmasiPassword = document.getElementById('konfirmasiPassword').value;
            
            if (passwordBaru.length < 6) {
                e.preventDefault();
                alert('Password baru harus minimal 6 karakter!');
                return false;
            }
            
            if (passwordBaru !== konfirmasiPassword) {
                e.preventDefault();
                alert('Konfirmasi password tidak sesuai!');
                return false;
            }
            
            return true;
        });

        // Password strength checker
        function checkPasswordStrength(password) {
            let score = 0;
            
            if (!password) {
                return { level: 'none', score: 0 };
            }
            
            // Length check
            if (password.length >= 6) score += 1;
            if (password.length >= 8) score += 1;
            if (password.length >= 12) score += 1;
            
            // Character variety
            if (/[a-z]/.test(password)) score += 1; // lowercase
            if (/[A-Z]/.test(password)) score += 1; // uppercase
            if (/[0-9]/.test(password)) score += 1; // numbers
            if (/[^a-zA-Z0-9]/.test(password)) score += 1; // special characters
            
            // Determine strength level
            if (score <= 2) return { level: 'weak', score: score };
            if (score <= 4) return { level: 'medium', score: score };
            if (score <= 6) return { level: 'strong', score: score };
            return { level: 'very-strong', score: score };
        }

        // Real-time validation
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('input[type="password"]');
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    if (this.value.length > 0) {
                        this.classList.remove('is-invalid');
                    }
                });
            });
        });
    </script>
</body>
</html>