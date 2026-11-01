<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Database;
use App\Config\Constants;
use App\Config\Auth;

Auth::requireRole('ADMIN');
$currentUser = Auth::user();
$pdo = Database::getConnection();

$appId = $_GET['id'] ?? null;
$tab = $_GET['tab'] ?? 'applications';

// -------------------------------------------------------------
// POST Actions: SEC Approval/Rejection, Form B Submission
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Approve SEC Member
    if ($action === 'approve_sec') {
        $secUserId = $_POST['userId'] ?? '';
        $pdo->prepare('UPDATE `User` SET approvalStatus = "APPROVED", active = 1 WHERE id = ? AND role = "SEC_MEMBER"')
            ->execute([$secUserId]);
        Auth::audit('APPROVE_SEC_MEMBER', 'User', $secUserId, ['approvedBy' => $currentUser['id']]);
        header('Location: /admin.php?tab=sec_users');
        exit;
    }

    // 2. Reject SEC Member
    if ($action === 'reject_sec') {
        $secUserId = $_POST['userId'] ?? '';
        $pdo->prepare('UPDATE `User` SET approvalStatus = "REJECTED" WHERE id = ? AND role = "SEC_MEMBER"')
            ->execute([$secUserId]);
        Auth::audit('REJECT_SEC_MEMBER', 'User', $secUserId, ['rejectedBy' => $currentUser['id']]);
        header('Location: /admin.php?tab=sec_users');
        exit;
    }

    // 3. Save / Submit Form B Grant Sanction Order
    if ($action === 'submit_form_b' && $appId) {
        $approvedGrant = $_POST['approvedGrant'] ?? 'PROOF';
        $approvedTotal = (float)($_POST['approvedTotal'] ?? 0);
        $recommendationNote = trim((string)($_POST['recommendationNote'] ?? ''));
        $secDecisionNote = trim((string)($_POST['secDecisionNote'] ?? ''));
        $termsAcknowledged = !empty($_POST['termsAcknowledged']);
        $adminReason = trim((string)($_POST['adminReason'] ?? 'Sanction finalized by administration'));

        // 9 budget headers
        $approvedAmounts = [];
        foreach (array_keys(Constants::BUDGET_HEADERS) as $hKey) {
            $approvedAmounts[$hKey] = (float)($_POST["alloc_$hKey"] ?? 0);
        }

        // 8 timeline dates
        $timeline = [];
        foreach (array_keys(Constants::FORM_B_TIMELINE_FIELDS) as $tKey) {
            $timeline[$tKey] = trim((string)($_POST["tl_$tKey"] ?? ''));
        }

        $evalId = Auth::generateId('geval');
        $pdo->beginTransaction();

        $delPrev = $pdo->prepare('DELETE FROM `GrantEvaluation` WHERE applicationId = ?');
        $delPrev->execute([$appId]);

        $insG = $pdo->prepare('
            INSERT INTO `GrantEvaluation` (id, applicationId, adminId, approvedGrant, approvedAmounts, approvedTotal, timeline, recommendationNote, secDecisionNote, termsAcknowledged, status, submittedAt)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "SUBMITTED", NOW(3))
        ');
        $insG->execute([
            $evalId,
            $appId,
            $currentUser['id'],
            $approvedGrant,
            json_encode($approvedAmounts, JSON_UNESCAPED_UNICODE),
            $approvedTotal,
            json_encode($timeline, JSON_UNESCAPED_UNICODE),
            $recommendationNote,
            $secDecisionNote,
            $termsAcknowledged ? 1 : 0
        ]);

        // Mark Application status = APPROVED (or REJECTED if approvedGrant == 'REJECTED')
        $newAppStatus = ($approvedGrant === 'REJECTED') ? 'REJECTED' : 'APPROVED';
        $uApp = $pdo->prepare('UPDATE `Application` SET status = ?, updatedAt = NOW(3) WHERE id = ?');
        $uApp->execute([$newAppStatus, $appId]);

        Auth::audit('SUBMIT_FORM_B', 'GrantEvaluation', $evalId, [
            'applicationId' => $appId,
            'status' => $newAppStatus,
            'approvedTotal' => $approvedTotal,
            'reason' => $adminReason
        ]);

        $pdo->commit();
        header("Location: /admin.php?id=" . urlencode($appId));
        exit;
    }
}

