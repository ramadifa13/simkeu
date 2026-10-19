<?php
// Mulai session jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'config/database.php';

// Fungsi untuk redirect berdasarkan role
function redirectBasedOnRole() {
    if (isset($_SESSION['role'])) {
        $role = $_SESSION['role'];
        switch ($role) {
            case 'admin':
                header("Location: admin/dashboard.php");
                break;
            case 'teller':
                header("Location: teller/dashboard.php");
                break;
            case 'anggota':
                header("Location: anggota/dashboard.php");
                break;
            default:
                header("Location: index.php");
        }
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = is_string($_POST['username'] ?? null)?trim($_POST['username']):'';
    $password = is_string($_POST['password'] ?? null)?$_POST['password']:'';
    $role = is_string($_POST['role'] ?? null)?$_POST['role']:'admin';
    if(!in_array($role,['admin','teller','anggota'],true))$role='admin';
    $loginToken=$_POST['login_csrf'] ?? '';
    if(!is_string($loginToken) || empty($_SESSION['login_csrf']) || !hash_equals($_SESSION['login_csrf'],$loginToken)) {
        $_SESSION['error']='Formulir login kedaluwarsa. Silakan coba kembali.';
        header('Location: login.php?role='.$role);exit;
    }
    
    // Validasi input
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Username dan password harus diisi!";
        header("Location: login.php?role=" . $role);
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Cari user berdasarkan username dan role
        $query = "SELECT * FROM users WHERE username = :username AND role = :role AND status = 'aktif'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':role', $role);
        $stmt->execute();
        
        if ($stmt->rowCount() == 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verify password dengan password_verify
            if (password_verify($password, $user['password'])) {
                // Set session
                session_regenerate_id(true);
                unset($_SESSION['login_csrf'],$_SESSION['layanan_csrf']);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                $_SESSION['role'] = $user['role'];
                
                // Update last login
                $update_query = "UPDATE users SET last_login = NOW() WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':id', $user['id']);
                $update_stmt->execute();
                
                // Redirect ke dashboard berdasarkan role
                redirectBasedOnRole();
                
            } else {
                $_SESSION['error'] = "Username, password, atau peran tidak sesuai.";
                header("Location: login.php?role=" . $role);
                exit();
            }
        } else {
            $_SESSION['error'] = "Username, password, atau peran tidak sesuai.";
            header("Location: login.php?role=" . $role);
            exit();
        }
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Terjadi kesalahan sistem. Silakan coba lagi.";
        error_log("Login error: " . $e->getMessage());
        header("Location: login.php?role=" . $role);
        exit();
    }
} else {
    $_SESSION['error'] = "Metode request tidak valid!";
    header("Location: login.php");
    exit();
}
?>
