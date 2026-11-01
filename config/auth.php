<?php
/**
 * Native PHP Session Authentication & Access Control Manager
 */

declare(strict_types=1);

namespace App\Config;

use PDO;

class Auth {
    public static function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $savePath = ini_get('session.save_path') ?: sys_get_temp_dir();
            if (!is_dir($savePath) || !is_writable($savePath)) {
                ini_set('session.save_path', sys_get_temp_dir());
            }
            if (!headers_sent()) {
                ini_set('session.cookie_httponly', '1');
                ini_set('session.use_only_cookies', '1');
                ini_set('session.cookie_samesite', 'Lax');
            }
            @session_start();
        }
    }

    public static function check(): bool {
        self::initSession();
        return !empty($_SESSION['user']['id']);
    }

    public static function user(): ?array {
        self::initSession();
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?string {
        return self::user()['id'] ?? null;
    }

    public static function role(): ?string {
        return self::user()['role'] ?? null;
    }

    public static function login(string $email, string $password): array {
        self::initSession();
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT * FROM `User` WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        if (!password_verify($password, $user['passwordHash'])) {
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        if (!$user['active']) {
            return ['success' => false, 'error' => 'Your account has been deactivated. Contact an administrator.'];
        }

        if ($user['approvalStatus'] === 'PENDING') {
            return [
                'success' => false,
                'error' => 'Your SEC Evaluator account is pending Administrator review and approval. Access to applicant sheets is restricted until approved.'
            ];
        }

        if ($user['approvalStatus'] === 'REJECTED') {
            return ['success' => false, 'error' => 'Your account registration was rejected by an administrator.'];
        }

        // Fetch startup profile if startup role
        $startup = null;
        if ($user['role'] === 'STARTUP') {
            $sStmt = $pdo->prepare('SELECT * FROM `Startup` WHERE userId = ? LIMIT 1');
            $sStmt->execute([$user['id']]);
            $startup = $sStmt->fetch() ?: null;
        }

        // Regenerate session ID on login to prevent fixation
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'],
            'approvalStatus' => $user['approvalStatus'],
            'mustChangePassword' => (bool) $user['mustChangePassword'],
            'designation' => $user['designation'],
            'department' => $user['department'],
            'startup' => $startup,
        ];

        self::audit('LOGIN', 'User', $user['id'], ['email' => $user['email'], 'role' => $user['role']]);

        return ['success' => true, 'user' => $_SESSION['user']];
    }

    public static function requireLogin(): void {
        self::initSession();
        if (!self::check()) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function requireRole(string|array $allowedRoles): void {
        self::requireLogin();
        $userRole = self::role();
        $roles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];

        if (!in_array($userRole, $roles, true)) {
            http_response_code(403);
            require_once __DIR__ . '/../templates/header.php';
            echo '<div class="max-w-2xl mx-auto my-16 p-8 bg-white rounded-2xl shadow-xl border border-red-200 text-center space-y-4">';
            echo '<div class="w-16 h-16 rounded-full bg-red-50 text-red-600 mx-auto flex items-center justify-center font-bold text-2xl">!</div>';
            echo '<h1 class="text-2xl font-bold text-gray-900">Access Denied</h1>';
            echo '<p class="text-sm text-gray-600">You do not have administrative authorization to access this area.</p>';
            echo '<div class="pt-4"><a href="/index.php" class="inline-block px-5 py-2.5 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700">Return to Portal Home</a></div>';
            echo '</div>';
            require_once __DIR__ . '/../templates/footer.php';
            exit;
        }
    }

    public static function logout(): void {
        self::initSession();
        if (self::id()) {
            self::audit('LOGOUT', 'User', self::id(), []);
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    public static function csrfToken(): string {
        self::initSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function csrfField(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verifyCsrf(?string $token = null): bool {
        self::initSession();
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        }
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function generateId(string $prefix = ''): string {
        return ($prefix ? $prefix . '_' : '') . bin2hex(random_bytes(10));
    }

    public static function audit(string $action, string $targetType, ?string $targetId = null, ?array $details = null): void {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('INSERT INTO `AuditLog` (id, actorId, action, entity, entityId, details, at) VALUES (?, ?, ?, ?, ?, ?, NOW(3))');
            $stmt->execute([
                self::generateId('log'),
                self::id() ?: 'system',
                $action,
                $targetType,
                $targetId,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null
            ]);
        } catch (\Throwable $e) {
            error_log('[Audit Error] ' . $e->getMessage());
        }
    }
}

if (!class_exists('Auth', false)) {
    class_alias(Auth::class, 'Auth');
}
