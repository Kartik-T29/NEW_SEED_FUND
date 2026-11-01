<?php
/**
 * IDEAPAD Seed Fund Portal - Startup Grant Application Multi-Section Wizard
 * 100% Pure Server-Rendered PHP with Tailwind CSS, Save & Next, and Client Image Compression
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Database;
use App\Config\Auth;
use App\Config\Constants;

Auth::requireRole('STARTUP');

$user = Auth::user();
$pdo = Database::getConnection();

// Fetch Startup profile
$sStmt = $pdo->prepare('SELECT * FROM `Startup` WHERE userId = ? LIMIT 1');
$sStmt->execute([$user['id']]);
$startup = $sStmt->fetch();

if (!$startup) {
    die("Startup profile not found. Please contact an administrator.");
}

// Fetch active or latest application
$aStmt = $pdo->prepare('SELECT * FROM `Application` WHERE startupId = ? ORDER BY version DESC, createdAt DESC LIMIT 1');
$aStmt->execute([$startup['id']]);
$application = $aStmt->fetch();

if (!$application) {
    // Generate initial blank draft
    $appId = Auth::generateId('app');
    $initialFormData = [
        'applicantName' => $user['name'],
        'teamName' => $startup['teamName'],
        'enrollmentNo' => $startup['enrollmentNo'],
        'program' => $startup['program'],
        'phone' => $startup['phone'],
        'email' => $user['email'],
        'problemStatement' => '',
        'proposedSolution' => '',
        'effortTraction' => '',
        'experiments' => [],
        'selfAssessment' => ['trl' => 1, 'mrl' => 1, 'irl' => 1, 'track' => 'PROTOTYPING', 'milestones' => []],
        'justifications' => [],
        'agreementAccepted' => false,
        'declarationSigned' => false,
        'declarationDate' => ''
    ];

    $ins = $pdo->prepare('INSERT INTO `Application` (id, startupId, version, status, grantType, requestedTotal, formData, createdAt, updatedAt) VALUES (?, ?, 1, "DRAFT", "PROOF", 0.00, ?, NOW(3), NOW(3))');
    $ins->execute([$appId, $startup['id'], json_encode($initialFormData, JSON_UNESCAPED_UNICODE)]);

    $aStmt->execute([$startup['id']]);
    $application = $aStmt->fetch();
}

$isReadOnly = ($application['status'] !== 'DRAFT');
$formData = json_decode($application['formData'], true) ?: [];

// Normalize legacy keys
$formData['problemStatement'] = $formData['problemStatement'] ?? $formData['problem'] ?? '';
$formData['proposedSolution'] = $formData['proposedSolution'] ?? $formData['solution'] ?? '';
$formData['effortTraction'] = $formData['effortTraction'] ?? $formData['traction'] ?? '';
$formData['justifications'] = $formData['justifications'] ?? [];

// Fetch budget items
$bStmt = $pdo->prepare('SELECT * FROM `BudgetItem` WHERE applicationId = ? ORDER BY header ASC, id ASC');
$bStmt->execute([$application['id']]);
$budgetItems = $bStmt->fetchAll();

// Calculate initial budget sums
$directBudgetTotal = 0.00;
foreach ($budgetItems as $bItem) {
    $directBudgetTotal += (float)$bItem['total'];
}
$contingencyBudgetTotal = round($directBudgetTotal * 0.05, 2);
$grandBudgetTotal = $directBudgetTotal + $contingencyBudgetTotal;
$currentRequestedTotal = (float)$application['requestedTotal'] > 0 ? (float)$application['requestedTotal'] : $grandBudgetTotal;

// Fetch uploaded documents
$dStmt = $pdo->prepare('SELECT * FROM `Document` WHERE applicationId = ? ORDER BY createdAt ASC');
$dStmt->execute([$application['id']]);
$uploadedDocs = $dStmt->fetchAll();
$docsByType = [];
foreach ($uploadedDocs as $d) {
    $docsByType[$d['type']] = $d;
}

$submitErrors = [];

// -------------------------------------------------------------
// POST Request Handlers (AJAX Partial Save, Upload, Submit)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isReadOnly) {
    $action = $_POST['action'] ?? '';

    // 1. Partial Section Save (AJAX or direct form POST)
    if ($action === 'save_draft' || isset($_POST['partial_step'])) {
        $grantType = $_POST['grantType'] ?? $_POST['grantTypeSelect'] ?? ($application['grantType'] ?? 'PROOF');
        if (!isset(Constants::GRANT_TIERS[$grantType])) {
            $grantType = $application['grantType'] ?? 'PROOF';
        }
        $formData['grantType'] = $grantType;

        // Read direct form field inputs
        if (isset($_POST['problemStatement'])) $formData['problemStatement'] = trim((string)$_POST['problemStatement']);
        if (isset($_POST['proposedSolution'])) $formData['proposedSolution'] = trim((string)$_POST['proposedSolution']);
        if (isset($_POST['effortTraction'])) $formData['effortTraction'] = trim((string)$_POST['effortTraction']);
        if (isset($_POST['agreementAccepted'])) $formData['agreementAccepted'] = (bool)$_POST['agreementAccepted'];
        if (isset($_POST['declarationSigned'])) $formData['declarationSigned'] = (bool)$_POST['declarationSigned'];
        if (isset($_POST['declarationDate'])) $formData['declarationDate'] = trim((string)$_POST['declarationDate']);

        // Keep standard keys synchronized for reports & evaluation split-view
        $formData['problem'] = $formData['problemStatement'];
        $formData['solution'] = $formData['proposedSolution'];
        $formData['traction'] = $formData['effortTraction'];

        // 10 Experiment fields
        if (isset($_POST['exp']) && is_array($_POST['exp'])) {
            $formData['experiments'] = $_POST['exp'];
        }

        // Self assessment
        if (isset($_POST['self_trl'])) $formData['selfAssessment']['trl'] = (int)$_POST['self_trl'];
        if (isset($_POST['self_mrl'])) $formData['selfAssessment']['mrl'] = (int)$_POST['self_mrl'];
        if (isset($_POST['self_irl'])) $formData['selfAssessment']['irl'] = (int)$_POST['self_irl'];
        if (isset($_POST['self_track'])) $formData['selfAssessment']['track'] = (string)$_POST['self_track'];
        if (isset($_POST['milestones']) && is_array($_POST['milestones'])) {
            $formData['selfAssessment']['milestones'] = $_POST['milestones'];
        }

        // Justifications handling from Section 5
        if (isset($_POST['justification_item']) && is_array($_POST['justification_item'])) {
            $formData['justifications'] = $_POST['justification_item'];
            $bRows = $pdo->prepare('SELECT id FROM `BudgetItem` WHERE applicationId = ? ORDER BY header ASC, id ASC');
            $bRows->execute([$application['id']]);
            $existingBIds = $bRows->fetchAll(PDO::FETCH_COLUMN);

            $updJust = $pdo->prepare('UPDATE `BudgetItem` SET justification = ? WHERE id = ?');
            foreach ($_POST['justification_item'] as $jKey => $jVal) {
                $jText = trim((string)$jVal);
                if (is_numeric($jKey) && isset($existingBIds[(int)$jKey])) {
                    $updJust->execute([$jText, $existingBIds[(int)$jKey]]);
                } elseif (is_string($jKey) && str_starts_with($jKey, 'b_')) {
                    $updJust->execute([$jText, $jKey]);
                }
            }
        }

        // Budget items handling from Section 4
        if (isset($_POST['budget_items_json'])) {
            $newItems = json_decode($_POST['budget_items_json'], true);
            if (is_array($newItems)) {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM `BudgetItem` WHERE applicationId = ?')->execute([$application['id']]);
                $bIns = $pdo->prepare('INSERT INTO `BudgetItem` (id, applicationId, header, name, vendor, unitCost, qty, total, justification) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $calcTotal = 0.00;
                foreach ($newItems as $it) {
                    $uCost = (float)($it['unitCost'] ?? 0);
                    $qty = (int)($it['qty'] ?? 1);
                    $total = $uCost * $qty;
                    $calcTotal += $total;
                    $bIns->execute([
                        Auth::generateId('b'),
                        $application['id'],
                        $it['header'] ?? 'CONSUMABLES',
                        trim($it['name'] ?? ''),
                        trim($it['vendor'] ?? ''),
                        $uCost,
                        $qty,
                        $total,
                        trim($it['justification'] ?? '')
                    ]);
                }
                $pdo->commit();
            }
        }

        // Recalculate direct total and 5% contingency buffer
        $totStmt = $pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM `BudgetItem` WHERE applicationId = ?');
        $totStmt->execute([$application['id']]);
        $directTotal = (float)$totStmt->fetchColumn();
        $contingency = round($directTotal * 0.05, 2);
        $grandTotal = $directTotal + $contingency;

        // Update Application record
        $upd = $pdo->prepare('UPDATE `Application` SET grantType = ?, requestedTotal = ?, formData = ?, updatedAt = NOW(3) WHERE id = ?');
        $upd->execute([$grantType, $grandTotal, json_encode($formData, JSON_UNESCAPED_UNICODE), $application['id']]);

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_POST['partial_step']) && empty($_POST['is_full_submit']))) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'requestedTotal' => $grandTotal,
                'subtotal' => $directTotal,
                'contingency' => $contingency,
                'grantType' => $grantType
            ]);
            exit;
        }

        header('Location: /dashboard.php');
        exit;
    }

    // 2. Document Upload (Client-side compressed image or deck/quotation PDF)
    if ($action === 'upload_document') {
        $docType = $_POST['docType'] ?? 'ANNEXURE';
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['file']['tmp_name'];
            $origName = $_FILES['file']['name'];
            $fileSize = $_FILES['file']['size'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            if (!in_array($ext, $allowed, true)) {
                $uploadError = "Only JPEG, PNG, WebP, and PDF files are accepted.";
            } else {
                $docId = Auth::generateId('doc');
                $targetFilename = $application['id'] . '_' . $docType . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $targetPath = __DIR__ . '/../uploads/' . $targetFilename;

                if (move_uploaded_file($tmpPath, $targetPath)) {
                    $docIns = $pdo->prepare('INSERT INTO `Document` (id, applicationId, type, memberLabel, path, createdAt) VALUES (?, ?, ?, ?, ?, NOW(3))');
                    $docIns->execute([$docId, $application['id'], $docType, $origName, $targetFilename]);
                    Auth::audit('UPLOAD_DOCUMENT', 'Document', $docId, ['type' => $docType, 'size' => $fileSize]);

                    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'docId' => $docId, 'filename' => $origName, 'path' => $targetFilename]);
                        exit;
                    }
                    header('Location: /dashboard.php#step-7');
                    exit;
                }
            }
        }
    }

    // 3. Document Delete
    if ($action === 'delete_document') {
        $docId = $_POST['docId'] ?? '';
        $del = $pdo->prepare('DELETE FROM `Document` WHERE id = ? AND applicationId = ?');
        $del->execute([$docId, $application['id']]);

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        header('Location: /dashboard.php#step-7');
        exit;
    }

    // 4. Final Submission Lock
    if ($action === 'final_submit') {
        // Read declaration and form fields from POST first
        if (isset($_POST['declarationSigned'])) {
            $formData['declarationSigned'] = true;
            $formData['declarationDate'] = date('Y-m-d');
        }

        $grantType = $_POST['grantType'] ?? ($application['grantType'] ?? 'PROOF');
        if (!isset(Constants::GRANT_TIERS[$grantType])) {
            $grantType = 'PROOF';
        }
        $formData['grantType'] = $grantType;

        // Recalculate latest budget total
        $totStmt = $pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM `BudgetItem` WHERE applicationId = ?');
        $totStmt->execute([$application['id']]);
        $directTotal = (float)$totStmt->fetchColumn();
        $contingency = round($directTotal * 0.05, 2);
        $grandTotal = $directTotal + $contingency;

        // Persist updated draft state before validating
        $pdo->prepare('UPDATE `Application` SET grantType = ?, requestedTotal = ?, formData = ?, updatedAt = NOW(3) WHERE id = ?')
            ->execute([$grantType, $grandTotal, json_encode($formData, JSON_UNESCAPED_UNICODE), $application['id']]);

        // Validation checks across all sections
        $errors = [];
        if (empty($formData['agreementAccepted'])) $errors[] = "Section 1: Agreement with IDEAPAD must be accepted.";
        if (empty($formData['problemStatement'])) $errors[] = "Section 2: Problem statement is required.";
        if (empty($formData['proposedSolution'])) $errors[] = "Section 2: Proposed solution is required.";
        if (empty($formData['effortTraction'])) $errors[] = "Section 2: Effort & traction so far is required.";

        for ($i = 1; $i <= 10; $i++) {
            if (empty($formData['experiments'][$i])) {
                $errors[] = "Section 3: Experiment Heading $i (" . Constants::EXPERIMENT_HEADINGS[$i]['title'] . ") is required.";
            }
        }

        if ($directTotal <= 0) {
            $errors[] = "Section 4: Itemized budget must contain at least one valid item.";
        }

        if (empty($formData['declarationSigned'])) {
            $errors[] = "Section 8: You must check the declaration box before submitting.";
        }

        if (count($errors) === 0) {
            $sub = $pdo->prepare('UPDATE `Application` SET status = "SUBMITTED", submittedAt = NOW(3), updatedAt = NOW(3) WHERE id = ?');
            $sub->execute([$application['id']]);
            Auth::audit('SUBMIT_APPLICATION', 'Application', $application['id'], ['total' => $grandTotal, 'grantType' => $grantType]);

            header('Location: /status.php');
            exit;
        } else {
            $submitErrors = $errors;
        }
    }
}

// Refresh application and items state after any POST handling
$aStmt->execute([$startup['id']]);
$application = $aStmt->fetch();
$formData = json_decode($application['formData'], true) ?: [];
$formData['problemStatement'] = $formData['problemStatement'] ?? $formData['problem'] ?? '';
$formData['proposedSolution'] = $formData['proposedSolution'] ?? $formData['solution'] ?? '';
$formData['effortTraction'] = $formData['effortTraction'] ?? $formData['traction'] ?? '';
$formData['justifications'] = $formData['justifications'] ?? [];

$bStmt->execute([$application['id']]);
$budgetItems = $bStmt->fetchAll();

$directBudgetTotal = 0.00;
foreach ($budgetItems as $bItem) {
    $directBudgetTotal += (float)$bItem['total'];
}
$contingencyBudgetTotal = round($directBudgetTotal * 0.05, 2);
$grandBudgetTotal = $directBudgetTotal + $contingencyBudgetTotal;
$currentRequestedTotal = (float)$application['requestedTotal'] > 0 ? (float)$application['requestedTotal'] : $grandBudgetTotal;

$pageTitle = "Startup Application Form | IDEAPAD Seed Fund";
require_once __DIR__ . '/../templates/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

  <!-- Status & Application Header -->
  <div class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
      <div class="flex items-center gap-2">
        <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wider <?= $application['status'] === 'DRAFT' ? 'bg-amber-100 text-amber-800' : ($application['status'] === 'SUBMITTED' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800') ?>">
          <?= htmlspecialchars($application['status']) ?>
        </span>
        <span class="text-xs text-gray-400 font-mono">ID: <?= htmlspecialchars($application['id']) ?></span>
      </div>
      <h1 class="text-2xl font-black text-gray-900 tracking-tight mt-1">Seed Grant Application (Form A Companion)</h1>
      <p class="text-xs text-gray-500">Applicant: <strong><?= htmlspecialchars($startup['teamName']) ?></strong> • Target Tier: <strong id="header-grant-tier"><?= htmlspecialchars(Constants::GRANT_TIERS[$application['grantType'] ?? 'PROOF']['name']) ?></strong></p>
    </div>

    <div class="flex items-center gap-3">
      <?php if ($isReadOnly): ?>
        <a href="/reports.php?id=<?= urlencode($application['id']) ?>&type=application" target="_blank" class="px-4 py-2 bg-blue-600 text-white font-bold text-xs rounded-xl shadow-xs hover:bg-blue-700 flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          Print Proposal Dossier
        </a>
      <?php else: ?>
        <button type="button" onclick="stepper.saveAndNext(null)" class="px-4 py-2 bg-gray-100 text-gray-700 font-semibold text-xs rounded-xl hover:bg-gray-200 border border-gray-300 flex items-center gap-1.5">
          <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
          Save Current Section
        </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($submitErrors)): ?>
    <div class="p-4 bg-red-50 border border-red-200 rounded-xl space-y-1">
      <div class="text-xs font-bold text-red-800 uppercase flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        Please complete required fields before final submission:
      </div>
      <ul class="list-disc pl-5 text-xs text-red-700 space-y-0.5">
        <?php foreach ($submitErrors as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- Policy Stepper Navigation (8 Sections) -->
  <div class="bg-white rounded-2xl p-3 shadow-xs border border-gray-200 overflow-x-auto">
    <div class="flex items-center gap-2 min-w-[760px] text-xs font-bold text-gray-500">
      <button type="button" data-step="1" class="stepper-nav-item active px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-blue-600 text-white text-[10px] font-bold flex items-center justify-center">1</span>
        Agreement
      </button>
      <button type="button" data-step="2" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold flex items-center justify-center">2</span>
        Applicant & Grant
      </button>
      <button type="button" data-step="3" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold flex items-center justify-center">3</span>
        10 Experiments
        <span id="experiment-count-badge" class="px-1.5 py-0.2 text-[10px] rounded-full bg-gray-200 text-gray-700">0/10</span>
      </button>
      <button type="button" data-step="4" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold flex items-center justify-center">4</span>
        Annexure 1 Budget
      </button>
      <button type="button" data-step="5" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold flex items-center justify-center">5</span>
        Justifications
      </button>
      <button type="button" data-step="6" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold flex items-center justify-center">6</span>
        Self-Assessment
      </button>
      <button type="button" data-step="7" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold flex items-center justify-center">7</span>
        Documents (100–150KB)
      </button>
      <button type="button" data-step="8" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold flex items-center justify-center">8</span>
        Declaration & Submit
      </button>
    </div>
  </div>

  <!-- Form Stepper Container -->
  <form id="startup-application-form" method="POST" action="/dashboard.php" class="space-y-6">
    <input type="hidden" name="action" id="form-action-input" value="save_draft">

    <!-- SECTION 1: AGREEMENT WITH IDEAPAD -->
    <div id="step-1" class="form-section bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
      <div class="border-b border-gray-200 pb-4">
        <div class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 uppercase tracking-wider mb-1">
          Policy P5.1 Compliance
        </div>
        <h2 class="text-xl font-bold text-gray-900">1. Facility Agreement & Terms with IDEAPAD</h2>
        <p class="text-xs text-gray-500 mt-1">Review the foundational university terms and incubation agreement conditions before requesting seed funding.</p>
      </div>

      <div class="p-5 rounded-xl bg-gray-50 border border-gray-200 text-xs text-gray-700 space-y-3 leading-relaxed max-h-72 overflow-y-auto">
        <h4 class="font-bold text-gray-900 text-sm">Terms and Conditions of Seed Grant Allocation:</h4>
        <ol class="list-decimal pl-5 space-y-2">
          <?php foreach (Constants::TERMS_AND_CONDITIONS as $tc): ?>
            <li><?= htmlspecialchars($tc) ?></li>
          <?php endforeach; ?>
        </ol>
      </div>

      <div class="p-4 rounded-xl bg-blue-50/60 border border-blue-200 flex items-start gap-3">
        <input 
          type="checkbox" 
          id="agreementAccepted" 
          name="agreementAccepted" 
          value="1" 
          <?= !empty($formData['agreementAccepted']) ? 'checked' : '' ?>
          <?= $isReadOnly ? 'disabled' : '' ?>
          class="mt-1 h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
        />
        <label for="agreementAccepted" class="text-xs text-gray-800 font-medium">
          I on behalf of <strong><?= htmlspecialchars($startup['teamName']) ?></strong> agree to the facility and grant terms laid down by IDEAPAD and ITM University. I confirm that all team members are enrolled students / faculty of the university.
        </label>
      </div>

      <!-- Navigation Bar -->
      <div class="pt-4 border-t border-gray-200 flex items-center justify-between">
        <div></div>
        <button type="button" onclick="stepper.saveAndNext(this)" class="px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 shadow-xs">
          Save & Next: Basic Details →
        </button>
      </div>
    </div>

    <!-- SECTION 2: BASIC INFORMATION & SUMMARY -->
    <div id="step-2" class="form-section hidden bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
      <div class="border-b border-gray-200 pb-4">
        <h2 class="text-xl font-bold text-gray-900">2. Applicant Profile & Venture Overview</h2>
        <p class="text-xs text-gray-500 mt-1">Applicant details (P6.1) and concise overview of problem, solution, and current traction (P6.2–4).</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 rounded-xl bg-gray-50 border border-gray-200 text-xs">
        <div><span class="text-gray-500">Applicant Name:</span> <strong><?= htmlspecialchars($user['name']) ?></strong></div>
        <div><span class="text-gray-500">Venture Team:</span> <strong><?= htmlspecialchars($startup['teamName']) ?></strong></div>
        <div><span class="text-gray-500">Enrollment No:</span> <strong><?= htmlspecialchars($startup['enrollmentNo']) ?></strong></div>
        <div><span class="text-gray-500">Program & Dept:</span> <strong><?= htmlspecialchars($startup['program']) ?></strong></div>
        <div><span class="text-gray-500">Contact Mobile:</span> <strong><?= htmlspecialchars($startup['phone']) ?></strong></div>
        <div><span class="text-gray-500">Official Email:</span> <strong><?= htmlspecialchars($user['email']) ?></strong></div>
      </div>

      <!-- Selected Grant Tier Selection -->
      <div>
        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">Target Grant Type (Policy P2) *</label>
        <p class="text-[11px] text-gray-500 mb-3">Click on a grant card below to select your requested scheme:</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3" id="grant-tier-selector">
          <?php foreach (Constants::GRANT_TIERS as $tKey => $tier): if ($tKey === 'EMERGENCY') continue; 
            $isSelected = ($application['grantType'] ?? 'PROOF') === $tKey;
          ?>
            <label 
              class="grant-card cursor-pointer border rounded-xl p-3 flex flex-col justify-between text-xs transition-all <?= $isSelected ? 'border-blue-600 bg-blue-50/70 ring-2 ring-blue-600 shadow-sm' : 'border-gray-200 hover:border-gray-300 bg-white' ?>" 
              data-grant="<?= $tKey ?>"
              onclick="updateGrantTierSelection('<?= $tKey ?>')"
            >
              <div>
                <div class="flex items-center justify-between">
                  <span class="font-bold text-gray-900"><?= htmlspecialchars($tier['name']) ?></span>
                  <span class="grant-check text-blue-600 text-sm font-bold <?= $isSelected ? '' : 'hidden' ?>">✓</span>
                </div>
                <input 
                  type="radio" 
                  name="grantType" 
                  value="<?= $tKey ?>" 
                  <?= $isSelected ? 'checked' : '' ?>
                  <?= $isReadOnly ? 'disabled' : '' ?>
                  class="sr-only grant-radio"
                />
                <span class="block text-blue-700 font-extrabold mt-1">₹<?= number_format($tier['min']) ?> – ₹<?= number_format($tier['max']) ?></span>
              </div>
              <span class="block text-[11px] text-gray-500 mt-2 leading-relaxed"><?= htmlspecialchars($tier['target_trl']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Problem Statement -->
      <div>
        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
          Problem you are solving (Explain the startup in short) *
        </label>
        <textarea 
          name="problemStatement" 
          rows="3" 
          maxlength="600"
          <?= $isReadOnly ? 'readonly' : '' ?>
          placeholder="e.g. There are almost 2 million people who suffer from secondary medical conditions..."
          class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-xs focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
        ><?= htmlspecialchars($formData['problemStatement'] ?? '') ?></textarea>
      </div>

      <!-- Proposed Solution -->
      <div>
        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
          Proposed solution (as short as possible) *
        </label>
        <textarea 
          name="proposedSolution" 
          rows="3" 
          maxlength="600"
          <?= $isReadOnly ? 'readonly' : '' ?>
          placeholder="e.g. Smart wearable sensor array coupled with an automated pneumatic cushion..."
          class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-xs focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
        ><?= htmlspecialchars($formData['proposedSolution'] ?? '') ?></textarea>
      </div>

      <!-- Traction So Far -->
      <div>
        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
          Effort & traction so far (as short as possible) *
        </label>
        <textarea 
          name="effortTraction" 
          rows="3" 
          maxlength="600"
          <?= $isReadOnly ? 'readonly' : '' ?>
          placeholder="e.g. 1. Interviewed 100 subjects; 2. Built proof of concept breadboard..."
          class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-xs focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
        ><?= htmlspecialchars($formData['effortTraction'] ?? '') ?></textarea>
      </div>

      <!-- Navigation -->
      <div class="pt-4 border-t border-gray-200 flex items-center justify-between">
        <button type="button" onclick="stepper.prevSection()" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-700 font-semibold text-xs hover:bg-gray-200">
          ← Back
        </button>
        <button type="button" onclick="stepper.saveAndNext(this)" class="px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 shadow-xs">
          Save & Next: 10 Experiments →
        </button>
      </div>
    </div>

    <!-- SECTION 3: 10 EXPERIMENT HEADINGS -->
    <div id="step-3" class="form-section hidden bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
      <div class="border-b border-gray-200 pb-4">
        <div class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 uppercase tracking-wider mb-1">
          Policy P6.5 Requirement
        </div>
        <h2 class="text-xl font-bold text-gray-900">3. Experiment Plan & Proof of Concept (10 Headings)</h2>
        <p class="text-xs text-gray-500 mt-1">Formulate the rigorous experiment to assess your solution and create proof of concept with desired outcomes.</p>
      </div>

      <div class="space-y-4">
        <?php foreach (Constants::EXPERIMENT_HEADINGS as $num => $exp): ?>
          <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-1.5">
            <div class="flex items-center justify-between">
              <label class="block text-xs font-bold text-gray-800">
                <?= $num ?>. <?= htmlspecialchars($exp['title']) ?> *
              </label>
              <span class="text-[10px] font-mono text-gray-400">Head <?= $num ?></span>
            </div>
            <p class="text-[11px] text-gray-500"><?= htmlspecialchars($exp['prompt']) ?></p>
            <textarea 
              name="exp[<?= $num ?>]" 
              rows="2" 
              <?= $isReadOnly ? 'readonly' : '' ?>
              placeholder="Enter your detailed response..."
              class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-xs focus:ring-2 focus:ring-blue-600 focus:outline-none"
            ><?= htmlspecialchars($formData['experiments'][$num] ?? '') ?></textarea>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Navigation -->
      <div class="pt-4 border-t border-gray-200 flex items-center justify-between">
        <button type="button" onclick="stepper.prevSection()" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-700 font-semibold text-xs hover:bg-gray-200">
          ← Back
        </button>
        <button type="button" onclick="stepper.saveAndNext(this)" class="px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 shadow-xs">
          Save & Next: Requirement Table →
        </button>
      </div>
    </div>

    <!-- SECTION 4: DETAILED REQUIREMENT TABLE (ANNEXURE 1) -->
    <div id="step-4" class="form-section hidden bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
      <div class="border-b border-gray-200 pb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
          <h2 class="text-xl font-bold text-gray-900">4. Detailed Requirement Table (Annexure 1)</h2>
          <p class="text-xs text-gray-500 mt-1">Itemized cost breakdown grouped under the 9 major grant headers with mandatory 5% contingency.</p>
        </div>
        <div class="text-right">
          <span class="text-[11px] text-gray-500 block">Total Grant Requested:</span>
          <span class="text-xl font-black text-green-700 font-mono" id="live-budget-total">₹<?= number_format($currentRequestedTotal, 2) ?></span>
        </div>
      </div>

      <!-- Budget Table -->
      <div class="overflow-x-auto border border-gray-200 rounded-xl">
        <table class="w-full text-left text-xs" id="budget-table">
          <thead class="bg-gray-100 text-gray-700 font-bold uppercase tracking-wider text-[10px]">
            <tr>
              <th class="p-3">Annexure 1 Category</th>
              <th class="p-3">Product / Service Description</th>
              <th class="p-3">Vendor / Source</th>
              <th class="p-3 text-right">Unit Cost (₹)</th>
              <th class="p-3 text-center">Qty</th>
              <th class="p-3 text-right">Total (₹)</th>
              <?php if (!$isReadOnly): ?><th class="p-3 text-center">Action</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="budget-tbody" class="divide-y divide-gray-200 bg-white">
            <?php foreach ($budgetItems as $bIdx => $item): ?>
              <tr data-row-idx="<?= $bIdx ?>" data-justification="<?= htmlspecialchars($item['justification'] ?? '') ?>">
                <td class="p-2.5">
                  <select name="b_header[]" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full p-1.5 border rounded-lg text-xs bg-gray-50">
                    <?php foreach (Constants::BUDGET_HEADERS as $hK => $hV): ?>
                      <option value="<?= $hK ?>" <?= $item['header'] === $hK ? 'selected' : '' ?>><?= htmlspecialchars($hV['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td class="p-2.5">
                  <input type="text" name="b_name[]" value="<?= htmlspecialchars($item['name']) ?>" <?= $isReadOnly ? 'readonly' : '' ?> class="w-full p-1.5 border rounded-lg text-xs" placeholder="Item name & specs" required>
                </td>
                <td class="p-2.5">
                  <input type="text" name="b_vendor[]" value="<?= htmlspecialchars($item['vendor'] ?? '') ?>" <?= $isReadOnly ? 'readonly' : '' ?> class="w-full p-1.5 border rounded-lg text-xs" placeholder="Amazon, Robu.in, etc.">
                </td>
                <td class="p-2.5 text-right">
                  <input type="number" step="0.01" name="b_unitCost[]" value="<?= htmlspecialchars((string)$item['unitCost']) ?>" <?= $isReadOnly ? 'readonly' : '' ?> class="w-24 p-1.5 border rounded-lg text-xs text-right font-mono budget-calc" required>
                </td>
                <td class="p-2.5 text-center">
                  <input type="number" name="b_qty[]" value="<?= htmlspecialchars((string)$item['qty']) ?>" <?= $isReadOnly ? 'readonly' : '' ?> class="w-16 p-1.5 border rounded-lg text-xs text-center font-mono budget-calc" min="1" required>
                </td>
                <td class="p-2.5 text-right font-mono font-bold text-gray-900 row-total">
                  ₹<?= number_format((float)$item['total'], 2) ?>
                </td>
                <?php if (!$isReadOnly): ?>
                  <td class="p-2.5 text-center">
                    <button type="button" onclick="removeBudgetRow(this)" class="text-red-500 hover:text-red-700 font-bold">✕</button>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="bg-gray-50 border-t-2 border-gray-300 font-bold text-xs">
            <tr>
              <td colspan="5" class="p-2.5 text-right text-gray-600">Direct Expenses Subtotal:</td>
              <td class="p-2.5 text-right font-mono" id="live-budget-subtotal">₹<?= number_format($directBudgetTotal, 2) ?></td>
              <?php if (!$isReadOnly): ?><td></td><?php endif; ?>
            </tr>
            <tr>
              <td colspan="5" class="p-2.5 text-right text-gray-600">Mandatory 5% Contingency Buffer:</td>
              <td class="p-2.5 text-right font-mono text-gray-600" id="live-budget-contingency">₹<?= number_format($contingencyBudgetTotal, 2) ?></td>
              <?php if (!$isReadOnly): ?><td></td><?php endif; ?>
            </tr>
            <tr class="bg-blue-50 text-blue-950 font-black">
              <td colspan="5" class="p-2.5 text-right text-sm">Grand Total Grant Requested:</td>
              <td class="p-2.5 text-right font-mono text-sm text-green-700" id="live-budget-grand">₹<?= number_format($grandBudgetTotal, 2) ?></td>
              <?php if (!$isReadOnly): ?><td></td><?php endif; ?>
            </tr>
          </tfoot>
        </table>
      </div>

      <?php if (!$isReadOnly): ?>
        <div>
          <button type="button" onclick="addBudgetRow()" class="px-4 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-xs flex items-center gap-1.5 border border-gray-300 shadow-xs">
            + Add Line Item
          </button>
        </div>
      <?php endif; ?>

      <!-- Navigation -->
      <div class="pt-4 border-t border-gray-200 flex items-center justify-between">
        <button type="button" onclick="stepper.prevSection()" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-700 font-semibold text-xs hover:bg-gray-200">
          ← Back
        </button>
        <button type="button" onclick="serializeAndSaveBudget(this)" class="px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 shadow-xs">
          Save & Next: Justifications →
        </button>
      </div>
    </div>

    <!-- SECTION 5: JUSTIFICATIONS (P6.7) -->
    <div id="step-5" class="form-section hidden bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
      <div class="border-b border-gray-200 pb-4">
        <h2 class="text-xl font-bold text-gray-900">5. Justification for Each Item</h2>
        <p class="text-xs text-gray-500 mt-1">Provide short explanation of why each item is essential for your prototype / validation stage.</p>
      </div>

      <div id="justifications-container" class="space-y-4">
        <?php if (empty($budgetItems)): ?>
          <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs">
            <strong>No budget items found.</strong> Please click "← Back" and add required line items in Section 4 (Requirement Table) first.
          </div>
        <?php else: foreach ($budgetItems as $jIdx => $item): ?>
          <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-2">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between text-xs font-bold text-gray-800 gap-1">
              <div class="flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-800 text-[11px] font-mono flex items-center justify-center font-bold"><?= $jIdx + 1 ?></span>
                <span><?= htmlspecialchars($item['name']) ?> <span class="text-[10px] text-gray-500 font-normal">(<?= htmlspecialchars($item['header']) ?> • <?= htmlspecialchars($item['vendor'] ?? 'Vendor') ?>)</span></span>
              </div>
              <span class="text-blue-700 font-mono font-bold">₹<?= number_format((float)$item['total'], 2) ?></span>
            </div>
            <textarea 
              name="justification_item[<?= $item['id'] ?>]" 
              data-item-index="<?= $jIdx ?>"
              rows="2" 
              <?= $isReadOnly ? 'readonly' : '' ?>
              placeholder="Explain why this item is necessary for the experiment..."
              class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-xs focus:ring-2 focus:ring-blue-600 focus:outline-none"
            ><?= htmlspecialchars($item['justification'] ?? ($formData['justifications'][$item['id']] ?? ($formData['justifications'][$jIdx] ?? ''))) ?></textarea>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Navigation -->
      <div class="pt-4 border-t border-gray-200 flex items-center justify-between">
        <button type="button" onclick="stepper.prevSection()" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-700 font-semibold text-xs hover:bg-gray-200">
          ← Back
        </button>
        <button type="button" onclick="stepper.saveAndNext(this)" class="px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 shadow-xs">
          Save & Next: Self-Assessment →
        </button>
      </div>
    </div>

    <!-- SECTION 6: SELF-ASSESSMENT (TRL/MRL/IRL & MILESTONES) -->
    <div id="step-6" class="form-section hidden bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
      <div class="border-b border-gray-200 pb-4">
        <h2 class="text-xl font-bold text-gray-900">6. Self-Assessment of Project Status</h2>
        <p class="text-xs text-gray-500 mt-1">Self-assess current levels on TRL, MRL, and IRL scales (1–9) and milestone status (P6.8–9).</p>
      </div>

      <!-- 3 Scales (TRL, MRL, IRL) -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-2">
          <label class="block text-xs font-bold text-gray-800 uppercase">Technology Readiness (TRL)</label>
          <select name="self_trl" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full p-2 bg-white border border-gray-300 rounded-lg text-xs font-bold text-blue-700">
            <?php foreach (Constants::TRL_LEVELS as $lvl => $desc): ?>
              <option value="<?= $lvl ?>" <?= ($formData['selfAssessment']['trl'] ?? 1) === $lvl ? 'selected' : '' ?>>Level <?= $lvl ?>: <?= htmlspecialchars($desc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-2">
          <label class="block text-xs font-bold text-gray-800 uppercase">Manufacturing Readiness (MRL)</label>
          <select name="self_mrl" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full p-2 bg-white border border-gray-300 rounded-lg text-xs font-bold text-purple-700">
            <?php foreach (Constants::MRL_LEVELS as $lvl => $desc): ?>
              <option value="<?= $lvl ?>" <?= ($formData['selfAssessment']['mrl'] ?? 1) === $lvl ? 'selected' : '' ?>>Level <?= $lvl ?>: <?= htmlspecialchars($desc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-2">
          <label class="block text-xs font-bold text-gray-800 uppercase">Integration Readiness (IRL)</label>
          <select name="self_irl" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full p-2 bg-white border border-gray-300 rounded-lg text-xs font-bold text-emerald-700">
            <?php foreach (Constants::IRL_LEVELS as $lvl => $desc): ?>
              <option value="<?= $lvl ?>" <?= ($formData['selfAssessment']['irl'] ?? 1) === $lvl ? 'selected' : '' ?>>Level <?= $lvl ?>: <?= htmlspecialchars($desc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Milestone Track Selector -->
      <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-3">
        <div class="flex items-center justify-between">
          <label class="text-xs font-bold text-gray-800 uppercase">Milestones Self-Assessment Track</label>
          <select name="self_track" id="track-selector" onchange="toggleMilestoneTrack(this.value)" <?= $isReadOnly ? 'disabled' : '' ?> class="p-1.5 bg-white border rounded-lg text-xs font-semibold">
            <option value="PROTOTYPING" <?= ($formData['selfAssessment']['track'] ?? 'PROTOTYPING') === 'PROTOTYPING' ? 'selected' : '' ?>>Track A: Problem-Solution Fit Startups</option>
            <option value="PROFESSIONAL" <?= ($formData['selfAssessment']['track'] ?? 'PROTOTYPING') === 'PROFESSIONAL' ? 'selected' : '' ?>>Track B: Entrepreneurial-Fit Startups</option>
          </select>
        </div>

        <!-- Track A Table -->
        <div id="track-a-container" class="<?= ($formData['selfAssessment']['track'] ?? 'PROTOTYPING') === 'PROTOTYPING' ? '' : 'hidden' ?>">
          <table class="w-full text-left text-xs border border-gray-200 rounded-lg overflow-hidden">
            <thead class="bg-gray-100 text-gray-700 font-bold">
              <tr>
                <th class="p-2.5">Milestone Checkpoint</th>
                <th class="p-2.5 text-center w-36">Status</th>
                <th class="p-2.5">Remarks / Progress Evidence</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
              <?php foreach (Constants::MILESTONES_TRACK_A as $mName): 
                $mStatus = $formData['selfAssessment']['milestones'][$mName]['status'] ?? ($formData['selfAssessment']['milestones'][$mName] ?? 'PENDING');
                $mRemarks = $formData['selfAssessment']['milestones'][$mName]['remarks'] ?? '';
              ?>
                <tr>
                  <td class="p-2.5 font-medium text-gray-800"><?= htmlspecialchars($mName) ?></td>
                  <td class="p-2.5 text-center">
                    <select name="milestones[<?= htmlspecialchars($mName) ?>][status]" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full p-1 border rounded text-xs bg-gray-50">
                      <option value="PENDING" <?= $mStatus === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                      <option value="IN_PROGRESS" <?= $mStatus === 'IN_PROGRESS' ? 'selected' : '' ?>>In Progress</option>
                      <option value="COMPLETED" <?= $mStatus === 'COMPLETED' ? 'selected' : '' ?>>Completed</option>
                    </select>
                  </td>
                  <td class="p-2.5">
                    <input type="text" name="milestones[<?= htmlspecialchars($mName) ?>][remarks]" value="<?= htmlspecialchars((string)$mRemarks) ?>" <?= $isReadOnly ? 'readonly' : '' ?> placeholder="Short progress notes..." class="w-full p-1 border rounded text-xs">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Track B Table -->
        <div id="track-b-container" class="<?= ($formData['selfAssessment']['track'] ?? 'PROTOTYPING') === 'PROFESSIONAL' ? '' : 'hidden' ?>">
          <table class="w-full text-left text-xs border border-gray-200 rounded-lg overflow-hidden">
            <thead class="bg-gray-100 text-gray-700 font-bold">
              <tr>
                <th class="p-2.5">Milestone Checkpoint</th>
                <th class="p-2.5 text-center w-36">Status</th>
                <th class="p-2.5">Remarks / Progress Evidence</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
              <?php foreach (Constants::MILESTONES_TRACK_B as $mName): 
                $mStatus = $formData['selfAssessment']['milestones'][$mName]['status'] ?? ($formData['selfAssessment']['milestones'][$mName] ?? 'PENDING');
                $mRemarks = $formData['selfAssessment']['milestones'][$mName]['remarks'] ?? '';
              ?>
                <tr>
                  <td class="p-2.5 font-medium text-gray-800"><?= htmlspecialchars($mName) ?></td>
                  <td class="p-2.5 text-center">
                    <select name="milestones[<?= htmlspecialchars($mName) ?>][status]" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full p-1 border rounded text-xs bg-gray-50">
                      <option value="PENDING" <?= $mStatus === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                      <option value="IN_PROGRESS" <?= $mStatus === 'IN_PROGRESS' ? 'selected' : '' ?>>In Progress</option>
                      <option value="COMPLETED" <?= $mStatus === 'COMPLETED' ? 'selected' : '' ?>>Completed</option>
                    </select>
                  </td>
                  <td class="p-2.5">
                    <input type="text" name="milestones[<?= htmlspecialchars($mName) ?>][remarks]" value="<?= htmlspecialchars((string)$mRemarks) ?>" <?= $isReadOnly ? 'readonly' : '' ?> placeholder="Short progress notes..." class="w-full p-1 border rounded text-xs">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Navigation -->
      <div class="pt-4 border-t border-gray-200 flex items-center justify-between">
        <button type="button" onclick="stepper.prevSection()" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-700 font-semibold text-xs hover:bg-gray-200">
          ← Back
        </button>
        <button type="button" onclick="stepper.saveAndNext(this)" class="px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 shadow-xs">
          Save & Next: Documents →
        </button>
      </div>
    </div>

    <!-- SECTION 7: DOCUMENTS & 100-150KB COMPRESSION -->
    <div id="step-7" class="form-section hidden bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
      <div class="border-b border-gray-200 pb-4">
        <div class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 uppercase tracking-wider mb-1">
          Policy P5.1 Document Compliance
        </div>
        <h2 class="text-xl font-bold text-gray-900">7. Mandatory Documents (Compressed to 100–150 KB)</h2>
        <p class="text-xs text-gray-500 mt-1">Identity documents are automatically compressed on your device to 100–150 KB before transmission.</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- Aadhaar Card -->
        <div class="p-4 rounded-xl border border-gray-200 bg-gray-50 space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-gray-800">Aadhaar Card (Team Members)</span>
            <?php if (isset($docsByType['AADHAAR'])): ?>
              <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-800">Uploaded ✓</span>
            <?php endif; ?>
          </div>
          <?php if (!$isReadOnly): ?>
            <input type="file" accept="image/*" onchange="handleCompressAndUpload(this, 'AADHAAR')" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
          <?php endif; ?>
          <div id="preview-AADHAAR" class="text-[11px] text-gray-500">
            <?php if (isset($docsByType['AADHAAR'])): ?>
              <a href="/uploads/<?= htmlspecialchars($docsByType['AADHAAR']['path']) ?>" target="_blank" class="text-blue-600 underline font-semibold">View uploaded Aadhaar file</a>
            <?php endif; ?>
          </div>
        </div>

        <!-- PAN Card -->
        <div class="p-4 rounded-xl border border-gray-200 bg-gray-50 space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-gray-800">PAN Card</span>
            <?php if (isset($docsByType['PAN'])): ?>
              <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-800">Uploaded ✓</span>
            <?php endif; ?>
          </div>
          <?php if (!$isReadOnly): ?>
            <input type="file" accept="image/*" onchange="handleCompressAndUpload(this, 'PAN')" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
          <?php endif; ?>
          <div id="preview-PAN" class="text-[11px] text-gray-500">
            <?php if (isset($docsByType['PAN'])): ?>
              <a href="/uploads/<?= htmlspecialchars($docsByType['PAN']['path']) ?>" target="_blank" class="text-blue-600 underline font-semibold">View uploaded PAN file</a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Passport Photo -->
        <div class="p-4 rounded-xl border border-gray-200 bg-gray-50 space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-gray-800">Passport-Size Photo</span>
            <?php if (isset($docsByType['PHOTO'])): ?>
              <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-800">Uploaded ✓</span>
            <?php endif; ?>
          </div>
          <?php if (!$isReadOnly): ?>
            <input type="file" accept="image/*" onchange="handleCompressAndUpload(this, 'PHOTO')" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
          <?php endif; ?>
          <div id="preview-PHOTO" class="text-[11px] text-gray-500">
            <?php if (isset($docsByType['PHOTO'])): ?>
              <a href="/uploads/<?= htmlspecialchars($docsByType['PHOTO']['path']) ?>" target="_blank" class="text-blue-600 underline font-semibold">View uploaded photo</a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Pitch Deck -->
        <div class="p-4 rounded-xl border border-gray-200 bg-gray-50 space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-gray-800">Pitch Deck (PDF, max 10MB)</span>
            <?php if (isset($docsByType['DECK'])): ?>
              <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-800">Uploaded ✓</span>
            <?php endif; ?>
          </div>
          <?php if (!$isReadOnly): ?>
            <input type="file" accept=".pdf" onchange="handleCompressAndUpload(this, 'DECK')" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
          <?php endif; ?>
          <div id="preview-DECK" class="text-[11px] text-gray-500">
            <?php if (isset($docsByType['DECK'])): ?>
              <a href="/uploads/<?= htmlspecialchars($docsByType['DECK']['path']) ?>" target="_blank" class="text-blue-600 underline font-semibold">View pitch deck PDF</a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Navigation -->
      <div class="pt-4 border-t border-gray-200 flex items-center justify-between">
        <button type="button" onclick="stepper.prevSection()" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-700 font-semibold text-xs hover:bg-gray-200">
          ← Back
        </button>
        <button type="button" onclick="stepper.saveAndNext(this)" class="px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 shadow-xs">
          Save & Next: Declaration →
        </button>
      </div>
    </div>

    <!-- SECTION 8: DECLARATION & SUBMISSION -->
    <div id="step-8" class="form-section hidden bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
      <div class="border-b border-gray-200 pb-4">
        <h2 class="text-xl font-bold text-gray-900">8. Applicant Declaration & Final Submission</h2>
        <p class="text-xs text-gray-500 mt-1">Review your seed grant request before locking the application for SEC evaluation.</p>
      </div>

      <!-- Application Summary Card -->
      <div class="p-5 rounded-2xl bg-gray-50 border border-gray-200 text-xs space-y-2.5">
        <div class="flex items-center justify-between">
          <span class="text-gray-500">Startup Venture:</span>
          <span class="font-bold text-gray-900"><?= htmlspecialchars($startup['teamName']) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-gray-500">Target Grant Scheme:</span>
          <span class="font-bold text-blue-700" id="summary-grant-tier"><?= htmlspecialchars(Constants::GRANT_TIERS[$application['grantType'] ?? 'PROOF']['name']) ?></span>
        </div>
        <div class="flex items-center justify-between border-t pt-2">
          <span class="text-gray-500">Direct Prototyping Expenses:</span>
          <span class="font-mono font-semibold text-gray-800" id="summary-direct-total">₹<?= number_format($directBudgetTotal, 2) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-gray-500">Mandatory 5% Contingency Buffer:</span>
          <span class="font-mono text-gray-600" id="summary-contingency">₹<?= number_format($contingencyBudgetTotal, 2) ?></span>
        </div>
        <div class="flex items-center justify-between border-t pt-2 bg-blue-50/50 -mx-5 px-5 py-2 rounded-b-xl">
          <span class="font-bold text-gray-900">Total Grant Requested:</span>
          <span class="font-black text-green-700 text-base font-mono" id="summary-total-requested">₹<?= number_format($currentRequestedTotal, 2) ?></span>
        </div>
        <div class="flex items-center justify-between pt-1">
          <span class="text-gray-500">Itemized Budget Breakdown:</span>
          <span class="font-semibold text-gray-700" id="summary-items-count"><?= count($budgetItems) ?> items</span>
        </div>
      </div>

      <!-- Policy Declaration Box (P6.10) -->
      <div class="p-5 rounded-xl bg-blue-50 border border-blue-200 space-y-3">
        <div class="flex items-start gap-3">
          <input 
            type="checkbox" 
            id="declarationSigned" 
            name="declarationSigned" 
            value="1" 
            <?= !empty($formData['declarationSigned']) ? 'checked' : '' ?>
            <?= $isReadOnly ? 'disabled' : '' ?>
            class="mt-1 h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <label for="declarationSigned" class="text-xs text-gray-900 font-bold leading-relaxed cursor-pointer">
            "<?= Constants::DECLARATION_TEXT ?>"
          </label>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-blue-200/80 text-xs text-gray-700">
          <div>
            <span>Digital Signature:</span> <strong><?= htmlspecialchars($startup['teamName']) ?> (<?= htmlspecialchars($user['name']) ?>)</strong>
          </div>
          <div>
            <span>Date:</span> <strong><?= htmlspecialchars($formData['declarationDate'] ?: date('Y-m-d')) ?></strong>
          </div>
        </div>
      </div>

      <!-- Final Submit Actions -->
      <div class="pt-4 border-t border-gray-200 flex items-center justify-between">
        <button type="button" onclick="stepper.prevSection()" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-700 font-semibold text-xs hover:bg-gray-200">
          ← Back
        </button>
        <?php if (!$isReadOnly): ?>
          <button 
            type="submit" 
            onclick="document.getElementById('form-action-input').value = 'final_submit'"
            class="px-6 py-3 rounded-xl bg-green-600 text-white font-extrabold text-sm hover:bg-green-700 shadow-md transition-all flex items-center gap-2"
          >
            <span>Submit Application & Lock Form</span>
            <span class="font-mono text-xs opacity-90">✓</span>
          </button>
        <?php else: ?>
          <div class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-blue-600 animate-pulse"></span>
            <span class="text-xs font-bold text-blue-700">Application Locked (Under SEC Evaluation)</span>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </form>

</div>

<!-- Client-side Scripts -->
<script src="/js/image-compressor.js"></script>
<script src="/js/save-next.js"></script>
<script>
function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

const stepper = new FormStepper({
  formId: 'startup-application-form',
  saveEndpoint: '/dashboard.php',
  onSectionChange: function (step) {
    history.replaceState(null, '', '#step-' + step);
    if (step === 5) syncJustifications();
    if (step === 8) updateDeclarationSummary();
  }
});

// Restore URL hash step if present
const currentHash = window.location.hash;
if (currentHash && currentHash.startsWith('#step-')) {
  const stepNum = parseInt(currentHash.replace('#step-', ''));
  if (stepNum >= 1 && stepNum <= 8) {
    stepper.goToSection(stepNum);
  }
}

// -------------------------------------------------------------
// Interactive Target Grant Type Selection (Issue 1 Fix)
// -------------------------------------------------------------
function updateGrantTierSelection(selectedTier) {
  document.querySelectorAll('.grant-card').forEach(card => {
    const isSelected = card.getAttribute('data-grant') === selectedTier;
    const radio = card.querySelector('input[type="radio"]');
    const check = card.querySelector('.grant-check');

    if (isSelected) {
      card.classList.add('border-blue-600', 'bg-blue-50/70', 'ring-2', 'ring-blue-600', 'shadow-sm');
      card.classList.remove('border-gray-200', 'hover:border-gray-300', 'bg-white');
      if (radio) radio.checked = true;
      if (check) check.classList.remove('hidden');
    } else {
      card.classList.remove('border-blue-600', 'bg-blue-50/70', 'ring-2', 'ring-blue-600', 'shadow-sm');
      card.classList.add('border-gray-200', 'hover:border-gray-300', 'bg-white');
      if (radio) radio.checked = false;
      if (check) check.classList.add('hidden');
    }
  });

  const tierNames = {
    'IDEATION': 'Ideation reward (₹1,000 – ₹5,000)',
    'VALIDATION': 'Validation Grant (₹5,000 – ₹20,000)',
    'PROOF': 'Proof Grant (₹25,000 – ₹50,000)',
    'SCALING': 'Scaling Grant (₹50,000 – ₹5,00,000)'
  };
  const displayName = tierNames[selectedTier] || selectedTier;

  const headerBadge = document.getElementById('header-grant-tier');
  if (headerBadge) headerBadge.innerText = displayName;

  const summaryBadge = document.getElementById('summary-grant-tier');
  if (summaryBadge) summaryBadge.innerText = displayName;
}

// -------------------------------------------------------------
// Dynamic Budget Table Serialization & Calculations (Issue 3 Fix)
// -------------------------------------------------------------
function addBudgetRow() {
  const tbody = document.getElementById('budget-tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td class="p-2.5">
      <select name="b_header[]" class="w-full p-1.5 border rounded-lg text-xs bg-gray-50">
        <?php foreach (Constants::BUDGET_HEADERS as $hK => $hV): ?>
          <option value="<?= $hK ?>"><?= htmlspecialchars($hV['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td class="p-2.5">
      <input type="text" name="b_name[]" class="w-full p-1.5 border rounded-lg text-xs" placeholder="Item name & specs" required>
    </td>
    <td class="p-2.5">
      <input type="text" name="b_vendor[]" class="w-full p-1.5 border rounded-lg text-xs" placeholder="Amazon, Robu.in, etc.">
    </td>
    <td class="p-2.5 text-right">
      <input type="number" step="0.01" name="b_unitCost[]" value="0" class="w-24 p-1.5 border rounded-lg text-xs text-right font-mono budget-calc" required>
    </td>
    <td class="p-2.5 text-center">
      <input type="number" name="b_qty[]" value="1" class="w-16 p-1.5 border rounded-lg text-xs text-center font-mono budget-calc" min="1" required>
    </td>
    <td class="p-2.5 text-right font-mono font-bold text-gray-900 row-total">
      ₹0.00
    </td>
    <td class="p-2.5 text-center">
      <button type="button" onclick="removeBudgetRow(this)" class="text-red-500 hover:text-red-700 font-bold">✕</button>
    </td>
  `;
  tbody.appendChild(tr);
  recalculateBudget();
  syncJustifications();
}

function removeBudgetRow(btn) {
  btn.closest('tr').remove();
  recalculateBudget();
  syncJustifications();
}

document.addEventListener('input', function(e) {
  if (e.target.classList.contains('budget-calc')) {
    recalculateBudget();
  }
});

function recalculateBudget() {
  let subtotal = 0;
  let count = 0;
  document.querySelectorAll('#budget-tbody tr').forEach(tr => {
    const uCost = parseFloat(tr.querySelector('input[name="b_unitCost[]"]')?.value || '0');
    const qty = parseInt(tr.querySelector('input[name="b_qty[]"]')?.value || '1');
    const tot = uCost * qty;
    subtotal += tot;
    count++;
    const totCell = tr.querySelector('.row-total');
    if (totCell) totCell.innerText = '₹' + tot.toLocaleString('en-IN', { minimumFractionDigits: 2 });
  });

  const contingency = Math.round(subtotal * 0.05 * 100) / 100;
  const grandTotal = subtotal + contingency;

  const subtotalEl = document.getElementById('live-budget-subtotal');
  if (subtotalEl) subtotalEl.innerText = '₹' + subtotal.toLocaleString('en-IN', { minimumFractionDigits: 2 });

  const contingencyEl = document.getElementById('live-budget-contingency');
  if (contingencyEl) contingencyEl.innerText = '₹' + contingency.toLocaleString('en-IN', { minimumFractionDigits: 2 });

  const liveTotalEl = document.getElementById('live-budget-total');
  if (liveTotalEl) liveTotalEl.innerText = '₹' + grandTotal.toLocaleString('en-IN', { minimumFractionDigits: 2 });

  const grandEl = document.getElementById('live-budget-grand');
  if (grandEl) grandEl.innerText = '₹' + grandTotal.toLocaleString('en-IN', { minimumFractionDigits: 2 });

  updateDeclarationSummary(grandTotal, subtotal, contingency, count);
}

// -------------------------------------------------------------
// Synchronize Justifications (Issue 2 Fix)
// -------------------------------------------------------------
function syncJustifications() {
  const container = document.getElementById('justifications-container');
  if (!container) return;

  const rows = document.querySelectorAll('#budget-tbody tr');
  if (rows.length === 0) {
    container.innerHTML = `
      <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs">
        <strong>No budget items found.</strong> Please click "← Back" and add required line items in Section 4 (Requirement Table) first.
      </div>
    `;
    return;
  }

  // Preserve any justifications already typed
  const existingValues = {};
  container.querySelectorAll('textarea').forEach(ta => {
    const idx = ta.getAttribute('data-item-index');
    if (idx !== null) existingValues[idx] = ta.value;
  });

  let html = '';
  rows.forEach((tr, idx) => {
    const headerSel = tr.querySelector('select[name="b_header[]"]');
    const headerText = headerSel ? (headerSel.options[headerSel.selectedIndex]?.text || headerSel.value) : 'Consumables';
    const name = tr.querySelector('input[name="b_name[]"]')?.value || `Item ${idx + 1}`;
    const vendor = tr.querySelector('input[name="b_vendor[]"]')?.value || 'Vendor not specified';
    const uCost = parseFloat(tr.querySelector('input[name="b_unitCost[]"]')?.value || '0');
    const qty = parseInt(tr.querySelector('input[name="b_qty[]"]')?.value || '1');
    const total = uCost * qty;
    const existingJust = existingValues[idx] || tr.getAttribute('data-justification') || '';

    html += `
      <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-2">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between text-xs font-bold text-gray-800 gap-1">
          <div class="flex items-center gap-2">
            <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-800 text-[11px] font-mono flex items-center justify-center font-bold">${idx + 1}</span>
            <span>${escapeHtml(name)} <span class="text-[10px] text-gray-500 font-normal">(${escapeHtml(headerText)} • ${escapeHtml(vendor)})</span></span>
          </div>
          <span class="text-blue-700 font-mono font-bold">₹${total.toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
        </div>
        <textarea 
          name="justification_item[${idx}]" 
          data-item-index="${idx}"
          rows="2" 
          placeholder="Explain why this ${escapeHtml(name)} is necessary for the experiment..."
          class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-xs focus:ring-2 focus:ring-blue-600 focus:outline-none"
        >${escapeHtml(existingJust)}</textarea>
      </div>
    `;
  });

  container.innerHTML = html;
}

// -------------------------------------------------------------
// Update Declaration Summary Card (Issue 3 Fix)
// -------------------------------------------------------------
function updateDeclarationSummary(grandTotal, directTotal, contingency, count) {
  if (grandTotal === undefined) {
    let sub = 0;
    let cnt = 0;
    document.querySelectorAll('#budget-tbody tr').forEach(tr => {
      const uCost = parseFloat(tr.querySelector('input[name="b_unitCost[]"]')?.value || '0');
      const qty = parseInt(tr.querySelector('input[name="b_qty[]"]')?.value || '1');
      sub += (uCost * qty);
      cnt++;
    });
    directTotal = sub;
    contingency = Math.round(sub * 0.05 * 100) / 100;
    grandTotal = directTotal + contingency;
    count = cnt;
  }

  const reqEl = document.getElementById('summary-total-requested');
  if (reqEl) reqEl.innerText = '₹' + grandTotal.toLocaleString('en-IN', { minimumFractionDigits: 2 });

  const dirEl = document.getElementById('summary-direct-total');
  if (dirEl) dirEl.innerText = '₹' + directTotal.toLocaleString('en-IN', { minimumFractionDigits: 2 });

  const contEl = document.getElementById('summary-contingency');
  if (contEl) contEl.innerText = '₹' + contingency.toLocaleString('en-IN', { minimumFractionDigits: 2 });

  const cntEl = document.getElementById('summary-items-count');
  if (cntEl) cntEl.innerText = count + ' items';
}

// Pre-save hook called by FormStepper
window.prepareSectionDataForSave = function(step) {
  if (step === 4) {
    const items = [];
    document.querySelectorAll('#budget-tbody tr').forEach(tr => {
      items.push({
        header: tr.querySelector('select[name="b_header[]"]')?.value,
        name: tr.querySelector('input[name="b_name[]"]')?.value,
        vendor: tr.querySelector('input[name="b_vendor[]"]')?.value,
        unitCost: parseFloat(tr.querySelector('input[name="b_unitCost[]"]')?.value || '0'),
        qty: parseInt(tr.querySelector('input[name="b_qty[]"]')?.value || '1'),
        justification: tr.getAttribute('data-justification') || ''
      });
    });

    let hiddenInput = document.getElementById('budget_items_json');
    if (!hiddenInput) {
      hiddenInput = document.createElement('input');
      hiddenInput.type = 'hidden';
      hiddenInput.name = 'budget_items_json';
      hiddenInput.id = 'budget_items_json';
      document.getElementById('startup-application-form').appendChild(hiddenInput);
    }
    hiddenInput.value = JSON.stringify(items);
  }
};

window.syncJustifications = syncJustifications;
window.updateDeclarationSummary = updateDeclarationSummary;

function serializeAndSaveBudget(btn) {
  window.prepareSectionDataForSave(4);
  stepper.saveAndNext(btn);
}

// -------------------------------------------------------------
// Client-Side 100-150KB Image Compression & Upload
// -------------------------------------------------------------
async function handleCompressAndUpload(inputEl, docType) {
  if (!inputEl.files || !inputEl.files[0]) return;
  const rawFile = inputEl.files[0];
  const previewDiv = document.getElementById('preview-' + docType);

  try {
    previewDiv.innerHTML = `<span class="text-blue-600 animate-pulse font-bold">Compressing to 100-150 KB...</span>`;

    // Perform iterative HTML5 Canvas compression
    const result = await ImageCompressor.compress(rawFile);

    previewDiv.innerHTML = `
      <div class="flex items-center gap-2 text-green-700 font-bold">
        <span>✓ Compressed: ${ImageCompressor.formatBytes(result.compressedSize)}</span>
        <span class="text-[10px] bg-green-100 px-1.5 py-0.5 rounded text-green-800">-${result.reductionPercent}%</span>
      </div>
      <img src="${result.dataUrl}" class="h-16 w-auto rounded border mt-1 object-cover">
    `;

    // Upload compressed file to server
    const fd = new FormData();
    fd.append('action', 'upload_document');
    fd.append('docType', docType);
    fd.append('file', result.file);

    const res = await fetch('/dashboard.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });

    const data = await res.json();
    if (data.success) {
      stepper.showToast(docType + ' uploaded successfully (100-150 KB).', 'success');
      setTimeout(() => location.reload(), 1000);
    }
  } catch (err) {
    previewDiv.innerHTML = `<span class="text-red-600 font-bold">Error: ${err.message}</span>`;
  }
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
