<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Database;
use App\Config\Auth;

Auth::initSession();

if (Auth::check()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $teamName = trim((string) ($_POST['teamName'] ?? ''));
    $enrollmentNo = trim((string) ($_POST['enrollmentNo'] ?? ''));
    $program = trim((string) ($_POST['program'] ?? ''));
    $phone = preg_replace('/[^0-9]/', '', (string) ($_POST['phone'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirmPassword'] ?? '');

    if (empty($name) || empty($teamName) || empty($enrollmentNo) || empty($program)) {
        $error = 'All fields (Founder Name, Team Name, Enrollment No, Program) are required.';
    } elseif (strlen($phone) < 10) {
        $error = 'Please enter a valid 10-digit mobile number.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid official email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT id FROM `User` WHERE LOWER(TRIM(email)) = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email address already exists. Please sign in.';
        } else {
            $userId = Auth::generateId('u');
            $startupId = Auth::generateId('s');
            $appId = Auth::generateId('app');
            $passwordHash = Auth::hashPassword($password);

            $pdo->beginTransaction();
            try {
                // 1. Create User
                $uStmt = $pdo->prepare('INSERT INTO `User` (id, email, name, passwordHash, role, approvalStatus, mustChangePassword, active, createdAt) VALUES (?, ?, ?, ?, "STARTUP", "APPROVED", 0, 1, NOW(3))');
                $uStmt->execute([$userId, $email, $name, $passwordHash]);

                // 2. Create Startup profile
                $sStmt = $pdo->prepare('INSERT INTO `Startup` (id, userId, teamName, enrollmentNo, program, phone) VALUES (?, ?, ?, ?, ?, ?)');
                $sStmt->execute([$startupId, $userId, $teamName, $enrollmentNo, $program, $phone]);

                // 3. Initialize blank draft application
                $initialFormData = [
                    'applicantName' => $name,
                    'teamName' => $teamName,
                    'enrollmentNo' => $enrollmentNo,
                    'program' => $program,
                    'phone' => $phone,
                    'email' => $email,
                    'problemStatement' => '',
                    'proposedSolution' => '',
                    'effortTraction' => '',
                    'experiments' => [],
                    'selfAssessment' => ['trl' => 1, 'mrl' => 1, 'irl' => 1, 'track' => 'PROTOTYPING', 'milestones' => []],
                    'agreementAccepted' => false,
                    'declarationSigned' => false,
                    'declarationDate' => ''
                ];

                $aStmt = $pdo->prepare('INSERT INTO `Application` (id, startupId, version, status, grantType, requestedTotal, formData, createdAt, updatedAt) VALUES (?, ?, 1, "DRAFT", "PROOF", 0.00, ?, NOW(3), NOW(3))');
                $aStmt->execute([$appId, $startupId, json_encode($initialFormData, JSON_UNESCAPED_UNICODE)]);

                $pdo->commit();

                // Auto-login newly created startup
                Auth::login($email, $password);
                header('Location: /dashboard.php');
                exit;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $error = 'Registration failed: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = "Startup Registration | IDEAPAD Seed Fund Portal";
require_once __DIR__ . '/../templates/header.php';
?>

<div class="flex-1 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
  <div class="max-w-xl w-full space-y-6 bg-white p-8 rounded-2xl shadow-xl border border-gray-200">
    
    <div class="text-center">
      <img src="/brand/ideapad-logo.png" alt="IDEAPAD" class="h-10 w-auto mx-auto mb-3 object-contain">
      <h2 class="text-2xl font-bold text-gray-900 tracking-tight">Startup Venture Registration</h2>
      <p class="mt-1 text-xs text-gray-500">Register your team to apply for IDEAPAD innovation seed grants</p>
    </div>

    <?php if ($error): ?>
      <div class="p-4 rounded-xl bg-red-50 border border-red-200 text-sm text-red-700 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST" action="/register.php" class="space-y-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Founder / Team Leader Name *</label>
          <input 
            type="text" 
            name="name" 
            required 
            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
            placeholder="e.g. Vikramaditya Sharma"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
          />
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Startup / Venture Name *</label>
          <input 
            type="text" 
            name="teamName" 
            required 
            value="<?= htmlspecialchars($_POST['teamName'] ?? '') ?>"
            placeholder="e.g. SolarVidyut AI"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
          />
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Enrollment / Student ID *</label>
          <input 
            type="text" 
            name="enrollmentNo" 
            required 
            value="<?= htmlspecialchars($_POST['enrollmentNo'] ?? '') ?>"
            placeholder="e.g. 0901IT221050"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
          />
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Academic Program / Degree *</label>
          <input 
            type="text" 
            name="program" 
            required 
            value="<?= htmlspecialchars($_POST['program'] ?? '') ?>"
            placeholder="e.g. B.Tech Information Technology"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
          />
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Contact Mobile Number *</label>
          <input 
            type="tel" 
            name="phone" 
            required 
            maxlength="10"
            value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
            placeholder="10-digit mobile number"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
          />
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Official Email Address *</label>
          <input 
            type="email" 
            name="email" 
            required 
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            placeholder="founder@itm.ac.in"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
          />
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Create Password *</label>
          <input 
            type="password" 
            name="password" 
            required 
            placeholder="Minimum 8 characters"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
          />
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Confirm Password *</label>
          <input 
            type="password" 
            name="confirmPassword" 
            required 
            placeholder="Re-enter password"
            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
          />
        </div>
      </div>

      <div class="pt-2">
        <button 
          type="submit" 
          class="w-full py-3 px-4 rounded-xl text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-200 transition-all shadow-md hover:shadow-lg"
        >
          Create Startup Account & Open Application Form
        </button>
      </div>
    </form>

    <div class="pt-4 border-t border-gray-100 text-center text-xs text-gray-500">
      Already have a startup account? 
      <a href="/login.php" class="font-bold text-blue-600 hover:underline">Sign In here</a>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
