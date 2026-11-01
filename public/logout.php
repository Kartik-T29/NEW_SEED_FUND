<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

use App\Config\Auth;

Auth::logout();
header('Location: /login.php');
exit;
