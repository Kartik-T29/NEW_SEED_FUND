<?php
/**
 * IDEAPAD Seed Fund Portal - Application Status & Milestone Progress Tracker
 * 100% Pure Server-Rendered PHP
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Database;
use App\Config\Auth;
use App\Config\Constants;

Auth::requireLogin();
$currentUser = Auth::user();
$pdo = Database::getConnection();

$appId = trim($_GET['id'] ?? '');

// If founder and no ID passed, get their active application
if ($currentUser['role'] === 'STARTUP') {
    $stmtStartup = $pdo->prepare('SELECT id FROM `Startup` WHERE userId = ? LIMIT 1');
    $stmtStartup->execute([$currentUser['id']]);
    $startup = $stmtStartup->fetch();
    
    if (!$startup) {
        header("Location: dashboard.php");
        exit;
    }
    
    $stmtApp = $pdo->prepare('SELECT id FROM `Application` WHERE startupId = ? ORDER BY version DESC, createdAt DESC LIMIT 1');
    $stmtApp->execute([$startup['id']]);
    $appRow = $stmtApp->fetch();
    
    if ($appRow) {
        $appId = $appRow['id'];
    }
}

if (empty($appId)) {
    header("Location: dashboard.php");
    exit;
}

// Fetch application, startup, and founder user details
$stmt = $pdo->prepare('
    SELECT a.*, s.teamName, s.enrollmentNo, s.program, s.phone,
           u.name AS founderName, u.email AS founderEmail
    FROM `Application` a
    JOIN `Startup` s ON a.startupId = s.id
    JOIN `User` u ON s.userId = u.id
    WHERE a.id = ?
    LIMIT 1
');
$stmt->execute([$appId]);
$app = $stmt->fetch();

if (!$app) {
    die("Application not found. <a href='dashboard.php'>Return to Dashboard</a>");
}

// Security: Founder can only view their own startup
if ($currentUser['role'] === 'STARTUP') {
    $stmtCheck = $pdo->prepare('SELECT id FROM `Startup` WHERE userId = ? LIMIT 1');
    $stmtCheck->execute([$currentUser['id']]);
    $chk = $stmtCheck->fetch();
    if (!$chk || $chk['id'] !== $app['startupId']) {
        die("Unauthorized access.");
    }
}

// Fetch Form B Evaluation if available
$stmtEval = $pdo->prepare('SELECT * FROM `GrantEvaluation` WHERE applicationId = ? LIMIT 1');
$stmtEval->execute([$appId]);
$evaluation = $stmtEval->fetch();

// Parse formData
$formData = json_decode($app['formData'] ?? '{}', true) ?: [];
$selfAssessment = $formData['selfAssessment'] ?? [];
$selfMilestones = $selfAssessment['milestones'] ?? [];

// Fetch documents
$stmtDocs = $pdo->prepare('SELECT * FROM `Document` WHERE applicationId = ? ORDER BY createdAt DESC');
$stmtDocs->execute([$appId]);
$documents = $stmtDocs->fetchAll();

$evalTimeline = !empty($evaluation['timeline']) ? json_decode($evaluation['timeline'], true) : [];

// Handle Bill / Invoice upload post-approval
$uploadMessage = '';
$uploadError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_bill') {
    Auth::verifyCsrf();
    if (isset($_FILES['bill_file']) && $_FILES['bill_file']['error'] === UPLOAD_ERR_OK) {
        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
        $origName = $_FILES['bill_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowedExts, true)) {
            $uploadError = 'Invalid file type. Allowed formats: PDF, JPG, PNG.';
        } elseif ($_FILES['bill_file']['size'] > 5 * 1024 * 1024) {
            $uploadError = 'File size exceeds maximum limit of 5 MB.';
        } else {
            $uploadDir = __DIR__ . '/../uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $safeName = 'bill_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $destPath = $uploadDir . $safeName;
            
            if (move_uploaded_file($_FILES['bill_file']['tmp_name'], $destPath)) {
                $stmtInsertDoc = $pdo->prepare('
                    INSERT INTO `Document` (id, applicationId, type, memberLabel, path, createdAt)
                    VALUES (?, ?, "OTHER", ?, ?, NOW(3))
                ');
                $stmtInsertDoc->execute([
                    Auth::generateId('doc'),
                    $appId,
                    $origName,
                    'uploads/' . $safeName
                ]);
                $uploadMessage = 'Invoice / utilization bill uploaded successfully.';
                // Refresh docs
                $stmtDocs->execute([$appId]);
                $documents = $stmtDocs->fetchAll();
            } else {
                $uploadError = 'Failed to save uploaded file.';
            }
        }
    } else {
        $uploadError = 'Please select a valid document to upload.';
    }
}

// Lifecycle step determination
$steps = [
    ['key' => 'DRAFT', 'label' => '1. Draft Formulated', 'desc' => 'Applicant filling application'],
    ['key' => 'SUBMITTED', 'label' => '2. Submitted to SEC', 'desc' => 'Locked & dossier under screening'],
    ['key' => 'IN_REVIEW', 'label' => '3. SEC Evaluation', 'desc' => 'Presentation & rubric scoring'],
    ['key' => 'APPROVED', 'label' => '4. Sanction Approved', 'desc' => 'Form B issued & funds ready'],
];

$currentStatus = $app['status'];
$isRejected = ($currentStatus === 'REJECTED');

$pageTitle = "Grant Status Tracker | " . htmlspecialchars($app['teamName']);
require_once __DIR__ . '/../templates/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Top Breadcrumb & Status Banner -->
    <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">
                <span>Application Lifecycle</span>
                <span>•</span>
                <span class="font-mono text-indigo-600">ID: <?= htmlspecialchars($app['id']) ?></span>
            </div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">
                <?= htmlspecialchars($app['teamName']) ?> <span class="text-slate-400 font-normal">| Grant Status Tracker</span>
            </h1>
        </div>

        <div class="flex items-center gap-3">
            <a href="reports.php?id=<?= urlencode($app['id']) ?>&type=application" target="_blank" class="px-4 py-2 bg-white hover:bg-slate-50 border border-slate-300 rounded-xl text-xs font-bold text-slate-700 shadow-sm transition-all flex items-center gap-1.5">
                <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                View Dossier
            </a>
            <?php if ($currentStatus === 'APPROVED'): ?>
                <a href="reports.php?id=<?= urlencode($app['id']) ?>&type=form-b" target="_blank" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-md shadow-emerald-600/20 transition-all flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Download Form B Sanction Order
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($uploadMessage): ?>
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm font-semibold flex items-center gap-2">
            <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <?= htmlspecialchars($uploadMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($uploadError): ?>
        <div class="mb-6 p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-sm font-semibold flex items-center gap-2">
            <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            <?= htmlspecialchars($uploadError) ?>
        </div>
    <?php endif; ?>

    <!-- 5-Step Lifecycle Visual Progress Stepper -->
    <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200 mb-8">
        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-700 mb-6">Application Lifecycle Stage</h2>
        
        <?php if ($isRejected): ?>
            <div class="p-4 bg-rose-50 border border-rose-200 rounded-xl mb-4">
                <div class="flex items-center gap-2 text-rose-800 font-bold text-sm">
                    <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Application Returned / Disapproved by SEC
                </div>
                <p class="text-xs text-rose-700 mt-1">
                    The Startup Evaluation Committee has recommended revisions. Per Policy Clause P4, your team may re-present after addressing the committee's feedback. Please coordinate with the Director, IDEAPAD.
                </p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php 
                    $statusOrder = ['DRAFT' => 1, 'SUBMITTED' => 2, 'IN_REVIEW' => 3, 'APPROVED' => 4];
                    $currentRank = $statusOrder[$currentStatus] ?? 1;
                    
                    foreach ($steps as $idx => $s):
                        $stepRank = $idx + 1;
                        $isCompleted = ($currentRank > $stepRank);
                        $isCurrent = ($currentRank === $stepRank);
                ?>
                    <div class="relative p-4 rounded-xl border <?= $isCurrent ? 'bg-indigo-50/50 border-indigo-400 ring-2 ring-indigo-500/20' : ($isCompleted ? 'bg-emerald-50/40 border-emerald-300' : 'bg-slate-50 border-slate-200') ?>">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold <?= $isCurrent ? 'text-indigo-800' : ($isCompleted ? 'text-emerald-700' : 'text-slate-400') ?>">
                                <?= htmlspecialchars($s['label']) ?>
                            </span>
                            <?php if ($isCompleted): ?>
                                <span class="w-5 h-5 rounded-full bg-emerald-600 text-white flex items-center justify-center text-[10px] font-bold">✓</span>
                            <?php elseif ($isCurrent): ?>
                                <span class="w-2.5 h-2.5 rounded-full bg-indigo-600 animate-ping"></span>
                            <?php else: ?>
                                <span class="w-5 h-5 rounded-full bg-slate-200 text-slate-400 flex items-center justify-center text-[10px] font-bold"><?= $stepRank ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[11px] text-slate-500 leading-snug"><?= htmlspecialchars($s['desc']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 2-Column Details Layout: Post-Approval Roadmap & Documents -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Column 1 & 2: 8 Timeline Milestones Roadmap -->
        <div class="lg:col-span-2 space-y-8">
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-bold text-slate-900">Governance Timeline & Milestones (Policy P9)</h3>
                    <span class="text-xs font-semibold px-2.5 py-1 rounded bg-slate-100 text-slate-600">8 Institutional Dates</span>
                </div>
                <p class="text-xs text-slate-500 mb-6">
                    Key compliance checkpoints established under ITM Gwalior Innovation Fund regulations. Disbursals and reviews follow this sequence strictly.
                </p>

                <div class="relative border-l-2 border-indigo-200 ml-4 pl-6 space-y-6">
                    <?php 
                        $stepNum = 1;
                        foreach (Constants::TIMELINE_DATES as $key => $label):
                            $stepTitle = is_array($label) ? ($label['label'] ?? $key) : $label;
                            $val = $evalTimeline[$key] ?? '';
                            $hasDate = !empty($val);
                            $ts = $hasDate ? strtotime($val) : false;
                            $dateFormatted = ($ts !== false) ? date('d M Y', $ts) : ($hasDate ? $val : 'Scheduled by SEC');
                    ?>
                        <div class="relative">
                            <span class="absolute -left-[31px] top-0 w-4 h-4 rounded-full border-2 <?= $hasDate ? 'bg-indigo-600 border-indigo-200' : 'bg-white border-slate-300' ?>"></span>
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                                <h4 class="text-xs font-bold text-slate-800">
                                    <span class="text-indigo-600 font-mono mr-1">0<?= $stepNum++ ?>.</span> <?= htmlspecialchars($stepTitle) ?>
                                </h4>
                                <span class="text-xs font-mono font-semibold <?= $hasDate ? 'text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200' : 'text-slate-400' ?>">
                                    <?= htmlspecialchars($dateFormatted) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Milestone Assessment Register -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
                <h3 class="text-base font-bold text-slate-900 mb-4">Self-Assessment Milestones Tracking</h3>
                <?php if (empty($selfMilestones)): ?>
                    <p class="text-xs text-slate-500 italic">No specific milestones logged for this application.</p>
                <?php else: ?>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($selfMilestones as $mName => $mData): 
                            $status = is_array($mData) ? ($mData['status'] ?? 'PENDING') : $mData;
                            $remark = is_array($mData) ? ($mData['remarks'] ?? '') : '';
                        ?>
                            <div class="py-3 flex items-start justify-between gap-4 text-xs">
                                <div>
                                    <span class="font-bold text-slate-800"><?= htmlspecialchars((string)$mName) ?></span>
                                    <?php if (!empty($remark)): ?>
                                        <p class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($remark) ?></p>
                                    <?php endif; ?>
                                </div>
                                <span class="px-2 py-0.5 text-[10px] font-bold rounded uppercase whitespace-nowrap <?= $status === 'COMPLETED' ? 'bg-emerald-100 text-emerald-800' : ($status === 'IN_PROGRESS' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600') ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Column 3: Documents & Uploads -->
        <div class="space-y-8">
            <!-- Uploaded Documents Archive -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
                <h3 class="text-base font-bold text-slate-900 mb-4">Compliance Documents</h3>
                <?php if (empty($documents)): ?>
                    <p class="text-xs text-slate-400 italic">No files uploaded yet.</p>
                <?php else: ?>
                    <ul class="space-y-2 text-xs">
                        <?php foreach ($documents as $doc): ?>
                            <li class="p-3 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between gap-2">
                                <div class="truncate">
                                    <span class="block font-bold text-slate-800 truncate"><?= htmlspecialchars($doc['memberLabel'] ?? $doc['type']) ?></span>
                                    <span class="text-[10px] text-slate-500 font-mono"><?= htmlspecialchars($doc['type']) ?></span>
                                </div>
                                <a href="<?= htmlspecialchars($doc['path']) ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 font-bold text-xs">
                                    Open
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Invoice & Utilization Certificate Submission (Post Approval) -->
            <?php if ($currentStatus === 'APPROVED'): ?>
                <div class="bg-indigo-50/60 rounded-2xl p-6 border border-indigo-200">
                    <h3 class="text-base font-bold text-indigo-950 mb-2">Submit Invoice / UC Bill</h3>
                    <p class="text-xs text-indigo-700 mb-4 leading-relaxed">
                        Attach GST invoice or payment voucher for tranche settlement.
                    </p>
                    <form method="POST" enctype="multipart/form-data" class="space-y-3">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="upload_bill">
                        <div>
                            <input type="file" name="bill_file" required accept=".pdf,.png,.jpg,.jpeg" 
                                   class="block w-full text-xs text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-indigo-600 file:text-white hover:file:bg-indigo-700">
                        </div>
                        <button type="submit" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm">
                            Upload Bill / Receipt
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Incubator Contact Information -->
            <div class="bg-slate-50 rounded-2xl p-6 border border-slate-200 text-xs">
                <h4 class="font-bold text-slate-900 mb-2">Incubator Contact</h4>
                <p class="text-slate-600 mb-1"><strong>IDEAPAD Technology Business Incubator</strong></p>
                <p class="text-slate-500">ITM Gwalior, Opp. Sithouli Railway Station, NH-75, Gwalior (M.P.) - 474001</p>
                <p class="text-slate-500 mt-2">Email: <a href="mailto:ideapad@itmgwalior.ac.in" class="text-indigo-600 underline">ideapad@itmgwalior.ac.in</a></p>
            </div>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
