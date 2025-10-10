<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
try {
  if ($method !== 'POST') json_err('Method not allowed', 405);
  $data = read_json_body();
  require_params($data, ['email','password']);

  $conn = db_conn();
  $stmt = $conn->prepare('SELECT id, username, email, password_hash, full_name, role, module, supplier_id, is_active FROM users WHERE email = :email LIMIT 1');
  $stmt->execute([':email'=>$data['email']]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$u || !(int)$u['is_active']) json_err('Invalid credentials', 401);

  $ok = password_verify($data['password'], $u['password_hash'] ?? '');
  if (!$ok) json_err('Invalid credentials', 401);

  unset($u['password_hash']);
  $_SESSION['user'] = $u;
  
  // Determine redirect based on user type
  $redirectUrl = 'index.php'; // Default
  if ($u['supplier_id']) {
    // User is linked to a supplier - redirect to supplier portal
    $redirectUrl = 'supplier_portal.php';
  } elseif ($u['module']) {
    // Regular user with module - redirect to their module
    $redirectUrl = $u['module'] . '.php';
  }
  
  json_ok(['user'=>$u, 'redirect' => $redirectUrl]);
} catch (Exception $e) {
  json_err($e->getMessage(), 400);
}
