<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Database;
use App\Config\Constants;
use App\Config\Auth;

Auth::requireRole(['SEC_MEMBER', 'ADMIN']);
$currentUser = Auth::user();
$pdo = Database::getConnection();

$appId = $_GET['id'] ?? null;

// If no application ID is selected, display the SEC Evaluation Queue
if (!$appId) {
    $qStmt = $pdo->prepare('
        SELECT a.id, a.status, a.grantType, a.requestedTotal, a.submittedAt, s.teamName, s.enrollmentNo, s.program,
               (SELECT COUNT(*) FROM `Assessment` WHERE applicationId = a.id AND status = "SUBMITTED") as submittedReviews,
               (SELECT status FROM `Assessment` WHERE applicationId = a.id AND memberId = ?) as myStatus
        FROM `Application` a
        JOIN `Startup` s ON a.startupId = s.id
        WHERE a.status IN ("SUBMITTED", "IN_REVIEW", "APPROVED", "REPRESENT")
        ORDER BY a.submittedAt DESC
    ');
    $qStmt->execute([$currentUser['id']]);
    $applications = $qStmt->fetchAll();

    $pageTitle = "SEC Evaluation Panel | IDEAPAD Seed Fund";
    require_once __DIR__ . '/../templates/header.php';
    ?>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">SEC Evaluation Panel</h1>
          <p class="text-xs text-gray-500 mt-1">Review applicant pitch decks, complete Form A assessments, and moderate requested budgets.</p>
        </div>
        <div class="flex items-center gap-2">
          <span class="px-3 py-1.5 rounded-xl bg-purple-50 text-purple-700 font-bold text-xs border border-purple-200">
            Evaluator: <?= htmlspecialchars($currentUser['name']) ?>
          </span>
        </div>
      </div>

      <!-- Application Queue Table -->
      <div class="bg-white rounded-2xl shadow-xs border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-gray-50 text-gray-700 font-bold uppercase tracking-wider text-[10px]">
              <tr>
                <th class="p-4">Startup Venture</th>
                <th class="p-4">Applicant & Program</th>
                <th class="p-4">Target Grant Tier</th>
                <th class="p-4 text-right">Requested Budget</th>
                <th class="p-4 text-center">Committee Reviews</th>
                <th class="p-4 text-center">My Assessment</th>
                <th class="p-4 text-center">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
              <?php if (empty($applications)): ?>
                <tr><td colspan="7" class="p-8 text-center text-gray-400">No applications currently awaiting committee evaluation.</td></tr>
              <?php else: foreach ($applications as $app): ?>
                <tr class="hover:bg-gray-50/80 transition-colors">
                  <td class="p-4 font-bold text-gray-900">
                    <?= htmlspecialchars($app['teamName']) ?>
                    <span class="block text-[10px] text-gray-400 font-normal">#<?= htmlspecialchars($app['id']) ?></span>
                  </td>
                  <td class="p-4 text-gray-600">
                    <span class="font-semibold text-gray-900 block"><?= htmlspecialchars($app['enrollmentNo']) ?></span>
                    <span class="text-[11px] text-gray-500"><?= htmlspecialchars($app['program']) ?></span>
                  </td>
                  <td class="p-4">
                    <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-blue-50 text-blue-800 border border-blue-200">
                      <?= htmlspecialchars(Constants::GRANT_TIERS[$app['grantType'] ?? 'PROOF']['name']) ?>
                    </span>
                  </td>
                  <td class="p-4 text-right font-mono font-bold text-gray-900">
                    ₹<?= number_format((float)$app['requestedTotal'], 2) ?>
                  </td>
                  <td class="p-4 text-center font-bold text-gray-700">
                    <?= (int)$app['submittedReviews'] ?> / 3 Submitted
                  </td>
                  <td class="p-4 text-center">
                    <?php if ($app['myStatus'] === 'SUBMITTED'): ?>
                      <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-green-100 text-green-800">Completed ✓</span>
                    <?php elseif ($app['myStatus'] === 'DRAFT'): ?>
                      <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-yellow-100 text-yellow-800">Draft In Progress</span>
                    <?php else: ?>
                      <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600">Pending</span>
                    <?php endif; ?>
                  </td>
                  <td class="p-4 text-center">
                    <a href="/sec.php?id=<?= urlencode($app['id']) ?>" class="px-3.5 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-xs inline-flex items-center gap-1">
                      Open Evaluation Split View →
                    </a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// -------------------------------------------------------------
// Split-Screen Evaluation Mode (?id=<appId>)
// -------------------------------------------------------------
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

if (!$application) {
    die("Application not found.");
}

$formData = json_decode($application['formData'], true) ?: [];

// Fetch itemized budget
$bStmt = $pdo->prepare('SELECT * FROM `BudgetItem` WHERE applicationId = ? ORDER BY header ASC, id ASC');
$bStmt->execute([$appId]);
$budgetItems = $bStmt->fetchAll();

// Fetch attached documents
$dStmt = $pdo->prepare('SELECT * FROM `Document` WHERE applicationId = ? ORDER BY createdAt ASC');
$dStmt->execute([$appId]);
$documents = $dStmt->fetchAll();

// Fetch or create member assessment (Form A)
$mStmt = $pdo->prepare('SELECT * FROM `Assessment` WHERE applicationId = ? AND memberId = ? LIMIT 1');
$mStmt->execute([$appId, $currentUser['id']]);
$myAssessment = $mStmt->fetch();

if (!$myAssessment) {
    $assId = Auth::generateId('ass');
    $preChecks = ['agreementChecked' => false, 'priorFundingChecked' => false, 'deckReviewed' => false, 'goAheadGiven' => false, 'notes' => ''];
    $statusForm = ['trl' => $formData['selfAssessment']['trl'] ?? 1, 'mrl' => $formData['selfAssessment']['mrl'] ?? 1, 'irl' => $formData['selfAssessment']['irl'] ?? 1];
    $experimentForm = ['answers' => [], 'notes' => []];
    $suggestedAmounts = [];
    foreach (array_keys(Constants::BUDGET_HEADERS) as $h) {
        $suggestedAmounts[$h] = 0;
    }

    $insAss = $pdo->prepare('INSERT INTO `Assessment` (id, applicationId, memberId, assessmentDate, preChecks, statusForm, experimentForm, recommendation, suggestedGrant, suggestedAmounts, status, createdAt) VALUES (?, ?, ?, NOW(3), ?, ?, ?, "", ?, ?, "DRAFT", NOW(3))');
    $insAss->execute([
        $assId,
        $appId,
        $currentUser['id'],
        json_encode($preChecks, JSON_UNESCAPED_UNICODE),
        json_encode($statusForm, JSON_UNESCAPED_UNICODE),
        json_encode($experimentForm, JSON_UNESCAPED_UNICODE),
        $application['grantType'] ?? 'PROOF',
        json_encode($suggestedAmounts, JSON_UNESCAPED_UNICODE)
    ]);

    $mStmt->execute([$appId, $currentUser['id']]);
    $myAssessment = $mStmt->fetch();
}

$assData = [
    'preChecks' => json_decode($myAssessment['preChecks'] ?? '{}', true) ?: [],
    'statusForm' => json_decode($myAssessment['statusForm'] ?? '{}', true) ?: [],
    'experimentForm' => json_decode($myAssessment['experimentForm'] ?? '{}', true) ?: [],
    'suggestedAmounts' => json_decode($myAssessment['suggestedAmounts'] ?? '{}', true) ?: [],
    'recommendation' => $myAssessment['recommendation'] ?? '',
    'suggestedGrant' => $myAssessment['suggestedGrant'] ?? ($application['grantType'] ?? 'PROOF'),
    'status' => $myAssessment['status'] ?? 'DRAFT'
];

// Fetch or initialize shared milestone container (Policy P8.2)
$msStmt = $pdo->prepare('SELECT * FROM `MilestoneAssessment` WHERE applicationId = ? LIMIT 1');
$msStmt->execute([$appId]);
$sharedMilestone = $msStmt->fetch();

if (!$sharedMilestone) {
    $msId = Auth::generateId('ms');
    $insMs = $pdo->prepare('INSERT INTO `MilestoneAssessment` (id, applicationId, ownerId, track, status, updatedAt) VALUES (?, ?, ?, "PROTOTYPING", "DRAFT", NOW(3))');
    $insMs->execute([$msId, $appId, $currentUser['id']]);

    // Populate default items from Track A
    $itemIns = $pdo->prepare('INSERT INTO `MilestoneItem` (id, milestoneId, name, state, updatedAt) VALUES (?, ?, ?, "PENDING", NOW(3))');
    foreach (Constants::MILESTONES_TRACK_A as $mName) {
        $itemIns->execute([Auth::generateId('mi'), $msId, $mName]);
    }

    $msStmt->execute([$appId]);
    $sharedMilestone = $msStmt->fetch();
}

// Fetch shared milestone items
$miStmt = $pdo->prepare('SELECT * FROM `MilestoneItem` WHERE milestoneId = ? ORDER BY id ASC');
$miStmt->execute([$sharedMilestone['id']]);
$milestoneItems = $miStmt->fetchAll();

// -------------------------------------------------------------
// POST Request Handler for Form A and Shared Milestones
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Partial Form A Section Save (AJAX or normal POST)
    if ($action === 'save_form_a_draft' || isset($_POST['form_a_tab'])) {
        $preChecks = [
            'agreementChecked' => !empty($_POST['agreementChecked']),
            'priorFundingChecked' => !empty($_POST['priorFundingChecked']),
            'deckReviewed' => !empty($_POST['deckReviewed']),
            'goAheadGiven' => !empty($_POST['goAheadGiven']),
            'notes' => trim((string)($_POST['sop_notes'] ?? ''))
        ];

        $statusForm = [
            'trl' => (int)($_POST['eval_trl'] ?? 1),
            'mrl' => (int)($_POST['eval_mrl'] ?? 1),
            'irl' => (int)($_POST['eval_irl'] ?? 1)
        ];

        // 12 questions
        $answers = [];
        $notes = [];
        for ($q = 1; $q <= 12; $q++) {
            if (isset($_POST["q_$q"])) {
                $answers[$q] = ($_POST["q_$q"] === '1');
            }
            if (isset($_POST["q_note_$q"])) {
                $notes[$q] = trim((string)$_POST["q_note_$q"]);
            }
        }
        $experimentForm = ['answers' => $answers, 'notes' => $notes];

        // Suggested budget
        $suggestedAmounts = [];
        $calcTotal = 0.0;
        foreach (array_keys(Constants::BUDGET_HEADERS) as $hKey) {
            $amt = (float)($_POST["sug_$hKey"] ?? 0);
            $suggestedAmounts[$hKey] = $amt;
            $calcTotal += $amt;
        }

        $recommendation = trim((string)($_POST['eval_recommendation'] ?? ''));
        $suggestedGrant = $_POST['eval_suggestedGrant'] ?? 'PROOF';

        $upd = $pdo->prepare('
            UPDATE `Assessment`
            SET preChecks = ?, statusForm = ?, experimentForm = ?, suggestedAmounts = ?, recommendation = ?, suggestedGrant = ?, assessmentDate = NOW(3)
            WHERE id = ?
        ');
        $upd->execute([
            json_encode($preChecks, JSON_UNESCAPED_UNICODE),
            json_encode($statusForm, JSON_UNESCAPED_UNICODE),
            json_encode($experimentForm, JSON_UNESCAPED_UNICODE),
            json_encode($suggestedAmounts, JSON_UNESCAPED_UNICODE),
            $recommendation,
            $suggestedGrant,
            $myAssessment['id']
        ]);

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'total' => $calcTotal]);
            exit;
        }
        header("Location: /sec.php?id=" . urlencode($appId));
        exit;
    }

    // 2. Final Submit Form A
    if ($action === 'submit_form_a') {
        $sub = $pdo->prepare('UPDATE `Assessment` SET status = "SUBMITTED", submittedAt = NOW(3) WHERE id = ?');
        $sub->execute([$myAssessment['id']]);

        // If all 3 submitted or at least 1, mark application IN_REVIEW
        $pdo->prepare('UPDATE `Application` SET status = "IN_REVIEW" WHERE id = ?')->execute([$appId]);
        Auth::audit('SUBMIT_FORM_A', 'Assessment', $myAssessment['id'], ['applicationId' => $appId]);

        header("Location: /sec.php?id=" . urlencode($appId));
        exit;
    }

    // 3. Update Shared Milestone State (Collaborative)
    if ($action === 'update_milestone_item') {
        $itemId = $_POST['itemId'] ?? '';
        $state = $_POST['state'] ?? 'PENDING';
        $remark = trim((string)($_POST['remark'] ?? ''));

        $uItem = $pdo->prepare('UPDATE `MilestoneItem` SET state = ?, ownerRemark = ?, updatedAt = NOW(3) WHERE id = ?');
        $uItem->execute([$state, $remark, $itemId]);

        // Record edit in audit log
        $pdo->prepare('INSERT INTO `MilestoneEditLog` (id, milestoneId, memberId, field, oldValue, newValue, at) VALUES (?, ?, ?, "state", "", ?, NOW(3))')
            ->execute([Auth::generateId('mel'), $sharedMilestone['id'], $currentUser['id'], $state . ': ' . $remark]);

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

$pageTitle = "SEC Evaluation Split View | " . htmlspecialchars($application['teamName']);
require_once __DIR__ . '/../templates/header.php';
?>

<!-- Dual-Pane Split Evaluation Screen -->
<div class="flex-1 flex flex-col h-[calc(100vh-4rem)] overflow-hidden bg-gray-100">

  <!-- Sub-Header: Application Summary & Split View Controls -->
  <div class="no-print bg-white border-b border-gray-200 px-4 py-2.5 flex items-center justify-between text-xs shrink-0 shadow-xs">
    <div class="flex items-center gap-3">
      <a href="/sec.php" class="p-1.5 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-100 font-bold flex items-center gap-1">
        ← Queue
      </a>
      <span class="h-4 w-px bg-gray-300"></span>
      <span class="font-extrabold text-gray-900 text-sm"><?= htmlspecialchars($application['teamName']) ?></span>
      <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800">
        <?= htmlspecialchars(Constants::GRANT_TIERS[$application['grantType'] ?? 'PROOF']['name']) ?>
      </span>
      <span class="text-gray-500 hidden sm:inline">Requested: <strong>₹<?= number_format((float)$application['requestedTotal'], 2) ?></strong></span>
    </div>

    <!-- Mobile Pane Toggle Buttons -->
    <div class="flex lg:hidden items-center gap-1 bg-gray-100 p-1 rounded-xl">
      <button type="button" id="mobile-toggle-app" class="px-3 py-1 rounded-lg text-xs font-bold bg-blue-600 text-white shadow-xs">
        Startup Proposal
      </button>
      <button type="button" id="mobile-toggle-form" class="px-3 py-1 rounded-lg text-xs font-bold text-gray-700">
        Form A Evaluation
      </button>
    </div>

    <!-- Printable & Status Badges -->
    <div class="flex items-center gap-2">
      <a href="/reports.php?id=<?= urlencode($appId) ?>&type=form-a" target="_blank" class="px-3 py-1.5 rounded-xl bg-purple-50 text-purple-700 border border-purple-200 font-semibold text-xs hover:bg-purple-100 flex items-center gap-1">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
        Printable Form A
      </a>
      <span class="px-2.5 py-1 rounded-xl text-xs font-bold <?= $assData['status'] === 'SUBMITTED' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
        <?= $assData['status'] === 'SUBMITTED' ? 'Evaluation Submitted ✓' : 'Draft Mode' ?>
      </span>
    </div>
  </div>

  <!-- Main Split-Screen Workspace -->
  <div id="split-container" class="flex-1 flex overflow-hidden relative">

    <!-- LEFT PANE: READ-ONLY STARTUP APPLICATION DOSSIER (Scrolls independently) -->
    <div id="split-left-pane" class="w-full lg:w-1/2 h-full overflow-y-auto bg-gray-50 p-6 space-y-6 border-r border-gray-200">
      
      <!-- Anchor: SOP & Basic Details -->
      <div id="dossier-sop" class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 space-y-4">
        <div class="flex items-center justify-between border-b pb-3">
          <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Startup Profile & Venture Details</h3>
          <span class="text-[11px] text-gray-500 font-mono">App ID: <?= htmlspecialchars($appId) ?></span>
        </div>
        <div class="grid grid-cols-2 gap-3 text-xs">
          <div><span class="text-gray-500">Applicant:</span> <strong><?= htmlspecialchars($application['founderName']) ?></strong></div>
          <div><span class="text-gray-500">Team Name:</span> <strong><?= htmlspecialchars($application['teamName']) ?></strong></div>
          <div><span class="text-gray-500">Enrollment No:</span> <strong><?= htmlspecialchars($application['enrollmentNo']) ?></strong></div>
          <div><span class="text-gray-500">Program:</span> <strong><?= htmlspecialchars($application['program']) ?></strong></div>
          <div><span class="text-gray-500">Contact:</span> <strong><?= htmlspecialchars($application['phone']) ?></strong></div>
          <div><span class="text-gray-500">Email:</span> <strong><?= htmlspecialchars($application['founderEmail']) ?></strong></div>
        </div>

        <div class="space-y-3 pt-3 border-t text-xs">
          <div>
            <span class="font-bold text-gray-800 block mb-1">Problem Statement:</span>
            <p class="p-3 rounded-xl bg-gray-50 text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars($formData['problemStatement'] ?? '')) ?></p>
          </div>
          <div>
            <span class="font-bold text-gray-800 block mb-1">Proposed Solution:</span>
            <p class="p-3 rounded-xl bg-gray-50 text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars($formData['proposedSolution'] ?? '')) ?></p>
          </div>
          <div>
            <span class="font-bold text-gray-800 block mb-1">Effort & Traction:</span>
            <p class="p-3 rounded-xl bg-gray-50 text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars($formData['effortTraction'] ?? '')) ?></p>
          </div>
        </div>
      </div>

      <!-- Anchor: Self-Assessment Levels -->
      <div id="dossier-levels" class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 space-y-3">
        <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider border-b pb-2">Startup Self-Assessment Scales</h3>
        <div class="grid grid-cols-3 gap-3 text-center text-xs">
          <div class="p-3 rounded-xl bg-blue-50 border border-blue-200">
            <span class="block text-gray-500 text-[10px] uppercase font-bold">Claimed TRL</span>
            <span class="text-xl font-extrabold text-blue-700">TRL <?= htmlspecialchars((string)($formData['selfAssessment']['trl'] ?? 1)) ?></span>
          </div>
          <div class="p-3 rounded-xl bg-purple-50 border border-purple-200">
            <span class="block text-gray-500 text-[10px] uppercase font-bold">Claimed MRL</span>
            <span class="text-xl font-extrabold text-purple-700">MRL <?= htmlspecialchars((string)($formData['selfAssessment']['mrl'] ?? 1)) ?></span>
          </div>
          <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200">
            <span class="block text-gray-500 text-[10px] uppercase font-bold">Claimed IRL</span>
            <span class="text-xl font-extrabold text-emerald-700">IRL <?= htmlspecialchars((string)($formData['selfAssessment']['irl'] ?? 1)) ?></span>
          </div>
        </div>
      </div>

      <!-- Anchor: 10 Experiment Headings -->
      <div id="dossier-experiment" class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 space-y-4">
        <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider border-b pb-2">10 Structured Experiment Sections</h3>
        <div class="space-y-3 text-xs">
          <?php foreach (Constants::EXPERIMENT_HEADINGS as $eIdx => $eVal): ?>
            <div class="p-3.5 rounded-xl bg-gray-50/80 border border-gray-200 space-y-1">
              <span class="font-bold text-blue-900 block"><?= $eIdx ?>. <?= htmlspecialchars($eVal['title']) ?></span>
              <p class="text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars($formData['experiments'][$eIdx] ?? 'Not answered.')) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Anchor: Budget Table & Justifications -->
      <div id="dossier-grant" class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 space-y-4">
        <div class="flex items-center justify-between border-b pb-2">
          <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Itemized Budget Table & Justifications</h3>
          <span class="font-bold text-blue-700 text-xs">Total: ₹<?= number_format((float)$application['requestedTotal'], 2) ?></span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-[11px]">
            <thead class="bg-gray-100 text-gray-700 font-bold uppercase text-[9px]">
              <tr>
                <th class="p-2">Category</th>
                <th class="p-2">Item Description</th>
                <th class="p-2 text-right">Cost × Qty</th>
                <th class="p-2 text-right">Total (₹)</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <?php foreach ($budgetItems as $b): ?>
                <tr>
                  <td class="p-2 font-bold text-gray-700"><?= htmlspecialchars($b['header']) ?></td>
                  <td class="p-2">
                    <span class="font-semibold text-gray-900"><?= htmlspecialchars($b['name']) ?></span>
                    <?php if (!empty($b['justification'])): ?>
                      <p class="text-[10px] text-gray-500 italic mt-0.5"><?= htmlspecialchars($b['justification']) ?></p>
                    <?php endif; ?>
                  </td>
                  <td class="p-2 text-right font-mono">₹<?= number_format((float)$b['unitCost']) ?> × <?= (int)$b['qty'] ?></td>
                  <td class="p-2 text-right font-mono font-bold text-gray-900">₹<?= number_format((float)$b['total'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Anchor: Documents -->
      <div class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 space-y-3">
        <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider border-b pb-2">Attached Documents</h3>
        <div class="space-y-2 text-xs">
          <?php foreach ($documents as $d): ?>
            <div class="flex items-center justify-between p-2.5 rounded-lg bg-gray-50 border">
              <span class="font-bold text-blue-800"><?= htmlspecialchars($d['type']) ?></span>
              <span class="text-gray-600 truncate max-w-[200px]"><?= htmlspecialchars($d['memberLabel'] ?? $d['path']) ?></span>
              <a href="/uploads/<?= urlencode($d['path']) ?>" target="_blank" class="text-blue-600 hover:underline font-bold">View Document ↗</a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>

    <!-- DRAGGABLE DIVIDER (Desktop only) -->
    <div id="split-divider" class="hidden lg:flex w-2 shrink-0 bg-gray-200 hover:bg-blue-400 split-divider items-center justify-center transition-colors">
      <div class="w-0.5 h-8 bg-gray-400 rounded"></div>
    </div>

    <!-- RIGHT PANE: INTERACTIVE SEC FORM A SCORING -->
    <div id="split-right-pane" class="hidden lg:block w-full lg:w-1/2 h-full overflow-y-auto bg-white p-6 space-y-6">

      <!-- Form A Navigation Tabs -->
      <div class="flex items-center gap-1 border-b border-gray-200 pb-3 overflow-x-auto scrollbar-none text-xs font-bold">
        <button type="button" onclick="switchFormATab('sop')" id="tab-btn-sop" class="form-a-tab px-3 py-2 rounded-xl bg-purple-600 text-white">
          1. SOP Pre-checks
        </button>
        <button type="button" onclick="switchFormATab('levels')" id="tab-btn-levels" class="form-a-tab px-3 py-2 rounded-xl bg-gray-100 text-gray-700">
          2. Status Levels
        </button>
        <button type="button" onclick="switchFormATab('milestones')" id="tab-btn-milestones" class="form-a-tab px-3 py-2 rounded-xl bg-gray-100 text-gray-700">
          3. Shared Milestones
        </button>
        <button type="button" onclick="switchFormATab('experiment')" id="tab-btn-experiment" class="form-a-tab px-3 py-2 rounded-xl bg-gray-100 text-gray-700 flex items-center gap-1">
          4. 12 Questions
          <span id="questions-count-badge" class="px-1.5 py-0.2 rounded-full text-[10px] bg-yellow-100 text-yellow-800">
            <?= count($assData['experimentForm']['answers'] ?? []) ?>/12
          </span>
        </button>
        <button type="button" onclick="switchFormATab('grant')" id="tab-btn-grant" class="form-a-tab px-3 py-2 rounded-xl bg-gray-100 text-gray-700">
          5. Grant & Budget
        </button>
      </div>

      <form id="sec-form-a" method="POST" action="/sec.php?id=<?= urlencode($appId) ?>" class="space-y-6">
        <input type="hidden" name="action" value="save_form_a_draft">
        <input type="hidden" name="form_a_tab" id="formATabInput" value="sop">

        <!-- TAB 1: SOP PRE-CHECKS (Policy P4.1–6) -->
        <div id="sec-tab-sop" class="sec-tab-pane space-y-5">
          <div class="border-b pb-3">
            <h3 class="text-base font-bold text-gray-900">SOP Pre-Checks & Compliance</h3>
            <p class="text-xs text-gray-500 mt-0.5">Verify paperwork compliance and prior grant utilization before hearing the pitch.</p>
          </div>

          <div class="space-y-3">
            <label class="flex items-start gap-3 p-3.5 rounded-xl border border-gray-200 bg-gray-50 cursor-pointer hover:bg-gray-100/80">
              <input type="checkbox" name="agreementChecked" value="1" <?= !empty($assData['preChecks']['agreementChecked']) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded text-purple-600 focus:ring-purple-500">
              <div class="text-xs">
                <span class="font-bold text-gray-900 block">1. Terms & Facility Agreement Paperwork</span>
                <span class="text-gray-500">Startup agrees to IDEAPAD terms, completed paperwork, PAN, Aadhaar, and photos provided.</span>
              </div>
            </label>

            <label class="flex items-start gap-3 p-3.5 rounded-xl border border-gray-200 bg-gray-50 cursor-pointer hover:bg-gray-100/80">
              <input type="checkbox" name="priorFundingChecked" value="1" <?= !empty($assData['preChecks']['priorFundingChecked']) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded text-purple-600 focus:ring-purple-500">
              <div class="text-xs">
                <span class="font-bold text-gray-900 block">2. Prior Grant Utilization Compliance</span>
                <span class="text-gray-500">Checked prior funding compliance, bills submitted, and effective utilization verified.</span>
              </div>
            </label>

            <label class="flex items-start gap-3 p-3.5 rounded-xl border border-gray-200 bg-gray-50 cursor-pointer hover:bg-gray-100/80">
              <input type="checkbox" name="deckReviewed" value="1" <?= !empty($assData['preChecks']['deckReviewed']) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded text-purple-600 focus:ring-purple-500">
              <div class="text-xs">
                <span class="font-bold text-gray-900 block">3. Pitch Deck Review (2 Days Prior)</span>
                <span class="text-gray-500">Evaluator has gone through the pitch deck submitted prior to the presentation.</span>
              </div>
            </label>

            <label class="flex items-start gap-3 p-3.5 rounded-xl border border-gray-200 bg-gray-50 cursor-pointer hover:bg-gray-100/80">
              <input type="checkbox" name="goAheadGiven" value="1" <?= !empty($assData['preChecks']['goAheadGiven']) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded text-purple-600 focus:ring-purple-500">
              <div class="text-xs">
                <span class="font-bold text-gray-900 block">4. Go-Ahead for Project Presentation</span>
                <span class="text-gray-500">Startup is given permission to present current project state and fund requirements.</span>
              </div>
            </label>
          </div>

          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Pre-Check Observational Notes</label>
            <textarea name="sop_notes" rows="2" class="w-full p-2.5 bg-gray-50 border rounded-xl text-xs" placeholder="Notes regarding paperwork or prior funding..."><?= htmlspecialchars($assData['preChecks']['notes'] ?? '') ?></textarea>
          </div>

          <!-- Bottom Save & Next -->
          <div class="pt-4 border-t flex justify-end">
            <button type="button" onclick="saveAndNextFormATab('levels')" class="px-4 py-2 bg-purple-600 text-white font-bold text-xs rounded-xl hover:bg-purple-700 shadow-xs">
              Save & Next: Status Levels →
            </button>
          </div>
        </div>

        <!-- TAB 2: STATUS LEVELS (TRL/MRL/IRL 1–9) -->
        <div id="sec-tab-levels" class="sec-tab-pane hidden space-y-5">
          <div class="border-b pb-3">
            <h3 class="text-base font-bold text-gray-900">Current Status Assessment (Policy P8.1)</h3>
            <p class="text-xs text-gray-500 mt-0.5">Mark achieved level for TRL, MRL, and IRL based on the pitch presentation.</p>
          </div>

          <!-- TRL 1-9 -->
          <div class="space-y-2">
            <label class="block text-xs font-bold text-blue-700 uppercase tracking-wider">Technology Readiness Level (TRL)</label>
            <select name="eval_trl" class="w-full p-2.5 border rounded-xl text-xs bg-gray-50">
              <?php foreach (Constants::TRL_LEVELS as $lvl => $desc): ?>
                <option value="<?= $lvl ?>" <?= ($assData['statusForm']['trl'] ?? 1) == $lvl ? 'selected' : '' ?>>
                  TRL <?= $lvl ?>: <?= htmlspecialchars($desc) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- MRL 1-9 -->
          <div class="space-y-2">
            <label class="block text-xs font-bold text-purple-700 uppercase tracking-wider">Manufacturing Readiness Level (MRL)</label>
            <select name="eval_mrl" class="w-full p-2.5 border rounded-xl text-xs bg-gray-50">
              <?php foreach (Constants::MRL_LEVELS as $lvl => $desc): ?>
                <option value="<?= $lvl ?>" <?= ($assData['statusForm']['mrl'] ?? 1) == $lvl ? 'selected' : '' ?>>
                  MRL <?= $lvl ?>: <?= htmlspecialchars($desc) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- IRL 1-9 -->
          <div class="space-y-2">
            <label class="block text-xs font-bold text-emerald-700 uppercase tracking-wider">Investment Readiness Level (IRL)</label>
            <select name="eval_irl" class="w-full p-2.5 border rounded-xl text-xs bg-gray-50">
              <?php foreach (Constants::IRL_LEVELS as $lvl => $desc): ?>
                <option value="<?= $lvl ?>" <?= ($assData['statusForm']['irl'] ?? 1) == $lvl ? 'selected' : '' ?>>
                  IRL <?= $lvl ?>: <?= htmlspecialchars($desc) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Bottom Controls -->
          <div class="pt-4 border-t flex justify-between">
            <button type="button" onclick="switchFormATab('sop')" class="px-4 py-2 bg-gray-100 text-gray-700 font-semibold text-xs rounded-xl hover:bg-gray-200">← Back</button>
            <button type="button" onclick="saveAndNextFormATab('milestones')" class="px-4 py-2 bg-purple-600 text-white font-bold text-xs rounded-xl hover:bg-purple-700 shadow-xs">Save & Next: Milestones →</button>
          </div>
        </div>

        <!-- TAB 3: SHARED COLLABORATIVE MILESTONES (P8.2) -->
        <div id="sec-tab-milestones" class="sec-tab-pane hidden space-y-5">
          <div class="border-b pb-3">
            <h3 class="text-base font-bold text-gray-900">Shared Milestone Assessment</h3>
            <p class="text-xs text-gray-500 mt-0.5">One shared form between committee members. Changes update the audit ledger.</p>
          </div>

          <div class="space-y-3">
            <?php foreach ($milestoneItems as $mItem): ?>
              <div class="p-3.5 rounded-xl border border-gray-200 bg-gray-50 space-y-2 text-xs">
                <div class="flex items-center justify-between">
                  <span class="font-bold text-gray-900"><?= htmlspecialchars($mItem['name']) ?></span>
                  <select onchange="updateMilestoneItem('<?= $mItem['id'] ?>', this.value, this.closest('div').nextElementSibling.value)" class="p-1 border rounded-lg text-xs bg-white font-bold">
                    <option value="PENDING" <?= $mItem['state'] === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                    <option value="COMPLETED" <?= $mItem['state'] === 'COMPLETED' ? 'selected' : '' ?>>Completed ✓</option>
                  </select>
                </div>
                <input 
                  type="text" 
                  value="<?= htmlspecialchars($mItem['ownerRemark'] ?? '') ?>" 
                  placeholder="Committee remarks..."
                  onblur="updateMilestoneItem('<?= $mItem['id'] ?>', this.previousElementSibling.querySelector('select').value, this.value)"
                  class="w-full p-1.5 border rounded-lg text-xs bg-white"
                />
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Bottom Controls -->
          <div class="pt-4 border-t flex justify-between">
            <button type="button" onclick="switchFormATab('levels')" class="px-4 py-2 bg-gray-100 text-gray-700 font-semibold text-xs rounded-xl hover:bg-gray-200">← Back</button>
            <button type="button" onclick="saveAndNextFormATab('experiment')" class="px-4 py-2 bg-purple-600 text-white font-bold text-xs rounded-xl hover:bg-purple-700 shadow-xs">Save & Next: 12 Questions →</button>
          </div>
        </div>

        <!-- TAB 4: 12 RUBRIC QUESTIONS & DECISION GUIDE (P8.3) -->
        <div id="sec-tab-experiment" class="sec-tab-pane hidden space-y-5">
          <div class="border-b pb-3 flex items-center justify-between">
            <div>
              <h3 class="text-base font-bold text-gray-900">TRL 2 / TRL 3 Experiment Assessment</h3>
              <p class="text-xs text-gray-500 mt-0.5">12 Rubric Questions (Yes / No per member) with reference notes.</p>
            </div>
            <!-- Live Decision Guide Badge -->
            <div id="decision-guide-badge" class="px-3 py-1 rounded-xl text-xs font-bold bg-gray-100 text-gray-700">
              Scoring Guide Active
            </div>
          </div>

          <div class="space-y-6">
            <?php foreach (Constants::RUBRIC_QUESTIONS as $catKey => $cat): ?>
              <div class="space-y-3">
                <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider bg-gray-100 p-2 rounded-lg"><?= htmlspecialchars($cat['category']) ?></h4>
                <?php foreach ($cat['questions'] as $qNum => $qText): ?>
                  <div class="p-3 rounded-xl border border-gray-200 bg-gray-50 space-y-2 text-xs">
                    <div class="flex items-center justify-between gap-3">
                      <span class="font-medium text-gray-800"><?= $qNum ?>. <?= htmlspecialchars($qText) ?></span>
                      <div class="flex items-center gap-2 shrink-0">
                        <label class="cursor-pointer inline-flex items-center gap-1 font-bold">
                          <input type="radio" name="q_<?= $qNum ?>" value="1" <?= ($assData['experimentForm']['answers'][$qNum] ?? null) === true ? 'checked' : '' ?> onchange="updateDecisionGuide()" class="text-green-600 focus:ring-green-500"> Yes
                        </label>
                        <label class="cursor-pointer inline-flex items-center gap-1 font-bold">
                          <input type="radio" name="q_<?= $qNum ?>" value="0" <?= ($assData['experimentForm']['answers'][$qNum] ?? null) === false ? 'checked' : '' ?> onchange="updateDecisionGuide()" class="text-red-600 focus:ring-red-500"> No
                        </label>
                      </div>
                    </div>
                    <input 
                      type="text" 
                      name="q_note_<?= $qNum ?>" 
                      value="<?= htmlspecialchars($assData['experimentForm']['notes'][$qNum] ?? '') ?>" 
                      placeholder="Reference note for Q<?= $qNum ?> (e.g. 'hypothesis is clear')..."
                      class="w-full p-1.5 border rounded-lg text-xs bg-white"
                    />
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Bottom Controls -->
          <div class="pt-4 border-t flex justify-between">
            <button type="button" onclick="switchFormATab('milestones')" class="px-4 py-2 bg-gray-100 text-gray-700 font-semibold text-xs rounded-xl hover:bg-gray-200">← Back</button>
            <button type="button" onclick="saveAndNextFormATab('grant')" class="px-4 py-2 bg-purple-600 text-white font-bold text-xs rounded-xl hover:bg-purple-700 shadow-xs">Save & Next: Budget Moderation →</button>
          </div>
        </div>

        <!-- TAB 5: GRANT EVALUATION & BUDGET MODERATION (P8.5–6) -->
        <div id="sec-tab-grant" class="sec-tab-pane hidden space-y-5">
          <div class="border-b pb-3">
            <h3 class="text-base font-bold text-gray-900">Grant Evaluation & Budget Suggestion</h3>
            <p class="text-xs text-gray-500 mt-0.5">Final recommendation note and itemized budget moderation under the 9 headers.</p>
          </div>

          <!-- Evaluation Tier Choice -->
          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">Grant Evaluation Decision (Policy P8.5)</label>
            <div class="grid grid-cols-2 gap-3 text-xs">
              <?php foreach (['VALIDATION' => 'Validation Grant', 'PROOF' => 'Proof Grant', 'SCALING' => 'Scaling Grant', 'REJECTED' => 'None / Application Rejected'] as $dKey => $dLabel): ?>
                <label class="cursor-pointer border rounded-xl p-3 flex items-center gap-2 <?= ($assData['suggestedGrant'] === $dKey) ? 'border-purple-600 bg-purple-50 ring-2 ring-purple-600 font-bold' : 'border-gray-200 hover:border-gray-300' ?>">
                  <input type="radio" name="eval_suggestedGrant" value="<?= $dKey ?>" <?= ($assData['suggestedGrant'] === $dKey) ? 'checked' : '' ?> class="text-purple-600 focus:ring-purple-500">
                  <span><?= htmlspecialchars($dLabel) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Suggested Budget Moderation Grid -->
          <div class="space-y-3">
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Suggested Budget Allocation (Annexure 1)</label>
            <div class="space-y-2 border rounded-xl p-3 bg-gray-50">
              <?php foreach (Constants::BUDGET_HEADERS as $bK => $bV): ?>
                <div class="flex items-center justify-between gap-3 text-xs">
                  <span class="text-gray-700 truncate max-w-[220px]" title="<?= htmlspecialchars($bV['name']) ?>"><?= htmlspecialchars($bV['name']) ?></span>
                  <div class="flex items-center gap-1 w-32">
                    <span class="text-gray-400 font-mono">₹</span>
                    <input 
                      type="number" 
                      step="1"
                      name="sug_<?= $bK ?>" 
                      value="<?= htmlspecialchars((string)($assData['suggestedAmounts'][$bK] ?? 0)) ?>" 
                      oninput="recalculateSecTotal()"
                      class="w-full text-right font-mono p-1 border rounded bg-white sec-sug-calc"
                    />
                  </div>
                </div>
              <?php endforeach; ?>

              <div class="pt-2 border-t flex items-center justify-between font-bold text-xs">
                <span>Total Suggested Grant:</span>
                <span class="font-mono text-purple-700 text-sm" id="sec-total-display">₹0.00</span>
              </div>
            </div>
          </div>

          <!-- Evaluator Recommendation Note -->
          <div>
            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">Recommendation Note (Policy P8.4)</label>
            <textarea 
              name="eval_recommendation" 
              rows="3" 
              placeholder="e.g. The team is working diligently to create product range. We recommend purchase of machinery and accessories..."
              class="w-full p-2.5 bg-gray-50 border rounded-xl text-xs"
            ><?= htmlspecialchars($assData['recommendation']) ?></textarea>
          </div>

          <!-- Bottom Submission Actions -->
          <div class="pt-4 border-t flex justify-between items-center">
            <button type="button" onclick="switchFormATab('experiment')" class="px-4 py-2 bg-gray-100 text-gray-700 font-semibold text-xs rounded-xl hover:bg-gray-200">← Back</button>
            <div class="flex items-center gap-2">
              <button type="button" onclick="saveFormADraft(this)" class="px-4 py-2 bg-gray-200 text-gray-800 font-bold text-xs rounded-xl hover:bg-gray-300">
                Save Draft
              </button>
              <button 
                type="submit" 
                onclick="document.querySelector('input[name=action]').value = 'submit_form_a'"
                class="px-5 py-2.5 bg-green-600 text-white font-extrabold text-xs rounded-xl hover:bg-green-700 shadow-md"
              >
                Submit Form A Evaluation ✓
              </button>
            </div>
          </div>
        </div>

      </form>
    </div>

  </div>

</div>

<!-- Client Scripts for Split View & Synchronized Auto-Scroll -->
<script src="/js/split-view.js"></script>
<script>
function switchFormATab(tabName) {
  document.querySelectorAll('.form-a-tab').forEach(btn => {
    btn.classList.remove('bg-purple-600', 'text-white');
    btn.classList.add('bg-gray-100', 'text-gray-700');
  });
  const activeBtn = document.getElementById('tab-btn-' + tabName);
  if (activeBtn) {
    activeBtn.classList.add('bg-purple-600', 'text-white');
    activeBtn.classList.remove('bg-gray-100', 'text-gray-700');
  }

  document.querySelectorAll('.sec-tab-pane').forEach(p => p.classList.add('hidden'));
  const activePane = document.getElementById('sec-tab-' + tabName);
  if (activePane) activePane.classList.remove('hidden');

  document.getElementById('formATabInput').value = tabName;

  // Trigger synchronized auto-scrolling on the left application pane
  if (window.syncLeftPaneToSection) {
    window.syncLeftPaneToSection(tabName);
  }
}

async function saveAndNextFormATab(nextTab) {
  await saveFormADraft(null);
  switchFormATab(nextTab);
}

async function saveFormADraft(btn) {
  const formEl = document.getElementById('sec-form-a');
  const fd = new FormData(formEl);
  fd.set('action', 'save_form_a_draft');

  if (btn) btn.innerText = 'Saving...';
  try {
    const res = await fetch('/sec.php?id=<?= urlencode($appId) ?>', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    const data = await res.json();
    if (data.success) {
      if (btn) btn.innerText = 'Saved ✓';
      setTimeout(() => { if (btn) btn.innerText = 'Save Draft'; }, 1500);
    }
  } catch (e) {
    if (btn) btn.innerText = 'Error';
  }
}

function updateDecisionGuide() {
  const noCount = document.querySelectorAll('input[type="radio"][name^="q_"][value="0"]:checked').length;
  const badge = document.getElementById('decision-guide-badge');
  if (noCount === 0) {
    badge.className = 'px-3 py-1 rounded-xl text-xs font-bold bg-green-100 text-green-800';
    badge.innerText = 'Mostly YES → Recommend Funding (TRL-3 likely)';
  } else if (noCount < 3) {
    badge.className = 'px-3 py-1 rounded-xl text-xs font-bold bg-blue-100 text-blue-800';
    badge.innerText = `Minor Issues (${noCount} NO) → Recommend Funding`;
  } else {
    badge.className = 'px-3 py-1 rounded-xl text-xs font-bold bg-red-100 text-red-800';
    badge.innerText = `Multiple NO (${noCount}) → Revision Required before Approval`;
  }
}

function recalculateSecTotal() {
  let total = 0;
  document.querySelectorAll('.sec-sug-calc').forEach(inp => {
    total += parseFloat(inp.value || '0');
  });
  document.getElementById('sec-total-display').innerText = '₹' + total.toLocaleString('en-IN', { minimumFractionDigits: 2 });
}
recalculateSecTotal();
updateDecisionGuide();

async function updateMilestoneItem(itemId, state, remark) {
  const fd = new FormData();
  fd.append('action', 'update_milestone_item');
  fd.append('itemId', itemId);
  fd.append('state', state);
  fd.append('remark', remark);

  await fetch('/sec.php?id=<?= urlencode($appId) ?>', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: fd
  });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
