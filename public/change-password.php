<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Database;
use App\Config\Auth;

Auth::requireLogin();
$user = Auth::user();

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string) ($_POST['currentPassword'] ?? '');
    $newPassword = (string) ($_POST['newPassword'] ?? '');
    $confirmPassword = (string) ($_POST['confirmPassword'] ?? '');

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'All fields are required.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match.';
    } else {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT passwordHash FROM `User` WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPassword, $row['passwordHash'])) {
            $error = 'Current password is incorrect.';
        } else {
            $newHash = Auth::hashPassword($newPassword);
            $upd = $pdo->prepare('UPDATE `User` SET passwordHash = ?, mustChangePassword = 0 WHERE id = ?');
            $upd->execute([$newHash, $user['id']]);

            Auth::audit('CHANGE_PASSWORD', 'User', $user['id'], ['email' => $user['email']]);
            $success = 'Password successfully updated.';
        }
    }
}

$pageTitle = "Change Password | IDEAPAD Seed Fund Portal";
require_once __DIR__ . '/../templates/header.php';
?>

<div class="flex-1 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
  <div class="max-w-md w-full bg-white p-8 rounded-2xl shadow-xl border border-gray-200 space-y-6">
    
    <div>
      <h2 class="text-2xl font-bold text-gray-900 tracking-tight">Security Credentials</h2>
      <p class="mt-1 text-xs text-gray-500">Update your account login password</p>
    </div>

    <?php if ($success): ?>
      <div class="p-4 rounded-xl bg-green-50 border border-green-200 text-sm text-green-700 flex items-start gap-3">
        <svg class="w-5 h-5 text-green-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
        <span><?= htmlspecialchars($success) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="p-4 rounded-xl bg-red-50 border border-red-200 text-sm text-red-700 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST" action="/change-password.php" class="space-y-4">
      <div>
        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Current Password *</label>
        <input 
          type="password" 
          name="currentPassword" 
          required 
          placeholder="••••••••••••"
          class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
        />
      </div>

      <div>
        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">New Password (Min 8 chars) *</label>
        <input 
          type="password" 
          name="newPassword" 
          required 
          placeholder="••••••••••••"
          class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
        />
      </div>

      <div>
        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Confirm New Password *</label>
        <input 
          type="password" 
          name="confirmPassword" 
          required 
          placeholder="••••••••••••"
          class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
        />
      </div>

      <div class="pt-2">
        <button 
          type="submit" 
          class="w-full py-3 px-4 rounded-xl text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-md"
        >
          Update Password
        </button>
      </div>
    </form>

  </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
