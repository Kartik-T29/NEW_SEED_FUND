<?php
/**
 * IDEAPAD Seed Fund Portal - Universal Report Viewer (Form A, Form B, Application Dossier)
 * 100% Pure Server-Rendered PHP with Vector Print Layouts (@media print)
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
$type = trim($_GET['type'] ?? 'application'); // application | form-a | form-b

if (empty($appId)) {
    die("Error: Application ID is required. <a href='index.php'>Return to Home</a>");
}

// Fetch application with startup and founder details
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
    die("Error: Application not found. <a href='index.php'>Return to Home</a>");
}

// Permission check: Founder can only view their own startup's reports
if ($currentUser['role'] === 'STARTUP') {
    $stmtFounder = $pdo->prepare('SELECT id FROM `Startup` WHERE userId = ? LIMIT 1');
    $stmtFounder->execute([$currentUser['id']]);
    $founderStartup = $stmtFounder->fetch();
    if (!$founderStartup || $founderStartup['id'] !== $app['startupId']) {
        die("Unauthorized access: You may only view reports for your own startup.");
    }
}

// Parse Application formData
$formData = json_decode($app['formData'] ?? '{}', true) ?: [];
$problemStatement = $formData['problem'] ?? '';
$proposedSolution = $formData['solution'] ?? '';
$tractionSoFar = $formData['traction'] ?? '';
$experiments = $formData['experiments'] ?? [];
$selfAssessment = $formData['selfAssessment'] ?? [];

// Fetch budget items
$stmtBudget = $pdo->prepare('SELECT * FROM `BudgetItem` WHERE applicationId = ? ORDER BY header ASC, id ASC');
$stmtBudget->execute([$appId]);
$budgetItems = $stmtBudget->fetchAll();

$groupedBudget = [];
$calculatedTotal = 0.0;
foreach (Constants::ANNEXURE_1_HEADERS as $catKey => $catInfo) {
    $groupedBudget[$catKey] = [];
}
foreach ($budgetItems as $item) {
    $cat = $item['header'];
    if (!isset($groupedBudget[$cat])) {
        $groupedBudget[$cat] = [];
    }
    $groupedBudget[$cat][] = $item;
    $calculatedTotal += (float)$item['total'];
}
$contingencyAmount = round($calculatedTotal * 0.05, 2);
$grandTotal = (float)$app['requestedTotal'] > 0 ? (float)$app['requestedTotal'] : ($calculatedTotal + $contingencyAmount);

// Fetch documents
$stmtDocs = $pdo->prepare('SELECT * FROM `Document` WHERE applicationId = ? ORDER BY createdAt ASC');
$stmtDocs->execute([$appId]);
$documents = $stmtDocs->fetchAll();

// Fetch Form A Assessments if available
$stmtAssess = $pdo->prepare('
    SELECT ast.*, u.name AS sec_name, u.designation AS sec_designation, u.department AS sec_dept
    FROM `Assessment` ast
    JOIN `User` u ON ast.memberId = u.id
    WHERE ast.applicationId = ?
    ORDER BY ast.submittedAt ASC, ast.id ASC
');
$stmtAssess->execute([$appId]);
$assessments = $stmtAssess->fetchAll();

// Fetch Form B Evaluation if available
$stmtEval = $pdo->prepare('
    SELECT ge.*, u.name as admin_name
    FROM `GrantEvaluation` ge
    JOIN `User` u ON ge.adminId = u.id
    WHERE ge.applicationId = ?
    LIMIT 1
');
$stmtEval->execute([$appId]);
$evaluation = $stmtEval->fetch();

$evalTimeline = !empty($evaluation['timeline']) ? (is_array($evaluation['timeline']) ? $evaluation['timeline'] : json_decode($evaluation['timeline'], true)) : [];
$moderatedBudget = !empty($evaluation['amounts']) ? (is_array($evaluation['amounts']) ? $evaluation['amounts'] : json_decode($evaluation['amounts'], true)) : [];

// Page titles
$tierName = Constants::GRANT_TIERS[$app['grantType'] ?? 'PROOF']['name'] ?? 'Proof Grant';
$pageTitle = match($type) {
    'form-a' => "SEC Form A Evaluation Report - {$app['teamName']}",
    'form-b' => "Form B Grant Sanction Order - {$app['teamName']}",
    default => "Seed Grant Application Dossier - {$app['teamName']}"
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | IDEAPAD Innovation Fund</title>
    <link rel="icon" type="image/png" href="brand/ideapad-logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; color: #000 !important; font-size: 11pt; }
            .print-card { box-shadow: none !important; border: 1px solid #ccc !important; }
            .page-break { page-break-before: always; }
            a { text-decoration: none !important; color: #000 !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased">

<!-- Floating Action Bar (Hidden on print) -->
<div class="no-print fixed top-4 right-4 z-50 flex items-center gap-3 bg-white/95 backdrop-blur-md px-4 py-2.5 rounded-2xl shadow-xl border border-slate-200">
    <a href="<?= $currentUser['role'] === 'ADMIN' ? 'admin.php' : ($currentUser['role'] === 'SEC_MEMBER' ? 'sec.php?id=' . urlencode($appId) : 'dashboard.php') ?>" 
       class="text-xs font-semibold text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Return to Portal
    </a>
    
    <div class="h-4 w-px bg-slate-300"></div>

    <!-- Toggle views -->
    <a href="reports.php?id=<?= urlencode($appId) ?>&type=application" class="text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors <?= $type === 'application' ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-100' ?>">
        Application Dossier
    </a>
    <a href="reports.php?id=<?= urlencode($appId) ?>&type=form-a" class="text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors <?= $type === 'form-a' ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-100' ?>">
        SEC Form A
    </a>
    <a href="reports.php?id=<?= urlencode($appId) ?>&type=form-b" class="text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors <?= $type === 'form-b' ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-100' ?>">
        Form B Sanction Order
    </a>

    <button onclick="window.print()" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-1.5 rounded-lg shadow-sm transition-all flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
        Print / Save PDF
    </button>
</div>

<!-- Main Printable Document Container -->
<div class="max-w-4xl mx-auto my-8 bg-white p-8 md:p-12 rounded-2xl shadow-xl border border-slate-200 print-card print:m-0 print:p-0 print:border-none print:shadow-none">

    <!-- Document Institutional Header -->
    <div class="flex items-center justify-between pb-6 mb-8 border-b-2 border-slate-900">
        <div class="flex items-center gap-4">
            <img src="brand/ideapad-logo.png" alt="IDEAPAD Logo" class="h-16 w-auto object-contain">
            <div>
                <h1 class="text-xl font-black tracking-tight text-slate-900 uppercase">IDEAPAD - Technology Business Incubator</h1>
                <p class="text-xs font-semibold text-slate-600">ITM Gwalior (M.P.), India | Innovation Fund Directorate</p>
                <p class="text-[10px] text-slate-500">Official Grant Governance Document under Seed Fund Policy 2026</p>
            </div>
        </div>
        <div class="text-right">
            <span class="inline-block px-3 py-1 text-xs font-bold uppercase rounded-md tracking-wider border <?= $app['status'] === 'APPROVED' ? 'bg-emerald-50 text-emerald-800 border-emerald-300' : ($app['status'] === 'REJECTED' ? 'bg-rose-50 text-rose-800 border-rose-300' : 'bg-amber-50 text-amber-800 border-amber-300') ?>">
                Status: <?= htmlspecialchars($app['status']) ?>
            </span>
            <div class="text-[11px] font-mono text-slate-500 mt-1">App ID: <?= htmlspecialchars($app['id']) ?></div>
            <?php 
                $createdTs = !empty($app['createdAt']) ? strtotime($app['createdAt']) : false;
            ?>
            <div class="text-[11px] text-slate-500">Date: <?= ($createdTs !== false) ? date('d M Y', $createdTs) : htmlspecialchars($app['createdAt'] ?? 'N/A') ?></div>
        </div>
    </div>

    <!-- ========================================================================================= -->
    <!-- VIEW TYPE 1: APPLICATION PROPOSAL DOSSIER                                                 -->
    <!-- ========================================================================================= -->
    <?php if ($type === 'application'): ?>
        <div class="text-center mb-8">
            <h2 class="text-xl font-bold uppercase text-slate-900 underline decoration-indigo-500 underline-offset-4">Seed Grant Application Proposal Dossier</h2>
            <p class="text-sm text-slate-600 mt-1">Formal request submitted under official Seed Fund Grant Policy</p>
        </div>

        <!-- Section 1: Applicant & Startup Details -->
        <div class="mb-8">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-3">1. Applicant & Startup Information</h3>
            <div class="grid grid-cols-2 gap-4 text-xs">
                <div><span class="text-slate-500">Team / Project Name:</span> <strong class="text-slate-800"><?= htmlspecialchars($app['teamName']) ?></strong></div>
                <div><span class="text-slate-500">Founder / Lead Applicant:</span> <strong class="text-slate-800"><?= htmlspecialchars($app['founderName']) ?></strong></div>
                <div><span class="text-slate-500">Official Email:</span> <strong class="text-slate-800"><?= htmlspecialchars($app['founderEmail']) ?></strong></div>
                <div><span class="text-slate-500">Enrollment / Roll No:</span> <strong class="text-slate-800"><?= htmlspecialchars($app['enrollmentNo']) ?></strong></div>
                <div><span class="text-slate-500">Academic Program:</span> <strong class="text-slate-800"><?= htmlspecialchars($app['program']) ?></strong></div>
                <div><span class="text-slate-500">Phone Contact:</span> <strong class="text-slate-800"><?= htmlspecialchars($app['phone']) ?></strong></div>
                <div class="col-span-2"><span class="text-slate-500">Grant Tier Requested:</span> <strong class="text-indigo-700 uppercase"><?= htmlspecialchars($tierName) ?></strong></div>
            </div>
        </div>

        <!-- Section 2: Problem Statement -->
        <div class="mb-6">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-2">2. Problem You Are Solving</h3>
            <div class="p-4 bg-slate-50 rounded-lg text-xs leading-relaxed text-slate-800 border border-slate-200 whitespace-pre-wrap"><?= htmlspecialchars($problemStatement ?: 'No problem statement provided.') ?></div>
        </div>

        <!-- Section 3: Proposed Solution -->
        <div class="mb-6">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-2">3. Proposed Solution</h3>
            <div class="p-4 bg-slate-50 rounded-lg text-xs leading-relaxed text-slate-800 border border-slate-200 whitespace-pre-wrap"><?= htmlspecialchars($proposedSolution ?: 'No solution description provided.') ?></div>
        </div>

        <!-- Section 4: Traction So Far -->
        <div class="mb-6">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-2">4. Effort & Traction So Far</h3>
            <div class="p-4 bg-slate-50 rounded-lg text-xs leading-relaxed text-slate-800 border border-slate-200 whitespace-pre-wrap"><?= htmlspecialchars($tractionSoFar ?: 'No traction details provided.') ?></div>
        </div>

        <!-- Section 5: Experiment Plan (10 Headings) -->
        <div class="mb-8 page-break">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-3">5. Experiment Plan & Proof of Concept (10 Policy Headings)</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <?php foreach (Constants::EXPERIMENT_HEADINGS as $expIdx => $expInfo): 
                    $expVal = $experiments[(string)$expIdx] ?? $experiments[$expIdx] ?? '';
                ?>
                    <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                        <div class="font-bold text-slate-900 mb-1"><?= $expIdx ?>. <?= htmlspecialchars($expInfo['title']) ?></div>
                        <div class="text-[10px] text-slate-500 mb-2"><?= htmlspecialchars($expInfo['prompt']) ?></div>
                        <div class="text-slate-700 whitespace-pre-wrap"><?= htmlspecialchars($expVal ?: 'Pending') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Section 6: Detailed Requirement Budget -->
        <div class="mb-8">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-3">6. Detailed Requirement Table (Annexure 1 Headers)</h3>
            <div class="overflow-x-auto border border-slate-200 rounded-lg">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-slate-100 text-slate-700 border-b border-slate-200 font-bold">
                        <tr>
                            <th class="p-2 border-r border-slate-200 w-12 text-center">S.No</th>
                            <th class="p-2 border-r border-slate-200">Category & Product / Service</th>
                            <th class="p-2 border-r border-slate-200">Vendor / Source</th>
                            <th class="p-2 border-r border-slate-200 text-right w-24">Unit Cost (₹)</th>
                            <th class="p-2 border-r border-slate-200 text-center w-16">Qty</th>
                            <th class="p-2 text-right w-28">Total (₹)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php if (empty($budgetItems)): ?>
                            <tr><td colspan="6" class="p-4 text-center text-slate-400">No budget items entered.</td></tr>
                        <?php else: ?>
                            <?php $sno = 1; foreach ($budgetItems as $item): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="p-2 border-r border-slate-200 text-center font-mono"><?= $sno++ ?></td>
                                    <td class="p-2 border-r border-slate-200">
                                        <span class="inline-block px-1.5 py-0.5 text-[9px] font-bold rounded bg-slate-200 text-slate-700 mr-1"><?= htmlspecialchars($item['header']) ?></span>
                                        <strong><?= htmlspecialchars($item['name']) ?></strong>
                                        <?php if (!empty($item['justification'])): ?>
                                            <p class="text-[10px] text-slate-500 mt-0.5"><?= htmlspecialchars($item['justification']) ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-2 border-r border-slate-200 text-slate-600"><?= htmlspecialchars($item['vendor'] ?? 'Local Vendor') ?></td>
                                    <td class="p-2 border-r border-slate-200 text-right font-mono">₹<?= number_format((float)$item['unitCost'], 2) ?></td>
                                    <td class="p-2 border-r border-slate-200 text-center font-mono"><?= (int)$item['qty'] ?></td>
                                    <td class="p-2 text-right font-mono font-bold">₹<?= number_format((float)$item['total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="bg-slate-50 border-t-2 border-slate-300 font-bold text-xs">
                        <tr>
                            <td colspan="5" class="p-2 text-right">Direct Expenses Subtotal:</td>
                            <td class="p-2 text-right font-mono">₹<?= number_format($calculatedTotal, 2) ?></td>
                        </tr>
                        <tr>
                            <td colspan="5" class="p-2 text-right text-slate-600">Mandatory 5% Contingency Buffer:</td>
                            <td class="p-2 text-right font-mono text-slate-600">₹<?= number_format($contingencyAmount, 2) ?></td>
                        </tr>
                        <tr class="bg-indigo-50 text-indigo-900 border-t border-indigo-200">
                            <td colspan="5" class="p-2 text-right text-sm">Grand Total Grant Requested:</td>
                            <td class="p-2 text-right font-mono text-sm font-black">₹<?= number_format($grandTotal, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Section 8: Self Assessments -->
        <div class="mb-8">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-3">8. Self-Assessment of TRL, MRL & IRL Readiness</h3>
            <div class="grid grid-cols-3 gap-4 text-center">
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <div class="text-[10px] uppercase font-bold text-slate-500">Technology Readiness</div>
                    <div class="text-2xl font-black text-indigo-600 mt-1">TRL <?= (int)($selfAssessment['trl'] ?? 1) ?></div>
                    <div class="text-[10px] text-slate-600 mt-1"><?= htmlspecialchars(Constants::TRL_LEVELS[$selfAssessment['trl'] ?? 1] ?? '') ?></div>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <div class="text-[10px] uppercase font-bold text-slate-500">Manufacturing Readiness</div>
                    <div class="text-2xl font-black text-indigo-600 mt-1">MRL <?= (int)($selfAssessment['mrl'] ?? 1) ?></div>
                    <div class="text-[10px] text-slate-600 mt-1"><?= htmlspecialchars(Constants::MRL_LEVELS[$selfAssessment['mrl'] ?? 1] ?? '') ?></div>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <div class="text-[10px] uppercase font-bold text-slate-500">Integration Readiness</div>
                    <div class="text-2xl font-black text-indigo-600 mt-1">IRL <?= (int)($selfAssessment['irl'] ?? 1) ?></div>
                    <div class="text-[10px] text-slate-600 mt-1"><?= htmlspecialchars(Constants::IRL_LEVELS[$selfAssessment['irl'] ?? 1] ?? '') ?></div>
                </div>
            </div>
        </div>

        <!-- Section 10: Legal Declaration & Electronic Signature -->
        <div class="mt-12 p-6 bg-slate-50 rounded-xl border border-slate-300 text-xs">
            <div class="font-bold uppercase text-slate-900 mb-2">Applicant Declaration & Undertaking</div>
            <p class="text-slate-700 italic mb-6 leading-relaxed">
                "<?= Constants::DECLARATION_TEXT ?>"
            </p>
            <div class="flex justify-between items-end pt-4 border-t border-slate-300">
                <div>
                    <div class="font-bold text-slate-800"><?= htmlspecialchars($app['founderName']) ?></div>
                    <div class="text-slate-500">Lead Applicant & Founder (<?= htmlspecialchars($app['teamName']) ?>)</div>
                    <div class="text-[10px] text-slate-400 mt-1">IP Recorded: <?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?></div>
                </div>
                <div class="text-right">
                    <div class="text-emerald-700 font-bold flex items-center gap-1 justify-end">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Electronically Signed & Submitted
                    </div>
                    <?php 
                        $subDateRaw = $app['submittedAt'] ?? $app['createdAt'] ?? '';
                        $subTs = !empty($subDateRaw) ? strtotime($subDateRaw) : false;
                    ?>
                    <div class="text-[10px] text-slate-500 mt-1">Timestamp: <?= ($subTs !== false) ? date('d M Y, h:i A', $subTs) : htmlspecialchars($subDateRaw) ?></div>
                </div>
            </div>
        </div>

    <!-- ========================================================================================= -->
    <!-- VIEW TYPE 2: SEC FORM A CONSOLIDATED EVALUATION REPORT                                    -->
    <!-- ========================================================================================= -->
    <?php elseif ($type === 'form-a'): ?>
        <div class="text-center mb-8">
            <h2 class="text-xl font-bold uppercase text-slate-900 underline decoration-indigo-500 underline-offset-4">SEC Assessment & Recommendation Report (Form A)</h2>
            <p class="text-sm text-slate-600 mt-1">Official Startup Evaluation Committee Multi-Member Assessment</p>
        </div>

        <!-- App Metadata -->
        <div class="p-4 bg-slate-50 rounded-lg border border-slate-200 mb-8 grid grid-cols-3 gap-4 text-xs">
            <div><span class="text-slate-500">Startup Name:</span> <strong class="text-slate-800"><?= htmlspecialchars($app['teamName']) ?></strong></div>
            <div><span class="text-slate-500">Applicant:</span> <strong class="text-slate-800"><?= htmlspecialchars($app['founderName']) ?></strong></div>
            <div><span class="text-slate-500">Assessment Date:</span> <strong class="text-slate-800"><?= date('d F Y') ?></strong></div>
            <div><span class="text-slate-500">Grant Requested:</span> <strong class="text-indigo-700"><?= htmlspecialchars($tierName) ?></strong></div>
            <div><span class="text-slate-500">Evaluations Recorded:</span> <strong class="text-slate-800"><?= count($assessments) ?> / 3 Members</strong></div>
            <div><span class="text-slate-500">Status:</span> <strong class="text-slate-800 uppercase"><?= htmlspecialchars($app['status']) ?></strong></div>
        </div>

        <!-- SEC Committee Roster -->
        <div class="mb-8">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-3">Startup Evaluation Committee (SEC) Panel Composition</h3>
            <div class="grid grid-cols-3 gap-4 text-xs">
                <?php foreach (Constants::SEC_PANEL_DESIGNATIONS as $index => $panelRole): ?>
                    <?php 
                        $eval = $assessments[$index] ?? null;
                    ?>
                    <div class="p-3 bg-white rounded-lg border border-slate-200 shadow-sm">
                        <span class="inline-block px-2 py-0.5 text-[9px] font-bold rounded bg-indigo-100 text-indigo-800 mb-1">Domain <?= $index + 1 ?></span>
                        <div class="font-bold text-slate-900"><?= htmlspecialchars($panelRole['domain']) ?></div>
                        <div class="text-slate-600 mt-1"><?= htmlspecialchars(($eval && !empty($eval['sec_name'])) ? $eval['sec_name'] : $panelRole['default_name']) ?></div>
                        <div class="text-[10px] text-slate-500"><?= htmlspecialchars(($eval && !empty($eval['sec_designation'])) ? $eval['sec_designation'] : $panelRole['default_designation']) ?></div>
                        <div class="mt-2 text-[10px] font-semibold <?= $eval ? 'text-emerald-700' : 'text-amber-600' ?>">
                            <?= $eval ? '✓ Evaluation Submitted' : '○ Pending Evaluation' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TRL / MRL / IRL 3-Way Comparative Evaluation Matrix -->
        <div class="mb-8">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-3">1. Current Status Assessment (TRL / MRL / IRL Comparison Matrix)</h3>
            <div class="overflow-x-auto border border-slate-200 rounded-lg">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-slate-100 text-slate-700 border-b border-slate-200 font-bold">
                        <tr>
                            <th class="p-2 border-r border-slate-200">Scale / Dimension</th>
                            <th class="p-2 border-r border-slate-200 text-center w-24">Self Assessment</th>
                            <th class="p-2 border-r border-slate-200 text-center w-28">Member 1</th>
                            <th class="p-2 border-r border-slate-200 text-center w-28">Member 2</th>
                            <th class="p-2 border-r border-slate-200 text-center w-28">Member 3</th>
                            <th class="p-2 text-center w-28 bg-indigo-50 text-indigo-900">Consensus / Avg</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 font-mono text-center">
                        <?php 
                            $scales = ['trl' => 'TRL (Technology Readiness)', 'mrl' => 'MRL (Manufacturing Readiness)', 'irl' => 'IRL (Integration Readiness)'];
                            foreach ($scales as $scaleKey => $scaleTitle):
                                $selfVal = (int)($selfAssessment[$scaleKey] ?? 1);
                                $mScores = [];
                                foreach ($assessments as $aItem) {
                                    $stForm = json_decode($aItem['statusForm'] ?? '{}', true) ?: [];
                                    if (isset($stForm[$scaleKey])) {
                                        $mScores[] = (int)$stForm[$scaleKey];
                                    }
                                }
                                $m1 = $mScores[0] ?? null;
                                $m2 = $mScores[1] ?? null;
                                $m3 = $mScores[2] ?? null;
                                $validScores = array_filter([$m1, $m2, $m3], fn($v) => !is_null($v));
                                $avg = count($validScores) > 0 ? round(array_sum($validScores) / count($validScores), 1) : '-';
                        ?>
                            <tr>
                                <td class="p-2 border-r border-slate-200 text-left font-sans font-semibold text-slate-800"><?= htmlspecialchars($scaleTitle) ?></td>
                                <td class="p-2 border-r border-slate-200 text-slate-600">Level <?= $selfVal ?></td>
                                <td class="p-2 border-r border-slate-200"><?= !is_null($m1) ? "Level $m1" : '-' ?></td>
                                <td class="p-2 border-r border-slate-200"><?= !is_null($m2) ? "Level $m2" : '-' ?></td>
                                <td class="p-2 border-r border-slate-200"><?= !is_null($m3) ? "Level $m3" : '-' ?></td>
                                <td class="p-2 bg-indigo-50 font-bold text-indigo-900"><?= $avg !== '-' ? "Level $avg" : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 12 Rubric Scoring Breakdown -->
        <div class="mb-8">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-3">2. Evaluation Rubric Scores (12 Questions Across 4 Domains)</h3>
            <div class="overflow-x-auto border border-slate-200 rounded-lg">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-slate-100 text-slate-700 border-b border-slate-200 font-bold">
                        <tr>
                            <th class="p-2 border-r border-slate-200 w-10 text-center">#</th>
                            <th class="p-2 border-r border-slate-200">Rubric Evaluation Dimension</th>
                            <th class="p-2 border-r border-slate-200 text-center w-20">M1 (1-5)</th>
                            <th class="p-2 border-r border-slate-200 text-center w-20">M2 (1-5)</th>
                            <th class="p-2 border-r border-slate-200 text-center w-20">M3 (1-5)</th>
                            <th class="p-2 text-center w-20 bg-indigo-50 text-indigo-900">Average</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-center font-mono">
                        <?php 
                            $qIndex = 1;
                            $m1Total = 0; $m2Total = 0; $m3Total = 0;
                            foreach (Constants::getFlatRubricQuestions() as $qid => $qText):
                                $getScore = function($assessIdx) use ($assessments, $qid) {
                                    if (!isset($assessments[$assessIdx])) return null;
                                    $expForm = json_decode($assessments[$assessIdx]['experimentForm'] ?? '{}', true) ?: [];
                                    $scores = $expForm['rubrics'] ?? [];
                                    return isset($scores[$qid]) ? (int)$scores[$qid] : null;
                                };
                                $s1 = $getScore(0);
                                $s2 = $getScore(1);
                                $s3 = $getScore(2);
                                if ($s1) $m1Total += $s1;
                                if ($s2) $m2Total += $s2;
                                if ($s3) $m3Total += $s3;
                                $rowScores = array_filter([$s1, $s2, $s3], fn($v) => !is_null($v) && $v > 0);
                                $rowAvg = count($rowScores) > 0 ? round(array_sum($rowScores) / count($rowScores), 1) : '-';
                        ?>
                            <tr>
                                <td class="p-2 border-r border-slate-200 text-slate-400 font-sans"><?= $qIndex++ ?></td>
                                <td class="p-2 border-r border-slate-200 text-left font-sans text-slate-800"><?= htmlspecialchars($qText) ?></td>
                                <td class="p-2 border-r border-slate-200"><?= $s1 ?: '-' ?></td>
                                <td class="p-2 border-r border-slate-200"><?= $s2 ?: '-' ?></td>
                                <td class="p-2 border-r border-slate-200"><?= $s3 ?: '-' ?></td>
                                <td class="p-2 bg-indigo-50 font-bold text-indigo-900"><?= $rowAvg ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-slate-100 font-bold border-t-2 border-slate-300">
                        <tr>
                            <td colspan="2" class="p-2 text-right font-sans">Total Rubric Score (Max 60):</td>
                            <td class="p-2 text-center font-mono"><?= $m1Total ?: '-' ?></td>
                            <td class="p-2 text-center font-mono"><?= $m2Total ?: '-' ?></td>
                            <td class="p-2 text-center font-mono"><?= $m3Total ?: '-' ?></td>
                            <?php 
                                $totals = array_filter([$m1Total, $m2Total, $m3Total], fn($v) => $v > 0);
                                $grandAvg = count($totals) > 0 ? round(array_sum($totals) / count($totals), 1) : '-';
                            ?>
                            <td class="p-2 text-center font-mono bg-indigo-50 text-indigo-900"><?= $grandAvg ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Qualitative Evaluator Notes -->
        <div class="mb-8">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-3">3. SEC Member Remarks & Moderation Notes</h3>
            <div class="space-y-3 text-xs">
                <?php if (empty($assessments)): ?>
                    <p class="text-slate-400 italic">No member remarks recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($assessments as $idx => $eval): 
                        $evalDateRaw = $eval['submittedAt'] ?? $eval['assessmentDate'] ?? 'now';
                        $evalTs = !empty($evalDateRaw) ? strtotime($evalDateRaw) : false;
                        $evalDateDisp = ($evalTs !== false) ? date('d M Y, h:i A', $evalTs) : htmlspecialchars($evalDateRaw);
                    ?>
                        <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                            <div class="flex justify-between items-center mb-1">
                                <strong class="text-slate-900"><?= htmlspecialchars($eval['sec_name'] ?? 'SEC Evaluator') ?> <span class="font-normal text-slate-500">(<?= htmlspecialchars($eval['sec_designation'] ?? 'Panelist') ?>)</span></strong>
                                <span class="text-[10px] text-slate-400"><?= $evalDateDisp ?></span>
                            </div>
                            <div class="text-slate-700 whitespace-pre-wrap"><?= htmlspecialchars($eval['recommendation'] ?? 'No written notes provided.') ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- 3 SEC Signatures Block -->
        <div class="mt-12 pt-8 border-t-2 border-slate-900">
            <div class="text-xs font-bold uppercase text-slate-600 mb-8 text-center tracking-wider">Startup Evaluation Committee (SEC) Final Endorsement</div>
            <div class="grid grid-cols-3 gap-8 text-center">
                <?php foreach (Constants::SEC_PANEL_DESIGNATIONS as $index => $panelRole): 
                    $eval = $assessments[$index] ?? null;
                ?>
                    <div class="border-t border-slate-400 pt-3">
                        <div class="font-bold text-xs text-slate-900"><?= htmlspecialchars(($eval && !empty($eval['sec_name'])) ? $eval['sec_name'] : $panelRole['default_name']) ?></div>
                        <div class="text-[10px] text-slate-600"><?= htmlspecialchars($panelRole['domain']) ?></div>
                        <div class="text-[9px] text-slate-500"><?= htmlspecialchars(($eval && !empty($eval['sec_designation'])) ? $eval['sec_designation'] : $panelRole['default_designation']) ?></div>
                        <?php
                            $sigDateRaw = $eval['submittedAt'] ?? 'now';
                            $sigTs = !empty($sigDateRaw) ? strtotime($sigDateRaw) : false;
                            $sigDateDisp = ($sigTs !== false) ? date('d/m/Y', $sigTs) : htmlspecialchars($sigDateRaw);
                        ?>
                        <div class="text-[10px] font-mono text-emerald-700 mt-2 font-semibold">
                            <?= $eval ? 'Signed: ' . $sigDateDisp : '[ Signature Pending ]' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <!-- ========================================================================================= -->
    <!-- VIEW TYPE 3: FORM B GRANT SANCTION ORDER                                                  -->
    <!-- ========================================================================================= -->
    <?php elseif ($type === 'form-b'): ?>
        <div class="text-center mb-8">
            <div class="inline-block px-4 py-1 rounded-full bg-indigo-50 border border-indigo-200 text-indigo-900 text-xs font-bold uppercase tracking-wider mb-2">
                Official Sanction Document (Form B)
            </div>
            <h2 class="text-2xl font-black uppercase tracking-tight text-slate-900">IDEAPAD Innovation Fund Grant Sanction Order</h2>
            <p class="text-xs text-slate-600 mt-1">Pursuant to the Approval of the Startup Evaluation Committee (SEC) and Director, IDEAPAD</p>
        </div>

        <!-- Formal Sanction Details Header -->
        <div class="p-4 bg-slate-50 rounded-xl border border-slate-200 mb-8 grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
            <div>
                <span class="text-slate-500 block text-[10px] uppercase">Sanction Order No:</span>
                <strong class="font-mono text-slate-800">IDEAPAD/SG/<?= date('Y') ?>/<?= strtoupper(substr($app['id'], 0, 8)) ?></strong>
            </div>
            <div>
                <span class="text-slate-500 block text-[10px] uppercase">Sanction Date:</span>
                <?php 
                    $sanctionTs = !empty($evalTimeline['sanctionDate']) ? strtotime($evalTimeline['sanctionDate']) : false;
                    $sanctionDisp = ($sanctionTs !== false) ? date('d F Y', $sanctionTs) : (!empty($evalTimeline['sanctionDate']) ? htmlspecialchars($evalTimeline['sanctionDate']) : date('d F Y'));
                ?>
                <strong class="text-slate-800"><?= $sanctionDisp ?></strong>
            </div>
            <div>
                <span class="text-slate-500 block text-[10px] uppercase">Startup / Grantee:</span>
                <strong class="text-slate-800"><?= htmlspecialchars($app['teamName']) ?></strong>
            </div>
            <div>
                <span class="text-slate-500 block text-[10px] uppercase">Principal Founder:</span>
                <strong class="text-slate-800"><?= htmlspecialchars($app['founderName']) ?></strong>
            </div>
            <div class="col-span-2">
                <span class="text-slate-500 block text-[10px] uppercase">Approved Grant Scheme:</span>
                <strong class="text-indigo-800 uppercase font-bold"><?= htmlspecialchars($tierName) ?></strong>
            </div>
            <div class="col-span-2 text-right">
                <span class="text-slate-500 block text-[10px] uppercase">Total Sanctioned Capital:</span>
                <strong class="text-base font-black font-mono text-emerald-700">
                    ₹<?= number_format((float)($evaluation['approvedTotal'] ?? $grandTotal), 2) ?>
                </strong>
            </div>
        </div>

        <!-- Official Sanction Clause Preamble -->
        <div class="mb-8 p-4 bg-emerald-50/50 rounded-xl border border-emerald-200 text-xs text-slate-800 leading-relaxed">
            <p>
                Sanction of the Competent Authority is hereby accorded for the release of financial assistance under the <strong>IDEAPAD Innovation Fund</strong> of ITM Gwalior to <strong><?= htmlspecialchars($app['teamName']) ?></strong>, represented by <strong><?= htmlspecialchars($app['founderName']) ?></strong> (Enrollment No. <?= htmlspecialchars($app['enrollmentNo']) ?>), for conducting validated prototyping, experimental setup, and proof-of-concept validation in accordance with the terms, timeline milestones, and moderated budget heads detailed hereunder.
            </p>
        </div>

        <!-- 8 Timeline Milestone Dates Table (Policy P9 Requirement) -->
        <div class="mb-8">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-3">1. Mandatory Timeline & Compliance Schedule (8 Key Dates)</h3>
            <div class="overflow-x-auto border border-slate-200 rounded-lg">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-slate-100 text-slate-700 border-b border-slate-200 font-bold">
                        <tr>
                            <th class="p-2 border-r border-slate-200 w-12 text-center">Stage</th>
                            <th class="p-2 border-r border-slate-200">Compliance Milestone</th>
                            <th class="p-2 border-r border-slate-200">Governing Policy Clause</th>
                            <th class="p-2 text-right w-36">Scheduled Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php 
                            $stageNo = 1;
                            foreach (Constants::TIMELINE_DATES as $tKey => $tLabel):
                                $tTitle = is_array($tLabel) ? ($tLabel['label'] ?? $tKey) : $tLabel;
                                $dateVal = $evalTimeline[$tKey] ?? '';
                                $ts = !empty($dateVal) ? strtotime($dateVal) : false;
                                $displayDate = ($ts !== false) ? date('d M Y', $ts) : (!empty($dateVal) ? $dateVal : 'To be assigned');
                        ?>
                            <tr class="hover:bg-slate-50">
                                <td class="p-2 border-r border-slate-200 text-center font-mono font-bold text-slate-500"><?= $stageNo++ ?></td>
                                <td class="p-2 border-r border-slate-200 font-semibold text-slate-800"><?= htmlspecialchars($tTitle) ?></td>
                                <td class="p-2 border-r border-slate-200 text-[10px] text-slate-500">Seed Fund SOP Clause P9</td>
                                <td class="p-2 text-right font-mono font-semibold text-indigo-700"><?= htmlspecialchars($displayDate) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Moderated Budget Allocation (9 Annexure 1 Heads) -->
        <div class="mb-8">
            <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-900 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-200 mb-3">2. Approved Head-Wise Budget Allocation (Annexure 1)</h3>
            <div class="overflow-x-auto border border-slate-200 rounded-lg">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-slate-100 text-slate-700 border-b border-slate-200 font-bold">
                        <tr>
                            <th class="p-2 border-r border-slate-200 w-12 text-center">#</th>
                            <th class="p-2 border-r border-slate-200">Budget Head (Annexure 1)</th>
                            <th class="p-2 border-r border-slate-200 text-right w-36">Requested (₹)</th>
                            <th class="p-2 text-right w-36 bg-emerald-50 text-emerald-900">Sanctioned (₹)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php 
                            $bIdx = 1;
                            $totReq = 0;
                            $totSan = 0;
                            foreach (Constants::ANNEXURE_1_HEADERS as $headKey => $headInfo):
                                $reqAmount = 0;
                                foreach ($groupedBudget[$headKey] ?? [] as $bItem) {
                                    $reqAmount += (float)$bItem['total'];
                                }
                                $sanAmount = isset($moderatedBudget[$headKey]) ? (float)$moderatedBudget[$headKey] : $reqAmount;
                                $totReq += $reqAmount;
                                $totSan += $sanAmount;
                        ?>
                            <tr class="hover:bg-slate-50">
                                <td class="p-2 border-r border-slate-200 text-center font-mono text-slate-400"><?= $bIdx++ ?></td>
                                <td class="p-2 border-r border-slate-200">
                                    <strong class="text-slate-800"><?= htmlspecialchars($headInfo['name']) ?></strong>
                                    <p class="text-[10px] text-slate-500"><?= htmlspecialchars($headInfo['description']) ?></p>
                                </td>
                                <td class="p-2 border-r border-slate-200 text-right font-mono">₹<?= number_format($reqAmount, 2) ?></td>
                                <td class="p-2 text-right font-mono font-bold bg-emerald-50/50 text-emerald-900">₹<?= number_format($sanAmount, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-slate-100 border-t-2 border-slate-300 font-bold text-xs">
                        <tr>
                            <td colspan="2" class="p-2 text-right">Contingency Reserve (5%):</td>
                            <td class="p-2 text-right font-mono">₹<?= number_format(round($totReq * 0.05, 2), 2) ?></td>
                            <td class="p-2 text-right font-mono bg-emerald-50 text-emerald-900">₹<?= number_format(round($totSan * 0.05, 2), 2) ?></td>
                        </tr>
                        <tr class="bg-indigo-50 text-indigo-950 border-t border-indigo-200">
                            <td colspan="2" class="p-2 text-right text-sm">Grand Total Financial Sanction:</td>
                            <td class="p-2 text-right font-mono text-sm">₹<?= number_format($totReq + round($totReq * 0.05, 2), 2) ?></td>
                            <td class="p-2 text-right font-mono text-sm font-black text-emerald-700 bg-emerald-100">
                                ₹<?= number_format($totSan + round($totSan * 0.05, 2), 2) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Institutional Terms & Grant Conditions -->
        <div class="mb-12 p-4 bg-slate-50 rounded-xl border border-slate-200 text-[11px] text-slate-700 space-y-2">
            <div class="font-bold uppercase text-slate-900 text-xs"><?= htmlspecialchars(Constants::TERMS_TITLE) ?></div>
            <?php $cnt = 1; foreach (Constants::TERMS_AND_CONDITIONS as $termTitle => $termText): ?>
                <p><strong><?= $cnt++ ?>. <?= htmlspecialchars($termTitle) ?>:</strong> <?= htmlspecialchars($termText) ?></p>
            <?php endforeach; ?>
            <div class="text-[10px] font-semibold text-slate-500 pt-1 border-t border-slate-200 italic">
                <?= htmlspecialchars(Constants::TERMS_FOOTNOTE) ?>
            </div>
        </div>

        <!-- 5 Institutional Signature Blocks (Policy Requirement) -->
        <div class="pt-8 border-t-2 border-slate-900 page-break">
            <div class="text-xs font-bold uppercase text-slate-700 mb-8 text-center tracking-wider">Formal Signatures & Institutional Endorsement</div>
            <div class="grid grid-cols-3 gap-6 text-center text-xs mb-8">
                <div class="border-t border-slate-400 pt-3">
                    <div class="font-bold text-slate-900">Er. Arun Singh Kushwah</div>
                    <div class="text-[10px] text-slate-600">Assistant Director, Startups</div>
                    <div class="text-[9px] text-slate-500">School of Engg. & Technology (SOET)</div>
                    <div class="text-[9px] font-mono text-emerald-700 mt-2 font-semibold">✓ Verified SEC Convener</div>
                </div>
                <div class="border-t border-slate-400 pt-3">
                    <div class="font-bold text-slate-900">Dr. Ambar Agarwal</div>
                    <div class="text-[10px] text-slate-600">Member, SEC Committee</div>
                    <div class="text-[9px] text-slate-500">School of Management (SOM)</div>
                    <div class="text-[9px] font-mono text-emerald-700 mt-2 font-semibold">✓ Verified SEC Finance</div>
                </div>
                <div class="border-t border-slate-400 pt-3">
                    <div class="font-bold text-slate-900">Prof. Ashish Chandel</div>
                    <div class="text-[10px] text-slate-600">Director, IDEAPAD Incubator</div>
                    <div class="text-[9px] text-slate-500">Professor of Practice, ITM Gwalior</div>
                    <div class="text-[9px] font-mono text-emerald-700 mt-2 font-semibold">✓ Sanction Approved</div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-12 text-center text-xs max-w-lg mx-auto pt-6 border-t border-slate-200">
                <div class="border-t border-slate-400 pt-3">
                    <div class="font-bold text-slate-900">Finance & Accounts Officer</div>
                    <div class="text-[10px] text-slate-600">ITM Gwalior</div>
                    <div class="text-[9px] font-mono text-slate-400 mt-2">[ Fund Disbursal Concurrence ]</div>
                </div>
                <div class="border-t border-slate-400 pt-3">
                    <div class="font-bold text-slate-900">Registrar</div>
                    <div class="text-[10px] text-slate-600">ITM Gwalior</div>
                    <div class="text-[9px] font-mono text-slate-400 mt-2">[ Institutional Authority ]</div>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

</body>
</html>
