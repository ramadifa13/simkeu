<?php
require_once __DIR__ . '/config/bootstrap.php';

require_once 'config/config.php';
require_once 'config/database.php';

function redirectBasedOnRole() {
    if (isset($_SESSION['role'])) {
        $role = $_SESSION['role'];
        switch ($role) {
            case 'admin':
                header('Location: admin/dashboard.php');
                break;
            case 'teller':
                header('Location: teller/dashboard.php');
                break;
            case 'anggota':
                header('Location: anggota/dashboard.php');
                break;
            default:
                header('Location: index.php');
        }
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $loginToken = $_POST['login_csrf'] ?? '';
    if (!is_string($loginToken) || empty($_SESSION['login_csrf']) || !hash_equals($_SESSION['login_csrf'], $loginToken)) {
        $_SESSION['error'] = 'Formulir login kedaluwarsa. Silakan coba kembali.';
        header('Location: login.php');
        exit;
    }

    $pesanLoginGagal = 'Username atau password tidak sesuai.';

    if ($username === '' || $password === '') {
        $_SESSION['error'] = $pesanLoginGagal;
        header('Location: login.php');
        exit();
    }

    try {
        $database = new Database();
        $db = $database->getConnection();

        // Role ditentukan dari database setelah autentikasi — bukan dari pilihan client.
        $query = 'SELECT * FROM users WHERE username = :username';
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($users) > 1) {
            $_SESSION['error'] = 'Username tidak unik. Hubungi administrator.';
            header('Location: login.php');
            exit();
        }

        if (count($users) === 1) {
            $user = $users[0];
            if (password_verify($password, $user['password'])) {
                if (($user['status'] ?? '') !== 'aktif') {
                    $_SESSION['error'] = 'Akun tidak aktif. Hubungi pengurus koperasi.';
                    header('Location: login.php');
                    exit();
                }
                if (!in_array($user['role'], ['admin', 'teller', 'anggota'], true)) {
                    $_SESSION['error'] = $pesanLoginGagal;
                    header('Location: login.php');
                    exit();
                }
                session_regenerate_id(true);
                unset($_SESSION['login_csrf'], $_SESSION['layanan_csrf']);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                $_SESSION['role'] = $user['role'];

                $update_stmt = $db->prepare('UPDATE users SET last_login = NOW() WHERE id = :id');
                $update_stmt->bindParam(':id', $user['id']);
                $update_stmt->execute();

                redirectBasedOnRole();
            }
        }

        $_SESSION['error'] = $pesanLoginGagal;
        header('Location: login.php');
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Terjadi kesalahan sistem. Silakan coba lagi.';
        error_log('Login error: ' . $e->getMessage());
        header('Location: login.php');
        exit();
    }
}

$_SESSION['error'] = 'Metode request tidak valid!';
header('Location: login.php');
exit();