// -------------------------------------------------------------
// Application Detail & Form B Sanction View
// -------------------------------------------------------------
if ($appId) {
    $aStmt = $pdo->prepare('
        SELECT a.*, s.teamName, s.enrollmentNo, s.program, s.phone, u.name as founderName, u.email as founderEmail
        FROM `Application` a
        JOIN `Startup` s ON a.startupId = s.id
        JOIN `User` u ON s.userId = u.id
        WHERE a.id = ?
        LIMIT 1
    ');
    $aStmt->execute([$appId]);
    $application = $aStmt->fetch();

    if (!$application) die("Application not found.");

    // Fetch SEC member assessments
    $assStmt = $pdo->prepare('
        SELECT ass.*, u.name as memberName, u.designation, u.department
        FROM `Assessment` ass
        JOIN `User` u ON ass.memberId = u.id
        WHERE ass.applicationId = ?
    ');
    $assStmt->execute([$appId]);
    $assessments = $assStmt->fetchAll();

    // Fetch existing GrantEvaluation (Form B) if present
    $geStmt = $pdo->prepare('SELECT * FROM `GrantEvaluation` WHERE applicationId = ? LIMIT 1');
    $geStmt->execute([$appId]);
    $existingFormB = $geStmt->fetch();

    $formBData = [
        'approvedGrant' => $existingFormB['approvedGrant'] ?? ($application['grantType'] ?? 'PROOF'),
        'approvedAmounts' => json_decode($existingFormB['approvedAmounts'] ?? '{}', true) ?: [],
        'approvedTotal' => (float)($existingFormB['approvedTotal'] ?? $application['requestedTotal']),
        'timeline' => json_decode($existingFormB['timeline'] ?? '{}', true) ?: [],
        'recommendationNote' => $existingFormB['recommendationNote'] ?? '',
        'secDecisionNote' => $existingFormB['secDecisionNote'] ?? '',
        'status' => $existingFormB['status'] ?? 'DRAFT'
    ];

    $pageTitle = "Form B Sanction Console | " . htmlspecialchars($application['teamName']);
    require_once __DIR__ . '/../templates/header.php';
    ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      
      <div class="flex items-center justify-between border-b pb-4">
        <div>
          <a href="/admin.php" class="text-xs font-bold text-blue-600 hover:underline mb-1 inline-block">← Back to Admin Console</a>
          <h1 class="text-2xl font-extrabold text-gray-900"><?= htmlspecialchars($application['teamName']) ?></h1>
          <p class="text-xs text-gray-500">Applicant: <strong><?= htmlspecialchars($application['founderName']) ?></strong> (<?= htmlspecialchars($application['enrollmentNo']) ?>) • Application #<?= htmlspecialchars($appId) ?></p>
        </div>

        <div class="flex items-center gap-2">
          <a href="/reports.php?id=<?= urlencode($appId) ?>&type=form-b" target="_blank" class="px-4 py-2 bg-blue-50 text-blue-700 font-bold text-xs rounded-xl hover:bg-blue-100 border border-blue-200 flex items-center gap-1.5">
            Print Sanction Order (Form B) ↗
          </a>
        </div>
      </div>

      <!-- Committee Reviews Summary Matrix -->
      <div class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 space-y-4">
        <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Committee Assessments Summary (Form A)</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <?php if (empty($assessments)): ?>
            <p class="text-xs text-gray-400 col-span-3">No SEC member evaluations recorded yet.</p>
          <?php else: foreach ($assessments as $idx => $ass): 
            $statusForm = json_decode($ass['statusForm'] ?? '{}', true);
            $expForm = json_decode($ass['experimentForm'] ?? '{}', true);
            $answers = $expForm['answers'] ?? [];
            $yesCount = count(array_filter($answers));
          ?>
            <div class="p-4 rounded-xl border border-gray-200 bg-gray-50/70 space-y-2 text-xs">
              <div class="flex items-center justify-between border-b pb-2">
                <span class="font-bold text-gray-900"><?= htmlspecialchars($ass['memberName']) ?></span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $ass['status'] === 'SUBMITTED' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                  <?= $ass['status'] ?>
                </span>
              </div>
              <div class="text-gray-500 text-[11px]"><?= htmlspecialchars($ass['designation']) ?>, <?= htmlspecialchars($ass['department']) ?></div>
              <div class="pt-1 flex items-center justify-between font-semibold">
                <span>Voted Tier:</span>
                <span class="text-blue-700 font-bold"><?= htmlspecialchars($ass['suggestedGrant']) ?></span>
              </div>
              <div class="flex items-center justify-between font-semibold">
                <span>TRL / MRL / IRL:</span>
                <span class="font-mono"><?= $statusForm['trl'] ?? 1 ?> / <?= $statusForm['mrl'] ?? 1 ?> / <?= $statusForm['irl'] ?? 1 ?></span>
              </div>
              <div class="flex items-center justify-between font-semibold">
                <span>Rubric Yes Answers:</span>
                <span class="font-mono font-bold text-emerald-700"><?= $yesCount ?> / 12 Yes</span>
              </div>
              <?php if (!empty($ass['recommendation'])): ?>
                <p class="p-2 bg-white rounded border text-[11px] text-gray-600 italic">"<?= htmlspecialchars($ass['recommendation']) ?>"</p>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- Form B Grant Sanction Editor -->
      <form method="POST" action="/admin.php?id=<?= urlencode($appId) ?>" class="bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
        <input type="hidden" name="action" value="submit_form_b">

        <div class="border-b pb-4">
          <h2 class="text-xl font-bold text-gray-900">Form B: Official Grant Sanction Order</h2>
          <p class="text-xs text-gray-500 mt-1">Finalize approved grant tier, moderate funding headers, and configure the 8 implementation timeline dates.</p>
        </div>

        <!-- 1. Approved Grant Tier -->
        <div class="space-y-3">
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Approved Grant Decision *</label>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
            <?php foreach (['VALIDATION' => 'Validation Grant', 'PROOF' => 'Proof Grant', 'SCALING' => 'Scaling Grant', 'REJECTED' => 'None / Rejected'] as $gK => $gLabel): ?>
              <label class="cursor-pointer border rounded-xl p-3 flex items-center gap-2 <?= $formBData['approvedGrant'] === $gK ? 'border-blue-600 bg-blue-50 ring-2 ring-blue-600 font-bold' : 'border-gray-200' ?>">
                <input type="radio" name="approvedGrant" value="<?= $gK ?>" <?= $formBData['approvedGrant'] === $gK ? 'checked' : '' ?> class="text-blue-600 focus:ring-blue-500">
                <span><?= htmlspecialchars($gLabel) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- 2. Budget Allocation across 9 Headers -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Sanctioned Budget Allocation (Annexure 1)</label>
            <span class="text-xs text-gray-500">Total Sanctioned: <strong id="admin-total-display" class="font-mono text-sm text-blue-700">₹0.00</strong></span>
          </div>

          <div class="space-y-2 border rounded-xl p-4 bg-gray-50">
            <?php foreach (Constants::BUDGET_HEADERS as $hK => $hV): ?>
              <div class="flex items-center justify-between gap-3 text-xs">
                <span class="text-gray-700 font-medium truncate max-w-[280px]"><?= htmlspecialchars($hV['name']) ?></span>
                <div class="flex items-center gap-1 w-36">
                  <span class="text-gray-400 font-mono">₹</span>
                  <input 
                    type="number" 
                    step="1"
                    name="alloc_<?= $hK ?>" 
                    value="<?= htmlspecialchars((string)($formBData['approvedAmounts'][$hK] ?? 0)) ?>" 
                    oninput="recalculateAdminTotal()"
                    class="w-full text-right font-mono p-1.5 border rounded-lg bg-white admin-alloc-calc"
                  />
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- 3. Approved Timeline: 8 Dates (Policy P9) -->
        <div class="space-y-3">
          <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Approved Project Timeline (8 Dates per Policy P9)</label>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 p-4 rounded-xl border bg-gray-50">
            <?php foreach (Constants::FORM_B_TIMELINE_FIELDS as $fKey => $fInfo): ?>
              <div class="space-y-1">
                <label class="block text-[11px] font-bold text-gray-800"><?= htmlspecialchars($fInfo['label']) ?></label>
                <input 
                  type="<?= str_contains($fKey, 'Duration') ? 'text' : 'date' ?>" 
                  name="tl_<?= $fKey ?>" 
                  value="<?= htmlspecialchars($formBData['timeline'][$fKey] ?? '') ?>"
                  placeholder="<?= str_contains($fKey, 'Duration') ? 'e.g. 4 to 6 months' : '' ?>"
                  class="w-full p-2 border rounded-lg text-xs bg-white"
                />
                <span class="block text-[10px] text-gray-400 truncate"><?= htmlspecialchars($fInfo['description']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- 4. Notes & Justifications -->
        <div class="space-y-4">
          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Final SEC Recommendation Note (Form B)</label>
            <textarea name="recommendationNote" rows="3" class="w-full p-2.5 bg-gray-50 border rounded-xl text-xs" placeholder="Final combined observations and recommendations..."><?= htmlspecialchars($formBData['recommendationNote']) ?></textarea>
          </div>

          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">SEC Majority Override Justification (If departing from committee recommendation)</label>
            <textarea name="secDecisionNote" rows="2" class="w-full p-2.5 bg-gray-50 border rounded-xl text-xs" placeholder="Provide administrative rationale if approved grant departs from SEC vote..."><?= htmlspecialchars($formBData['secDecisionNote']) ?></textarea>
          </div>
        </div>

        <!-- 5. Terms & Audit Action Reason -->
        <div class="space-y-3 pt-3 border-t">
          <label class="flex items-start gap-3 p-3.5 rounded-xl border border-gray-200 bg-blue-50/60 cursor-pointer">
            <input type="checkbox" name="termsAcknowledged" value="1" <?= !empty($existingFormB['termsAcknowledged']) ? 'checked' : '' ?> required class="mt-0.5 h-4 w-4 rounded text-blue-600 focus:ring-blue-500">
            <span class="text-xs text-gray-800 font-medium">
              I certify that this grant sanction order complies with ITM University Innovation Fund policies, non-dilutive grant caps, and audit regulations.
            </span>
          </label>

          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Administrator Action Reason (Audited Ledger) *</label>
            <input type="text" name="adminReason" required value="Form B Grant Sanction Order issued by Director" class="w-full p-2.5 bg-gray-50 border rounded-xl text-xs">
          </div>
        </div>

        <div class="pt-4 border-t flex justify-end">
          <button type="submit" class="px-6 py-3 bg-green-600 text-white font-bold text-sm rounded-xl hover:bg-green-700 shadow-md">
            Issue & Finalize Form B Grant Sanction Order ✓
          </button>
        </div>

      </form>

    </div>

    <script>
    function recalculateAdminTotal() {
      let total = 0;
      document.querySelectorAll('.admin-alloc-calc').forEach(inp => {
        total += parseFloat(inp.value || '0');
      });
      document.getElementById('admin-total-display').innerText = '₹' + total.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    }
    recalculateAdminTotal();
    </script>
    <?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// -------------------------------------------------------------
// Main Administrator Dashboard (Queue, SEC Approvals, Audit Logs)
// -------------------------------------------------------------
// Fetch pending and approved SEC members
$uSecStmt = $pdo->prepare('SELECT * FROM `User` WHERE role = "SEC_MEMBER" ORDER BY approvalStatus ASC, createdAt DESC');
$uSecStmt->execute();
$secUsers = $uSecStmt->fetchAll();

// Fetch applications
$appQueueStmt = $pdo->prepare('
    SELECT a.*, s.teamName, s.enrollmentNo, s.program,
           (SELECT COUNT(*) FROM `Assessment` WHERE applicationId = a.id AND status = "SUBMITTED") as submittedCount
    FROM `Application` a
    JOIN `Startup` s ON a.startupId = s.id
    ORDER BY a.createdAt DESC
');
$appQueueStmt->execute();
$allApplications = $appQueueStmt->fetchAll();

// Fetch audit logs
$logStmt = $pdo->prepare('SELECT * FROM `AuditLog` ORDER BY createdAt DESC LIMIT 20');
$logStmt->execute();
$auditLogs = $logStmt->fetchAll();

$pageTitle = "Administration Console | IDEAPAD Seed Fund";
require_once __DIR__ . '/../templates/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
  
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
      <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Incubator Administration Console</h1>
      <p class="text-xs text-gray-500 mt-1">Manage SEC evaluator approvals, issue Form B grant sanction orders, and review audit trails.</p>
    </div>
    <div class="flex items-center gap-2">
      <span class="px-3 py-1.5 rounded-xl bg-amber-50 text-amber-800 font-bold text-xs border border-amber-200">
        Administrator: <?= htmlspecialchars($currentUser['name']) ?>
      </span>
    </div>
  </div>

  <!-- Top Stats -->
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
    <div class="p-5 bg-white rounded-2xl shadow-xs border border-gray-200">
      <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Total Applications</span>
      <span class="text-2xl font-extrabold text-gray-900 mt-1 block"><?= count($allApplications) ?></span>
    </div>
    <div class="p-5 bg-white rounded-2xl shadow-xs border border-gray-200">
      <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider">SEC Evaluators</span>
      <span class="text-2xl font-extrabold text-purple-700 mt-1 block"><?= count($secUsers) ?></span>
    </div>
    <div class="p-5 bg-white rounded-2xl shadow-xs border border-gray-200">
      <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Pending Approvals</span>
      <span class="text-2xl font-extrabold text-amber-600 mt-1 block">
        <?= count(array_filter($secUsers, fn($u) => $u['approvalStatus'] === 'PENDING')) ?>
      </span>
    </div>
  </div>

  <!-- SEC Member Approvals Section -->
  <div class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 space-y-4">
    <div class="flex items-center justify-between border-b pb-3">
      <div>
        <h3 class="text-base font-bold text-gray-900">SEC Evaluator Roster & Approvals</h3>
        <p class="text-xs text-gray-500">Verify appointments before granting access to applicant intellectual property.</p>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-left text-xs">
        <thead class="bg-gray-50 text-gray-700 font-bold uppercase text-[10px]">
          <tr>
            <th class="p-3">Evaluator Name</th>
            <th class="p-3">Designation & Department</th>
            <th class="p-3">Institutional Email</th>
            <th class="p-3 text-center">Status</th>
            <th class="p-3 text-center">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <?php foreach ($secUsers as $secU): ?>
            <tr>
              <td class="p-3 font-bold text-gray-900"><?= htmlspecialchars($secU['name']) ?></td>
              <td class="p-3 text-gray-600"><?= htmlspecialchars($secU['designation'] ?? 'Evaluator') ?>, <?= htmlspecialchars($secU['department'] ?? 'SOET') ?></td>
              <td class="p-3 text-gray-600 font-mono"><?= htmlspecialchars($secU['email']) ?></td>
              <td class="p-3 text-center">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold <?= $secU['approvalStatus'] === 'APPROVED' ? 'bg-green-100 text-green-800' : ($secU['approvalStatus'] === 'PENDING' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                  <?= $secU['approvalStatus'] ?>
                </span>
              </td>
              <td class="p-3 text-center">
                <?php if ($secU['approvalStatus'] === 'PENDING'): ?>
                  <form method="POST" action="/admin.php" class="inline-flex gap-1.5">
                    <input type="hidden" name="userId" value="<?= $secU['id'] ?>">
                    <button type="submit" name="action" value="approve_sec" class="px-2.5 py-1 rounded-lg bg-green-600 text-white font-bold text-[11px] hover:bg-green-700">Approve</button>
                    <button type="submit" name="action" value="reject_sec" class="px-2.5 py-1 rounded-lg bg-red-100 text-red-700 font-bold text-[11px] hover:bg-red-200">Reject</button>
                  </form>
                <?php else: ?>
                  <span class="text-gray-400 text-[11px]">Active</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Applications Review Queue -->
  <div class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 space-y-4">
    <h3 class="text-base font-bold text-gray-900 border-b pb-3">Startup Applications & Grant Sanctions</h3>
    <div class="overflow-x-auto">
      <table class="w-full text-left text-xs">
        <thead class="bg-gray-50 text-gray-700 font-bold uppercase text-[10px]">
          <tr>
            <th class="p-3">Startup Team</th>
            <th class="p-3">Applicant & Program</th>
            <th class="p-3">Grant Tier</th>
            <th class="p-3 text-right">Requested</th>
            <th class="p-3 text-center">SEC Reviews</th>
            <th class="p-3 text-center">Status</th>
            <th class="p-3 text-center">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <?php foreach ($allApplications as $app): ?>
            <tr class="hover:bg-gray-50/80">
              <td class="p-3 font-bold text-gray-900"><?= htmlspecialchars($app['teamName']) ?></td>
              <td class="p-3 text-gray-600"><?= htmlspecialchars($app['enrollmentNo']) ?> (<?= htmlspecialchars($app['program']) ?>)</td>
              <td class="p-3"><span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800"><?= htmlspecialchars($app['grantType'] ?? 'PROOF') ?></span></td>
              <td class="p-3 text-right font-mono font-bold">₹<?= number_format((float)$app['requestedTotal'], 2) ?></td>
              <td class="p-3 text-center font-bold text-purple-700"><?= (int)$app['submittedCount'] ?> / 3</td>
              <td class="p-3 text-center">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold <?= $app['status'] === 'APPROVED' ? 'bg-green-100 text-green-800' : ($app['status'] === 'SUBMITTED' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700') ?>">
                  <?= $app['status'] ?>
                </span>
              </td>
              <td class="p-3 text-center">
                <a href="/admin.php?id=<?= urlencode($app['id']) ?>" class="px-3 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs inline-block">
                  Form B Sanction Console →
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Immutable Audit Logs -->
  <div class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 space-y-3">
    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider border-b pb-2">Audit Ledger (Compliance P11)</h3>
    <div class="space-y-2 text-[11px] font-mono">
      <?php foreach ($auditLogs as $log): ?>
        <div class="p-2.5 rounded-lg bg-gray-50 border flex items-center justify-between text-gray-600">
          <div>
            <strong class="text-blue-900"><?= htmlspecialchars($log['action']) ?></strong>
            <span class="text-gray-400">on <?= htmlspecialchars($log['targetType']) ?> (<?= htmlspecialchars($log['targetId'] ?? '') ?>)</span>
          </div>
          <span class="text-gray-400"><?= htmlspecialchars($log['createdAt']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
