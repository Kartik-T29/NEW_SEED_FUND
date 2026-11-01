<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Database;
use App\Config\Constants;
use App\Config\Auth;

Auth::requireRole('STARTUP');
$user = Auth::user();
$pdo = Database::getConnection();

// Fetch startup record
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

// Fetch budget items
$bStmt = $pdo->prepare('SELECT * FROM `BudgetItem` WHERE applicationId = ? ORDER BY header ASC, id ASC');
$bStmt->execute([$application['id']]);
$budgetItems = $bStmt->fetchAll();

// Fetch uploaded documents
$dStmt = $pdo->prepare('SELECT * FROM `Document` WHERE applicationId = ? ORDER BY createdAt ASC');
$dStmt->execute([$application['id']]);
$documents = $dStmt->fetchAll();

// -------------------------------------------------------------
// POST Request Handlers (AJAX Partial Save, Upload, Submit)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isReadOnly) {
    $action = $_POST['action'] ?? '';

    // 1. Partial Section Save (AJAX or direct form POST)
    if ($action === 'save_draft' || isset($_POST['partial_step'])) {
        $grantType = $_POST['grantType'] ?? ($application['grantType'] ?? 'PROOF');
        
        // Update form data state
        if (isset($_POST['formData'])) {
            $submittedForm = json_decode($_POST['formData'], true);
            if (is_array($submittedForm)) {
                $formData = array_merge($formData, $submittedForm);
            }
        } else {
            // Read direct form field inputs
            if (isset($_POST['problemStatement'])) $formData['problemStatement'] = trim((string)$_POST['problemStatement']);
            if (isset($_POST['proposedSolution'])) $formData['proposedSolution'] = trim((string)$_POST['proposedSolution']);
            if (isset($_POST['effortTraction'])) $formData['effortTraction'] = trim((string)$_POST['effortTraction']);
            if (isset($_POST['agreementAccepted'])) $formData['agreementAccepted'] = (bool)$_POST['agreementAccepted'];
            if (isset($_POST['declarationSigned'])) $formData['declarationSigned'] = (bool)$_POST['declarationSigned'];
            if (isset($_POST['declarationDate'])) $formData['declarationDate'] = trim((string)$_POST['declarationDate']);

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
        }

        // Budget items handling
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
                $application['requestedTotal'] = $calcTotal;
            }
        }

        // Recalculate total from BudgetItem table
        $totStmt = $pdo->prepare('SELECT COALESCE(SUM(total), 0) FROM `BudgetItem` WHERE applicationId = ?');
        $totStmt->execute([$application['id']]);
        $requestedTotal = (float)$totStmt->fetchColumn();

        // Update Application record
        $upd = $pdo->prepare('UPDATE `Application` SET grantType = ?, requestedTotal = ?, formData = ?, updatedAt = NOW(3) WHERE id = ?');
        $upd->execute([$grantType, $requestedTotal, json_encode($formData, JSON_UNESCAPED_UNICODE), $application['id']]);

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_POST['partial_step']) && empty($_POST['is_full_submit']))) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'requestedTotal' => $requestedTotal]);
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

        if (count($budgetItems) === 0) {
            $errors[] = "Section 4: Itemized budget must contain at least one item.";
        }

        if (empty($formData['declarationSigned'])) {
            $errors[] = "Section 8: You must sign the declaration statement before submitting.";
        }

        if (count($errors) === 0) {
            $sub = $pdo->prepare('UPDATE `Application` SET status = "SUBMITTED", submittedAt = NOW(3), updatedAt = NOW(3) WHERE id = ?');
            $sub->execute([$application['id']]);
            Auth::audit('SUBMIT_APPLICATION', 'Application', $application['id'], ['total' => $application['requestedTotal'], 'grantType' => $application['grantType']]);

            header('Location: /status.php');
            exit;
        } else {
            $submitErrors = $errors;
        }
    }
}

