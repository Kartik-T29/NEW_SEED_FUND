<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Database;
use App\Config\Auth;

Auth::initSession();

if (Auth::check()) {
    header('Location: /sec.php');
    exit;
}

$error = null;
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $designation = trim((string) ($_POST['designation'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirmPassword'] ?? '');
    $inviteCode = trim((string) ($_POST['inviteCode'] ?? ''));

    if (empty($name) || empty($designation) || empty($department)) {
        $error = 'Full Name, Designation, and Department/School are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid institutional email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM `User` WHERE LOWER(TRIM(email)) = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email address already exists.';
        } else {
            $userId = Auth::generateId('u');
            $passwordHash = Auth::hashPassword($password);

            // New SEC Evaluator is created strictly with approvalStatus = PENDING
            $ins = $pdo->prepare('INSERT INTO `User` (id, email, name, passwordHash, role, approvalStatus, mustChangePassword, active, designation, department, createdAt) VALUES (?, ?, ?, ?, "SEC_MEMBER", "PENDING", 0, 1, ?, ?, NOW(3))');
            $ins->execute([$userId, $email, $name, $passwordHash, $designation, $department]);

            Auth::audit('SEC_SELF_REGISTER', 'User', $userId, ['email' => $email, 'designation' => $designation]);
            $submitted = true;
        }
    }
}

$pageTitle = "SEC Evaluator Registration | IDEAPAD Seed Fund Portal";
require_once __DIR__ . '/../templates/header.php';
?>

<div class="flex-1 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
  <div class="max-w-xl w-full bg-white p-8 rounded-2xl shadow-xl border border-gray-200">

    <?php if ($submitted): ?>
      <!-- Holding Screen: Waiting for Admin Approval -->
      <div class="text-center space-y-5">
        <div class="w-16 h-16 rounded-full bg-yellow-50 text-yellow-600 flex items-center justify-center mx-auto">
          <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 tracking-tight">Registration Received</h2>
        
        <div class="p-5 rounded-xl bg-yellow-50/80 border border-yellow-200 text-left space-y-2 text-sm text-yellow-900">
          <div class="flex items-center gap-2 font-bold text-yellow-800">
            <svg class="w-5 h-5 text-yellow-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            Pending Administrator Approval
          </div>
          <p class="text-xs text-yellow-800 leading-relaxed">
            Your SEC Evaluator profile for <strong><?= htmlspecialchars($email ?? '') ?></strong> has been created with status <strong>PENDING</strong>.
          </p>
          <p class="text-xs text-yellow-700 leading-relaxed">
            In compliance with university governance and confidentiality norms, access to applicant proposal dossiers and scoring sheets is restricted until the System Administrator verifies your appointment.
          </p>
        </div>

        <p class="text-xs text-gray-500">
          You will be able to log in to the evaluation workspace once your appointment is verified.
        </p>

        <div class="pt-3">
          <a href="/login.php" class="inline-block w-full py-3 px-4 rounded-xl text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-md">
            Return to Login
          </a>
        </div>
      </div>

    <?php else: ?>

      <div class="text-center mb-6">
        <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center mx-auto mb-3 shadow-xs font-bold text-xl">
          SEC
        </div>
        <h2 class="text-2xl font-bold text-gray-900 tracking-tight">SEC Evaluator Registration</h2>
        <p class="mt-1 text-xs text-gray-500">Apply to join the Seed Evaluation Committee review panel</p>
      </div>

      <div class="mb-5 p-3.5 rounded-xl bg-blue-50 border border-blue-200 text-xs text-blue-800 flex items-start gap-2.5">
        <svg class="w-4 h-4 text-blue-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <span><strong>Notice:</strong> All SEC evaluator registrations require explicit administrative authorization before evaluation access is granted.</span>
      </div>

      <?php if ($error): ?>
        <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 text-sm text-red-700 flex items-start gap-3">
          <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" action="/sec-register.php" class="space-y-4">
        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Full Name & Honorific *</label>
          <input 
            type="text" 
            name="name" 
            required 
            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
            placeholder="e.g. Dr. Anand Verma"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-purple-600 focus:bg-white focus:outline-none"
          />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Designation / Role *</label>
            <input 
              type="text" 
              name="designation" 
              required 
              value="<?= htmlspecialchars($_POST['designation'] ?? '') ?>"
              placeholder="e.g. Professor & HOD"
              class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-purple-600 focus:bg-white focus:outline-none"
            />
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Department / School *</label>
            <input 
              type="text" 
              name="department" 
              required 
              value="<?= htmlspecialchars($_POST['department'] ?? '') ?>"
              placeholder="e.g. Computer Science"
              class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-purple-600 focus:bg-white focus:outline-none"
            />
          </div>
        </div>

        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Institutional Email Address *</label>
          <input 
            type="email" 
            name="email" 
            required 
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            placeholder="evaluator@itmuniversity.ac.in"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-purple-600 focus:bg-white focus:outline-none"
          />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Create Password *</label>
            <input 
              type="password" 
              name="password" 
              required 
              placeholder="Minimum 8 characters"
              class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-purple-600 focus:bg-white focus:outline-none"
            />
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Confirm Password *</label>
            <input 
              type="password" 
              name="confirmPassword" 
              required 
              placeholder="Re-enter password"
              class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-purple-600 focus:bg-white focus:outline-none"
            />
          </div>
        </div>

        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Committee Invite Code (Optional)</label>
          <input 
            type="text" 
            name="inviteCode" 
            value="<?= htmlspecialchars($_POST['inviteCode'] ?? '') ?>"
            placeholder="Enter authorization code if provided by Admin"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-purple-600 focus:bg-white focus:outline-none"
          />
        </div>

        <div class="pt-2">
          <button 
            type="submit" 
            class="w-full py-3 px-4 rounded-xl text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 focus:ring-4 focus:ring-purple-200 transition-all shadow-md hover:shadow-lg"
          >
            Submit SEC Application
          </button>
        </div>
      </form>

      <div class="pt-4 border-t border-gray-100 text-center text-xs text-gray-500">
        Already an approved SEC member? 
        <a href="/login.php" class="font-bold text-purple-600 hover:underline">Sign In here</a>
      </div>

    <?php endif; ?>

  </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
