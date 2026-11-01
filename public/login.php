<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Auth;

Auth::initSession();

// If already logged in, redirect to respective dashboard
if (Auth::check()) {
    $role = Auth::role();
    if ($role === 'ADMIN') {
        header('Location: /admin.php');
    } elseif ($role === 'SEC_MEMBER') {
        header('Location: /sec.php');
    } else {
        header('Location: /dashboard.php');
    }
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $result = Auth::login($email, $password);
    if ($result['success']) {
        $role = $result['user']['role'];
        if ($role === 'ADMIN') {
            header('Location: /admin.php');
        } elseif ($role === 'SEC_MEMBER') {
            header('Location: /sec.php');
        } else {
            header('Location: /dashboard.php');
        }
        exit;
    } else {
        $error = $result['error'];
    }
}

$pageTitle = "Sign In | IDEAPAD Seed Fund Portal";
require_once __DIR__ . '/../templates/header.php';
?>

<div class="flex-1 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
  <div class="max-w-md w-full space-y-8 bg-white p-8 rounded-2xl shadow-xl border border-gray-200">
    
    <!-- Header -->
    <div class="text-center">
      <img src="/brand/ideapad-logo.png" alt="IDEAPAD" class="h-12 w-auto mx-auto mb-4 object-contain">
      <h2 class="text-2xl font-bold text-gray-900 tracking-tight">Portal Authentication</h2>
      <p class="mt-1 text-xs text-gray-500">Sign in to your Startup, SEC Evaluator, or Admin account</p>
    </div>

    <!-- Error Alert -->
    <?php if ($error): ?>
      <div class="p-4 rounded-xl bg-red-50 border border-red-200 text-sm text-red-700 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="/login.php" class="mt-8 space-y-5">
      <div>
        <label for="email" class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Institutional Email</label>
        <input 
          id="email" 
          name="email" 
          type="email" 
          required 
          autocomplete="email"
          value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
          placeholder="user@itm.ac.in / ideapad.co.in"
          class="mt-1 block w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm shadow-xs focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none transition-all"
        />
      </div>

      <div>
        <div class="flex items-center justify-between">
          <label for="password" class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Password</label>
        </div>
        <div class="relative mt-1">
          <input 
            id="password" 
            name="password" 
            type="password" 
            required 
            autocomplete="current-password"
            placeholder="••••••••••••"
            class="block w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm shadow-xs focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none transition-all pr-10"
          />
          <button 
            type="button" 
            onclick="togglePasswordVisibility()" 
            class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
          >
            <svg id="eye-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
          </button>
        </div>
      </div>

      <div>
        <button 
          type="submit" 
          class="w-full py-3 px-4 rounded-xl text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-200 transition-all shadow-md hover:shadow-lg"
        >
          Sign In to Portal
        </button>
      </div>
    </form>

    <!-- Registration Links -->
    <div class="pt-4 border-t border-gray-100 space-y-2 text-center text-xs text-gray-500">
      <div>
        Are you a startup founder? 
        <a href="/register.php" class="font-bold text-blue-600 hover:underline">Register New Venture</a>
      </div>
      <div>
        Are you an appointed evaluation expert? 
        <a href="/sec-register.php" class="font-bold text-purple-600 hover:underline">SEC Evaluator Registration</a>
      </div>
    </div>

  </div>
</div>

<script>
function togglePasswordVisibility() {
  const pwd = document.getElementById('password');
  const icon = document.getElementById('eye-icon');
  if (pwd.type === 'password') {
    pwd.type = 'text';
    icon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"></path>`;
  } else {
    pwd.type = 'password';
    icon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>`;
  }
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