$pageTitle = "Startup Application Form | IDEAPAD Seed Fund";
require_once __DIR__ . '/../templates/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

  <!-- Status & Application Header -->
  <div class="bg-white rounded-2xl p-6 shadow-xs border border-gray-200 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
      <div class="flex items-center gap-2">
        <span class="text-xs font-bold text-gray-500 uppercase tracking-wider">Application #<?= htmlspecialchars($application['id']) ?></span>
        <span class="px-2.5 py-0.5 rounded-full text-xs font-extrabold <?= $application['status'] === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' : ($application['status'] === 'SUBMITTED' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800') ?>">
          <?= htmlspecialchars($application['status']) ?>
        </span>
      </div>
      <h1 class="text-2xl font-extrabold text-gray-900 mt-1"><?= htmlspecialchars($startup['teamName']) ?></h1>
      <p class="text-xs text-gray-500">
        Lead Applicant: <strong><?= htmlspecialchars($startup['teamName']) ?></strong> (<?= htmlspecialchars($startup['enrollmentNo']) ?>, <?= htmlspecialchars($startup['program']) ?>)
      </p>
    </div>

    <div class="flex items-center gap-3">
      <?php if ($isReadOnly): ?>
        <a href="/reports.php?id=<?= urlencode($application['id']) ?>&type=application" target="_blank" class="px-4 py-2 bg-blue-50 text-blue-700 font-semibold text-xs rounded-xl hover:bg-blue-100 flex items-center gap-1.5 border border-blue-200">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
          Print Proposal Dossier
        </a>
      <?php else: ?>
        <button type="button" onclick="stepper.saveAndNext(null)" class="px-4 py-2 bg-gray-100 text-gray-700 font-semibold text-xs rounded-xl hover:bg-gray-200 border border-gray-300 flex items-center gap-1.5">
          Save Draft
        </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($submitErrors)): ?>
    <div class="p-5 rounded-2xl bg-red-50 border border-red-200 text-sm text-red-700 space-y-2">
      <div class="font-bold flex items-center gap-2 text-red-800">
        <svg class="w-5 h-5 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        Please complete all required sections before submitting:
      </div>
      <ul class="list-disc pl-5 space-y-1 text-xs">
        <?php foreach ($submitErrors as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- 8-Section Horizontal Navigation Stepper -->
  <div class="bg-white rounded-2xl p-2.5 shadow-xs border border-gray-200 overflow-x-auto scrollbar-none">
    <div class="flex items-center min-w-max gap-1 text-xs font-semibold">
      <button type="button" data-step="1" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-bold flex items-center justify-center">1</span>
        Agreement
      </button>
      <button type="button" data-step="2" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold flex items-center justify-center">2</span>
        Basic Info
      </button>
      <button type="button" data-step="3" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold flex items-center justify-center">3</span>
        10 Experiments
        <span id="experiment-count-badge" class="px-1.5 py-0.2 rounded-full text-[10px] bg-gray-100 text-gray-600">0/10</span>
      </button>
      <button type="button" data-step="4" class="stepper-nav-item px-3 py-2 rounded-xl flex items-center gap-1.5 transition-all">
        <span class="w-5 h-5 rounded-full bg-gray-100 text-gray-700 text-[10px] font-bold flex items-center justify-center">4</span>
        Budget (9 Heads)
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
    <input type="hidden" name="action" value="save_draft">
    <input type="hidden" name="grantType" id="grantTypeInput" value="<?= htmlspecialchars($application['grantType'] ?? 'PROOF') ?>">

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
        <div><span class="text-gray-500">Applicant Name:</span> <strong><?= htmlspecialchars($startup['teamName']) ?></strong></div>
        <div><span class="text-gray-500">Venture Team:</span> <strong><?= htmlspecialchars($startup['teamName']) ?></strong></div>
        <div><span class="text-gray-500">Enrollment No:</span> <strong><?= htmlspecialchars($startup['enrollmentNo']) ?></strong></div>
        <div><span class="text-gray-500">Program & Dept:</span> <strong><?= htmlspecialchars($startup['program']) ?></strong></div>
        <div><span class="text-gray-500">Contact Mobile:</span> <strong><?= htmlspecialchars($startup['phone']) ?></strong></div>
        <div><span class="text-gray-500">Official Email:</span> <strong><?= htmlspecialchars($user['email']) ?></strong></div>
      </div>

      <!-- Selected Grant Tier Selection -->
      <div>
        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-2">Target Grant Type (Policy P2)</label>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <?php foreach (Constants::GRANT_TIERS as $tKey => $tier): if ($tKey === 'EMERGENCY') continue; ?>
            <label class="cursor-pointer border rounded-xl p-3 flex flex-col justify-between text-xs transition-all <?= ($application['grantType'] ?? 'PROOF') === $tKey ? 'border-blue-600 bg-blue-50/50 ring-2 ring-blue-600' : 'border-gray-200 hover:border-gray-300' ?>">
              <div>
                <input 
                  type="radio" 
                  name="grantTypeSelect" 
                  value="<?= $tKey ?>" 
                  <?= ($application['grantType'] ?? 'PROOF') === $tKey ? 'checked' : '' ?>
                  <?= $isReadOnly ? 'disabled' : '' ?>
                  onchange="document.getElementById('grantTypeInput').value = this.value"
                  class="sr-only"
                />
                <span class="block font-bold text-gray-900"><?= htmlspecialchars($tier['name']) ?></span>
                <span class="block text-blue-600 font-extrabold mt-1">₹<?= number_format($tier['min']) ?> – ₹<?= number_format($tier['max']) ?></span>
              </div>
              <span class="block text-[11px] text-gray-500 mt-2"><?= htmlspecialchars($tier['target_trl']) ?></span>
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
          placeholder="e.g. We are building an edge IoT sensor node that detects anomalies..."
          class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-xs focus:ring-2 focus:ring-blue-600 focus:bg-white focus:outline-none"
        ><?= htmlspecialchars($formData['proposedSolution'] ?? '') ?></textarea>
      </div>

      <!-- Effort and Traction -->
      <div>
        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1">
          Effort and traction so far (as short as possible) *
        </label>
        <textarea 
          name="effortTraction" 
          rows="3" 
          maxlength="800"
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
        <h2 class="text-xl font-bold text-gray-900">3. Experiment Definition (10 Structured Headings)</h2>
        <p class="text-xs text-gray-500 mt-1">Explain in detail the specific experiment you will conduct to assess your solution and create proof-of-concept (TRL-3 evidence).</p>
      </div>

      <div class="space-y-5">
        <?php foreach (Constants::EXPERIMENT_HEADINGS as $idx => $exp): ?>
          <div class="p-4 rounded-xl bg-gray-50/70 border border-gray-200 space-y-2">
            <div class="flex items-center justify-between">
              <label class="block text-xs font-bold text-gray-900 uppercase tracking-wider">
                <?= $idx ?>. <?= htmlspecialchars($exp['title']) ?> *
              </label>
              <span class="text-[11px] text-gray-500 font-normal"><?= htmlspecialchars($exp['prompt']) ?></span>
            </div>
            <textarea 
              name="exp[<?= $idx ?>]" 
              rows="2" 
              maxlength="800"
              <?= $isReadOnly ? 'readonly' : '' ?>
              placeholder="Provide details for <?= htmlspecialchars($exp['title']) ?>..."
              class="w-full px-3.5 py-2.5 bg-white border border-gray-300 rounded-xl text-xs focus:ring-2 focus:ring-blue-600 focus:outline-none"
            ><?= htmlspecialchars($formData['experiments'][$idx] ?? '') ?></textarea>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Navigation -->
      <div class="pt-4 border-t border-gray-200 flex items-center justify-between">
        <button type="button" onclick="stepper.prevSection()" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-700 font-semibold text-xs hover:bg-gray-200">
          ← Back
        </button>
        <button type="button" onclick="stepper.saveAndNext(this)" class="px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 shadow-xs">
          Save & Next: Itemized Budget →
        </button>
      </div>
    </div>

    <!-- SECTION 4: REQUIREMENT TABLE / BUDGET (ANNEXURE 1) -->
    <div id="step-4" class="form-section hidden bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
      <div class="border-b border-gray-200 pb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <div>
          <h2 class="text-xl font-bold text-gray-900">4. Detailed Requirement Table (Annexure 1)</h2>
          <p class="text-xs text-gray-500 mt-1">Itemized cost breakdown grouped under the 9 major grant headers.</p>
        </div>
        <div class="text-right">
          <span class="text-xs text-gray-500 block">Total Requested:</span>
          <span class="text-xl font-extrabold text-blue-600" id="live-budget-total">₹<?= number_format((float)$application['requestedTotal'], 2) ?></span>
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
              <tr data-row-idx="<?= $bIdx ?>">
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
        </table>
      </div>

      <?php if (!$isReadOnly): ?>
        <div>
          <button type="button" onclick="addBudgetRow()" class="px-4 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-xs flex items-center gap-1.5 border border-gray-300">
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
        <?php foreach ($budgetItems as $jIdx => $item): ?>
          <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-2">
            <div class="flex items-center justify-between text-xs font-bold text-gray-800">
              <span>Item <?= $jIdx + 1 ?>: <?= htmlspecialchars($item['name']) ?> (<?= htmlspecialchars($item['header']) ?>)</span>
              <span class="text-blue-600 font-mono">₹<?= number_format((float)$item['total'], 2) ?></span>
            </div>
            <textarea 
              name="justification_item[<?= $item['id'] ?>]" 
              rows="2" 
              <?= $isReadOnly ? 'readonly' : '' ?>
              placeholder="Explain why this item is necessary for the experiment..."
              class="w-full p-2.5 bg-white border border-gray-300 rounded-lg text-xs"
            ><?= htmlspecialchars($item['justification'] ?? '') ?></textarea>
          </div>
        <?php endforeach; ?>
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
        <p class="text-xs text-gray-500 mt-1">Assess your current project readiness on TRL, MRL, and IRL scales (Policy P6.8).</p>
      </div>

      <!-- 3 Readiness Scales -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-2">
          <label class="block text-xs font-bold text-blue-700 uppercase tracking-wider">Technology Readiness Level (TRL)</label>
          <select name="self_trl" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full p-2.5 border rounded-xl text-xs bg-white">
            <?php foreach (Constants::TRL_LEVELS as $lvl => $desc): ?>
              <option value="<?= $lvl ?>" <?= ($formData['selfAssessment']['trl'] ?? 1) == $lvl ? 'selected' : '' ?>>
                TRL <?= $lvl ?>: <?= htmlspecialchars($desc) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-2">
          <label class="block text-xs font-bold text-purple-700 uppercase tracking-wider">Manufacturing Readiness Level (MRL)</label>
          <select name="self_mrl" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full p-2.5 border rounded-xl text-xs bg-white">
            <?php foreach (Constants::MRL_LEVELS as $lvl => $desc): ?>
              <option value="<?= $lvl ?>" <?= ($formData['selfAssessment']['mrl'] ?? 1) == $lvl ? 'selected' : '' ?>>
                MRL <?= $lvl ?>: <?= htmlspecialchars($desc) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 space-y-2">
          <label class="block text-xs font-bold text-emerald-700 uppercase tracking-wider">Investment Readiness Level (IRL)</label>
          <select name="self_irl" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full p-2.5 border rounded-xl text-xs bg-white">
            <?php foreach (Constants::IRL_LEVELS as $lvl => $desc): ?>
              <option value="<?= $lvl ?>" <?= ($formData['selfAssessment']['irl'] ?? 1) == $lvl ? 'selected' : '' ?>>
                IRL <?= $lvl ?>: <?= htmlspecialchars($desc) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Milestone Track Self-Assessment -->
      <div class="pt-4 border-t border-gray-200 space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-bold text-gray-900">Milestone Achievement Self-Assessment (P6.9)</h3>
          <select name="self_track" id="self-track-select" onchange="toggleMilestoneTrack(this.value)" class="text-xs p-1.5 border rounded-lg bg-gray-50 font-semibold">
            <option value="PROTOTYPING" <?= ($formData['selfAssessment']['track'] ?? 'PROTOTYPING') === 'PROTOTYPING' ? 'selected' : '' ?>>Track A: Idea Prototyping & PoC</option>
            <option value="PROFESSIONAL" <?= ($formData['selfAssessment']['track'] ?? '') === 'PROFESSIONAL' ? 'selected' : '' ?>>Track B: Professional & Entrepreneurship</option>
          </select>
        </div>

        <div class="space-y-3">
          <?php foreach (Constants::MILESTONES_TRACK_A as $mIdx => $mName): ?>
            <div class="p-3.5 rounded-xl bg-gray-50 border border-gray-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
              <span class="font-medium text-gray-800 sm:w-1/2"><?= htmlspecialchars($mName) ?></span>
              <div class="flex items-center gap-3 sm:w-1/2">
                <select name="milestones[<?= htmlspecialchars($mName) ?>][status]" <?= $isReadOnly ? 'disabled' : '' ?> class="p-1.5 border rounded-lg bg-white text-xs">
                  <option value="PENDING" <?= ($formData['selfAssessment']['milestones'][$mName]['status'] ?? '') === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                  <option value="COMPLETED" <?= ($formData['selfAssessment']['milestones'][$mName]['status'] ?? '') === 'COMPLETED' ? 'selected' : '' ?>>Completed</option>
                </select>
                <input 
                  type="text" 
                  name="milestones[<?= htmlspecialchars($mName) ?>][remarks]" 
                  value="<?= htmlspecialchars($formData['selfAssessment']['milestones'][$mName]['remarks'] ?? '') ?>"
                  <?= $isReadOnly ? 'readonly' : '' ?>
                  placeholder="Remarks / evidence link..."
                  class="flex-1 p-1.5 border rounded-lg bg-white text-xs"
                />
              </div>
            </div>
          <?php endforeach; ?>
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

    <!-- SECTION 7: DOCUMENTS & 100-150KB IMAGE COMPRESSOR -->
    <div id="step-7" class="form-section hidden bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-gray-200 space-y-6">
      <div class="border-b border-gray-200 pb-4">
        <div class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 uppercase tracking-wider mb-1">
          Policy P5.1 Verification
        </div>
        <h2 class="text-xl font-bold text-gray-900">7. Mandatory Supporting Documents</h2>
        <p class="text-xs text-gray-500 mt-1">Aadhaar card, PAN card, and passport photos are automatically compressed client-side to <strong>100 KB – 150 KB</strong> before upload.</p>
      </div>

      <!-- Document Upload Cards -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        
        <!-- Aadhaar Card -->
        <div class="p-5 rounded-2xl bg-gray-50 border border-gray-200 space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-gray-900">Aadhaar Card *</span>
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 font-bold">100–150 KB</span>
          </div>
          <p class="text-[11px] text-gray-500">Government ID for identity verification.</p>
          <?php if (!$isReadOnly): ?>
            <input type="file" accept="image/*" onchange="handleCompressAndUpload(this, 'AADHAAR')" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            <div id="preview-AADHAAR" class="text-[11px] text-gray-600 space-y-1"></div>
          <?php endif; ?>
        </div>

        <!-- PAN Card -->
        <div class="p-5 rounded-2xl bg-gray-50 border border-gray-200 space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-gray-900">PAN Card *</span>
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 font-bold">100–150 KB</span>
          </div>
          <p class="text-[11px] text-gray-500">Permanent Account Number card of team leader.</p>
          <?php if (!$isReadOnly): ?>
            <input type="file" accept="image/*" onchange="handleCompressAndUpload(this, 'PAN')" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            <div id="preview-PAN" class="text-[11px] text-gray-600 space-y-1"></div>
          <?php endif; ?>
        </div>

        <!-- Passport Photo -->
        <div class="p-5 rounded-2xl bg-gray-50 border border-gray-200 space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-gray-900">Passport Photo *</span>
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 font-bold">100–150 KB</span>
          </div>
          <p class="text-[11px] text-gray-500">Recent formal passport-size photo of founder.</p>
          <?php if (!$isReadOnly): ?>
            <input type="file" accept="image/*" onchange="handleCompressAndUpload(this, 'PHOTO')" class="block w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            <div id="preview-PHOTO" class="text-[11px] text-gray-600 space-y-1"></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Uploaded Documents Table -->
      <div class="pt-4 border-t border-gray-200 space-y-3">
        <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Uploaded Documents Ledger</h4>
        <div class="overflow-x-auto border border-gray-200 rounded-xl">
          <table class="w-full text-left text-xs">
            <thead class="bg-gray-50 text-gray-700 font-bold uppercase text-[10px]">
              <tr>
                <th class="p-3">Type</th>
                <th class="p-3">Filename / Label</th>
                <th class="p-3">Upload Date</th>
                <?php if (!$isReadOnly): ?><th class="p-3 text-center">Action</th><?php endif; ?>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white" id="uploaded-docs-tbody">
              <?php if (empty($documents)): ?>
                <tr><td colspan="4" class="p-4 text-center text-gray-400">No documents uploaded yet.</td></tr>
              <?php else: foreach ($documents as $doc): ?>
                <tr>
                  <td class="p-3 font-bold text-blue-700"><?= htmlspecialchars($doc['type']) ?></td>
                  <td class="p-3 text-gray-900"><?= htmlspecialchars($doc['memberLabel'] ?? $doc['path']) ?></td>
                  <td class="p-3 text-gray-500"><?= htmlspecialchars(substr($doc['createdAt'], 0, 10)) ?></td>
                  <?php if (!$isReadOnly): ?>
                    <td class="p-3 text-center">
                      <button type="button" onclick="deleteDocument('<?= $doc['id'] ?>')" class="text-red-600 hover:underline font-bold">Remove</button>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; endif; ?>
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
          Save & Next: Declaration & Submit →
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
      <div class="p-5 rounded-2xl bg-gray-50 border border-gray-200 text-xs space-y-2">
        <div class="flex items-center justify-between">
          <span class="text-gray-500">Venture:</span>
          <span class="font-bold text-gray-900"><?= htmlspecialchars($startup['teamName']) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-gray-500">Target Grant:</span>
          <span class="font-bold text-blue-700"><?= htmlspecialchars(Constants::GRANT_TIERS[$application['grantType'] ?? 'PROOF']['name']) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-gray-500">Total Requested:</span>
          <span class="font-bold text-green-700 text-sm">₹<?= number_format((float)$application['requestedTotal'], 2) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-gray-500">Itemized Items:</span>
          <span class="font-bold text-gray-800"><?= count($budgetItems) ?> items</span>
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
          <label for="declarationSigned" class="text-xs text-gray-900 font-bold leading-relaxed">
            "<?= Constants::DECLARATION_TEXT ?>"
          </label>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-blue-200/80 text-xs text-gray-700">
          <div>
            <span>Digital Signature:</span> <strong><?= htmlspecialchars($startup['teamName']) ?></strong>
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
            onclick="document.querySelector('input[name=action]').value = 'final_submit'"
            class="px-6 py-3 rounded-xl bg-green-600 text-white font-extrabold text-sm hover:bg-green-700 shadow-md transition-all"
          >
            Submit Application & Lock Form ✓
          </button>
        <?php else: ?>
          <span class="text-xs font-bold text-blue-700">Application Locked (Under Evaluation)</span>
        <?php endif; ?>
      </div>
    </div>

  </form>

</div>

<!-- Client-side Scripts -->
<script src="/js/image-compressor.js"></script>
<script src="/js/save-next.js"></script>
<script>
const stepper = new FormStepper({
  formId: 'startup-application-form',
  saveEndpoint: '/dashboard.php',
  onSectionChange: function (step) {
    history.replaceState(null, '', '#step-' + step);
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
// Dynamic Budget Table Serialization & Calculations
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
      <input type="text" name="b_vendor[]" class="w-full p-1.5 border rounded-lg text-xs" placeholder="Vendor / Source">
    </td>
    <td class="p-2.5 text-right">
      <input type="number" step="0.01" name="b_unitCost[]" value="0" class="w-24 p-1.5 border rounded-lg text-xs text-right font-mono budget-calc" required>
    </td>
    <td class="p-2.5 text-center">
      <input type="number" name="b_qty[]" value="1" class="w-16 p-1.5 border rounded-lg text-xs text-center font-mono budget-calc" min="1" required>
    </td>
    <td class="p-2.5 text-right font-mono font-bold text-gray-900 row-total">₹0.00</td>
    <td class="p-2.5 text-center">
      <button type="button" onclick="removeBudgetRow(this)" class="text-red-500 hover:text-red-700 font-bold">✕</button>
    </td>
  `;
  tbody.appendChild(tr);
  recalculateBudget();
}

function removeBudgetRow(btn) {
  btn.closest('tr').remove();
  recalculateBudget();
}

document.addEventListener('input', function(e) {
  if (e.target.classList.contains('budget-calc')) {
    recalculateBudget();
  }
});

function recalculateBudget() {
  let grandTotal = 0;
  document.querySelectorAll('#budget-tbody tr').forEach(tr => {
    const uCost = parseFloat(tr.querySelector('input[name="b_unitCost[]"]')?.value || '0');
    const qty = parseInt(tr.querySelector('input[name="b_qty[]"]')?.value || '1');
    const tot = uCost * qty;
    grandTotal += tot;
    const totCell = tr.querySelector('.row-total');
    if (totCell) totCell.innerText = '₹' + tot.toLocaleString('en-IN', { minimumFractionDigits: 2 });
  });
  document.getElementById('live-budget-total').innerText = '₹' + grandTotal.toLocaleString('en-IN', { minimumFractionDigits: 2 });
}

function serializeAndSaveBudget(btn) {
  const items = [];
  document.querySelectorAll('#budget-tbody tr').forEach(tr => {
    items.push({
      header: tr.querySelector('select[name="b_header[]"]')?.value,
      name: tr.querySelector('input[name="b_name[]"]')?.value,
      vendor: tr.querySelector('input[name="b_vendor[]"]')?.value,
      unitCost: parseFloat(tr.querySelector('input[name="b_unitCost[]"]')?.value || '0'),
      qty: parseInt(tr.querySelector('input[name="b_qty[]"]')?.value || '1')
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

async function deleteDocument(docId) {
  if (!confirm('Are you sure you want to remove this document?')) return;
  const fd = new FormData();
  fd.append('action', 'delete_document');
  fd.append('docId', docId);

  await fetch('/dashboard.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: fd
  });
  location.reload();
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
