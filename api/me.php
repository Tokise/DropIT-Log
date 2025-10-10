<?php
require_once __DIR__ . '/common.php';

try {
  $u = require_auth();
  json_ok($u);
} catch (Exception $e) {
  json_err($e->getMessage(), 400);
}
