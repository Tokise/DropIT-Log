<?php
/**
 * Database Configuration
 * DropIT Logistic System
 */

require_once __DIR__ . '/env.php';

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        Env::load();
        $this->host = Env::get('DB_HOST', 'localhost');
        $this->db_name = Env::get('DB_NAME', 'dropit_logistic');
        $this->username = Env::get('DB_USER', 'root');
        $this->password = Env::get('DB_PASS', '');
    }

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
        } catch(PDOException $exception) {
            error_log("Database connection error: " . $exception->getMessage());
            throw new Exception("Database connection failed: " . $exception->getMessage());
        }
        
        return $this->conn;
    }
}
?>
