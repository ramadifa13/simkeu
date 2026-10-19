<?php
require_once __DIR__ . '/env.php';

class Database {
    // Default aman untuk XAMPP lokal. Di hosting gunakan environment variable
    // DB_HOST, DB_NAME, DB_USER, dan DB_PASSWORD.
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function getConnection() {
        $this->conn = null;
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->db_name = getenv('DB_NAME') ?: 'simkeusi_koperasi_db';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException(
                'Driver PDO MySQL belum aktif. Aktifkan extension=pdo_mysql pada php.ini yang dipakai Apache/XAMPP, lalu restart Apache.'
            );
        }

        $this->conn = new PDO(
            "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4",
            $this->username,
            $this->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return $this->conn;
    }
}
?>
