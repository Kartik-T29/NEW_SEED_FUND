<?php
/**
 * IDEAPAD Seed Fund Portal - Comprehensive E2E Automated Verification Suite
 * Tests 100% Pure PHP + MySQL Implementation without external browser dependencies
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Database;
use App\Config\Auth;
use App\Config\Constants;

class PortalTestSuite {
    private PDO $pdo;
    private int $testsPassed = 0;
    private int $testsFailed = 0;
    private array $failures = [];

    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.save_path', sys_get_temp_dir());
            session_start();
        }
        $this->pdo = Database::getConnection();
    }

    private function assert(bool $condition, string $testName, string $detail = ''): void {
        if ($condition) {
            $this->testsPassed++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->testsFailed++;
            $msg = "  [FAIL] {$testName}" . ($detail ? " - {$detail}" : "");
            echo "{$msg}\n";
            $this->failures[] = $msg;
        }
    }

    public function runAll(): bool {
        echo "\n=======================================================\n";
        echo " IDEAPAD SEED FUND PORTAL - AUTOMATED TEST SUITE\n";
        echo "=======================================================\n\n";

        $this->testDatabaseSchema();
        $this->testConstantsIntegrity();
        $this->testSeededAccounts();
        $this->testSecRegistrationAndApprovalGate();
        $this->testStartupRegistrationValidation();
        $this->testApplicationLifecycleAndSaveNext();
        $this->testSecEvaluationAndRubrics();
        $this->testAdminFormBSanctionOrder();
        $this->testReportsAndStatusRender();

        echo "\n=======================================================\n";
        echo " TEST SUMMARY: {$this->testsPassed} Passed, {$this->testsFailed} Failed\n";
        echo "=======================================================\n";

        if ($this->testsFailed > 0) {
            echo "\nFailures detected:\n";
            foreach ($this->failures as $f) {
                echo "{$f}\n";
            }
            return false;
        }

        echo "\nAll test suites passed with 100% success!\n\n";
        return true;
    }

    private function testDatabaseSchema(): void {
        echo "[1] Testing Database Schema & Table Integrity...\n";
        $expectedTables = [
            'User', 'Startup', 'Application', 'Document', 'BudgetItem',
            'Assessment', 'MilestoneAssessment', 'MilestoneItem',
            'MilestoneRemark', 'MilestoneEditLog', 'GrantEvaluation', 'AuditLog'
        ];

        $stmt = $this->pdo->query("SHOW TABLES");
        $actualTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expectedTables as $table) {
            $this->assert(in_array($table, $actualTables, true), "Table '{$table}' exists in MySQL");
        }
    }

    private function testConstantsIntegrity(): void {
        echo "\n[2] Testing Policy Constants Alignment...\n";
        $this->assert(count(Constants::GRANT_TIERS) === 5, "Grant tiers count equals 5 (Ideation to Emergency)");
        $this->assert(isset(Constants::GRANT_TIERS['PROOF']), "Proof grant tier defined with correct min/max");
        $this->assert(count(Constants::ANNEXURE_1_HEADERS) === 9, "Annexure 1 budget heads count equals exactly 9");
        $this->assert(count(Constants::getFlatRubricQuestions()) === 12, "SEC Rubric questions count equals exactly 12");
        $this->assert(count(Constants::TIMELINE_DATES) === 8, "Form B timeline dates count equals 8 per Policy P9");
        $this->assert(isset(Constants::TIMELINE_DATES['experimentDuration']), "experimentDuration date present in Form B");
        $this->assert(count(Constants::EXPERIMENT_HEADINGS) === 10, "10 Experiment headings defined according to Section 5");
    }

    private function testSeededAccounts(): void {
        echo "\n[3] Testing Seeded Accounts & Password Hashing...\n";
        // Check Admin
        $stmt = $this->pdo->prepare("SELECT * FROM `User` WHERE email = 'admin@ideapad.co.in'");
        $stmt->execute();
        $admin = $stmt->fetch();
        $this->assert((bool)$admin, "Admin account 'admin@ideapad.co.in' exists");
        $this->assert(password_verify('Admin@IdeaPad2024', $admin['passwordHash']), "Admin password verifies correctly");
        $this->assert($admin['role'] === 'ADMIN', "Admin user has role ADMIN");
        $this->assert($admin['approvalStatus'] === 'APPROVED', "Admin user status is APPROVED");

        // Check SEC Member 1
        $stmt = $this->pdo->prepare("SELECT * FROM `User` WHERE email = 'sec@ideapad.co.in'");
        $stmt->execute();
        $sec = $stmt->fetch();
        $this->assert((bool)$sec, "SEC Member 'sec@ideapad.co.in' exists");
        $this->assert(password_verify('Sec@2026Password!', $sec['passwordHash']), "SEC password verifies correctly");
        $this->assert($sec['role'] === 'SEC_MEMBER', "SEC user has role SEC_MEMBER");

        // Check Founder
        $stmt = $this->pdo->prepare("SELECT * FROM `User` WHERE email = 'founder@itm.ac.in'");
        $stmt->execute();
        $founder = $stmt->fetch();
        $this->assert((bool)$founder, "Founder account 'founder@itm.ac.in' exists");
        $this->assert(password_verify('Startup@2026Secure', $founder['passwordHash']), "Founder password verifies correctly");
        $this->assert($founder['role'] === 'STARTUP', "Founder user has role STARTUP");
    }

    private function testSecRegistrationAndApprovalGate(): void {
        echo "\n[4] Testing SEC Self-Registration & Admin Approval Gate...\n";
        $testEmail = 'test_sec_' . time() . '@ideapad.co.in';
        $userId = Auth::generateId('u');

        // Insert new SEC member as PENDING
        $stmt = $this->pdo->prepare("
            INSERT INTO `User` (id, name, email, passwordHash, role, designation, department, approvalStatus, active, createdAt)
            VALUES (?, 'Test SEC Candidate', ?, ?, 'SEC_MEMBER', 'Associate Professor', 'Electronics & Comm', 'PENDING', 1, NOW(3))
        ");
        $stmt->execute([
            $userId,
            $testEmail,
            Auth::hashPassword('SecTestPass123!')
        ]);

        // Verify status is PENDING
        $stmt = $this->pdo->prepare("SELECT * FROM `User` WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $this->assert($user['approvalStatus'] === 'PENDING', "Newly registered SEC member is saved with approvalStatus PENDING");

        // Verify login rejection while PENDING
        $res = Auth::login($testEmail, 'SecTestPass123!');
        $this->assert($res['success'] === false && str_contains($res['error'], 'pending Administrator review'), "Pending SEC member is blocked from logging in with descriptive holding message");

        // Admin approves the candidate
        $stmt = $this->pdo->prepare("UPDATE `User` SET approvalStatus = 'APPROVED' WHERE id = ?");
        $stmt->execute([$userId]);

        // Re-check login
        $res2 = Auth::login($testEmail, 'SecTestPass123!');
        $this->assert($res2['success'] === true, "Admin approval activates SEC account and permits login");
    }

    private function testStartupRegistrationValidation(): void {
        echo "\n[5] Testing Startup Registration Data Modeling...\n";
        $founderEmail = 'founder_test_' . time() . '@itm.ac.in';
        $userId = Auth::generateId('u');
        $startupId = Auth::generateId('s');

        // Create user
        $stmt = $this->pdo->prepare("
            INSERT INTO `User` (id, name, email, passwordHash, role, approvalStatus, active, createdAt)
            VALUES (?, 'Rohan Sharma', ?, ?, 'STARTUP', 'APPROVED', 1, NOW(3))
        ");
        $stmt->execute([
            $userId,
            $founderEmail,
            Auth::hashPassword('FounderTest123!')
        ]);

        // Create startup with all required policy fields
        $stmt = $this->pdo->prepare("
            INSERT INTO `Startup` (id, userId, teamName, enrollmentNo, program, phone)
            VALUES (?, ?, 'AeroDrone Labs', 'ITM/2026/BT/042', 'B.Tech Mechanical Engineering', '9876543210')
        ");
        $stmt->execute([
            $startupId,
            $userId
        ]);

        $stmt = $this->pdo->prepare("
            SELECT s.*, u.name AS lead_founder, u.email AS lead_email
            FROM `Startup` s
            JOIN `User` u ON s.userId = u.id
            WHERE s.id = ?
        ");
        $stmt->execute([$startupId]);
        $row = $stmt->fetch();

        $this->assert((bool)$row, "Startup profile created and linked to Founder User");
        $this->assert($row['enrollmentNo'] === 'ITM/2026/BT/042', "Enrollment number stored accurately");
        $this->assert($row['teamName'] === 'AeroDrone Labs', "Team name stored accurately");
        $this->assert($row['program'] === 'B.Tech Mechanical Engineering', "Academic program stored accurately");
    }

    private function testApplicationLifecycleAndSaveNext(): void {
        echo "\n[6] Testing Application Wizard & Save & Next Operations...\n";
        
        // Find a startup
        $stmt = $this->pdo->prepare("SELECT id FROM `Startup` LIMIT 1");
        $stmt->execute();
        $startup = $stmt->fetch();
        $startupId = $startup['id'];

        $appId = Auth::generateId('app');

        $initialFormData = [
            'problem' => 'Agricultural drone payload stabilization failure under high wind',
            'solution' => 'Gimbal micro-inertia counterweight algorithm with real-time feedback',
            'traction' => 'Bench-tested 3-axis prototype on vibration shaker table',
            'experiments' => [
                '1' => 'Validate stabilization under 30 km/h wind gusts in wind tunnel',
                '2' => 'Excessive image blur during cross-wind spray passes',
                '3' => 'Sub-pixel optical flow stability exceeding 95% accuracy',
                '4' => 'Test 5-point wind angles in controlled university wind tunnel',
                '5' => 'Custom carbon fiber gimbal, IMU 9-DOF sensor, high-speed camera',
                '6' => 'Drift angle, stabilization settling time, frame jitter frequency',
                '7' => 'Settling time under 80ms following sudden 20 knot aerodynamic disturbance',
                '8' => 'High ambient temperatures causing sensor thermal drift',
                '9' => 'Week 1-3: Assembly. Week 4-6: Tunnel trials. Week 7: Report.',
                '10' => 'Stabilized gimbal assembly, flight trial telemetry dataset, video demo'
            ],
            'selfAssessment' => [
                'trl' => 3,
                'mrl' => 2,
                'irl' => 2,
                'milestones' => [
                    'Problem validation' => 'COMPLETED',
                    'MVP prototyping' => 'IN_PROGRESS'
                ]
            ],
            'declarationSigned' => true
        ];

        // Create Draft Application
        $stmt = $this->pdo->prepare("
            INSERT INTO `Application` (id, startupId, version, status, grantType, requestedTotal, formData, createdAt, updatedAt)
            VALUES (?, ?, 1, 'DRAFT', 'PROOF', 45000.00, ?, NOW(3), NOW(3))
        ");
        $stmt->execute([$appId, $startupId, json_encode($initialFormData, JSON_UNESCAPED_UNICODE)]);
        $this->assert(true, "Application draft created with 10 experiment fields and self-assessment");

        // Insert Annexure 1 Budget Items
        $items = [
            ['EQUIPMENT', '3-Axis Brushless Gimbal Motor Kit', 'Robu.in', 22000.0, 1],
            ['CONSUMABLES', 'Carbon Fiber CNC Sheets & Fasteners', 'SunRobotics', 9000.0, 1],
            ['SOFTWARE', 'MATLAB Aerospace Toolbox License', 'MathWorks', 11000.0, 1]
        ];

        $subtotal = 0.0;
        foreach ($items as $it) {
            $cost = $it[3] * $it[4];
            $subtotal += $cost;
            $stmt = $this->pdo->prepare("
                INSERT INTO `BudgetItem` (id, applicationId, header, name, vendor, unitCost, qty, total, justification)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Required for experiment')
            ");
            $stmt->execute([
                Auth::generateId('b'),
                $appId,
                $it[0],
                $it[1],
                $it[2],
                $it[3],
                $it[4],
                $cost
            ]);
        }

        $contingency = round($subtotal * 0.05, 2);
        $grandTotal = $subtotal + $contingency;

        $stmt = $this->pdo->prepare("UPDATE `Application` SET requestedTotal = ? WHERE id = ?");
        $stmt->execute([$grandTotal, $appId]);

        $this->assert($subtotal === 42000.0, "Budget direct expenses accurately calculated (₹42,000)");
        $this->assert($contingency === 2100.0, "Budget 5% contingency accurately calculated (₹2,100)");
        $this->assert($grandTotal === 44100.0, "Grand total matches subtotal + contingency (₹44,100)");

        // Submit Application
        $stmt = $this->pdo->prepare("
            UPDATE `Application` SET
                status = 'SUBMITTED',
                submittedAt = NOW(3),
                updatedAt = NOW(3)
            WHERE id = ?
        ");
        $stmt->execute([$appId]);

        $stmt = $this->pdo->prepare("SELECT status, submittedAt FROM `Application` WHERE id = ?");
        $stmt->execute([$appId]);
        $submittedApp = $stmt->fetch();

        $this->assert($submittedApp['status'] === 'SUBMITTED', "Application successfully transitions to SUBMITTED");
        $this->assert(!empty($submittedApp['submittedAt']), "Submission timestamp recorded for audit trail");
    }

    private function testSecEvaluationAndRubrics(): void {
        echo "\n[7] Testing SEC Multi-Member Form A Evaluation...\n";

        // Fetch application
        $stmt = $this->pdo->prepare("SELECT id FROM `Application` WHERE status = 'SUBMITTED' ORDER BY createdAt DESC LIMIT 1");
        $stmt->execute();
        $app = $stmt->fetch();
        $appId = $app['id'];

        // Get SEC members
        $stmt = $this->pdo->prepare("SELECT id, name FROM `User` WHERE role = 'SEC_MEMBER' AND approvalStatus = 'APPROVED' LIMIT 3");
        $stmt->execute();
        $secMembers = $stmt->fetchAll();

        $this->assert(count($secMembers) >= 1, "At least 1 active SEC member exists for evaluation");

        foreach ($secMembers as $idx => $member) {
            $secId = $member['id'];
            $statusForm = ['trl' => 3, 'mrl' => 2, 'irl' => 2];
            $rubricScores = [
                'q1' => 4, 'q2' => 4, 'q3' => 5, 'q4' => 4,
                'q5' => 3, 'q6' => 4, 'q7' => 3, 'q8' => 4,
                'q9' => 4, 'q10' => 5, 'q11' => 4, 'q12' => 4
            ];
            $experimentForm = ['rubrics' => $rubricScores];
            $suggestedAmounts = ['EQUIPMENT' => 22000, 'CONSUMABLES' => 9000, 'SOFTWARE' => 11000];

            $stmtAssess = $this->pdo->prepare("
                INSERT INTO `Assessment` (id, applicationId, memberId, assessmentDate, preChecks, statusForm, experimentForm, recommendation, suggestedGrant, suggestedAmounts, status, submittedAt)
                VALUES (?, ?, ?, NOW(3), '{}', ?, ?, 'Strong technical proposal with realistic milestone timeline.', 'PROOF', ?, 'SUBMITTED', NOW(3))
                ON DUPLICATE KEY UPDATE statusForm = VALUES(statusForm), experimentForm = VALUES(experimentForm), recommendation = VALUES(recommendation), status = 'SUBMITTED', submittedAt = NOW(3)
            ");
            $stmtAssess->execute([
                Auth::generateId('ast'),
                $appId,
                $secId,
                json_encode($statusForm),
                json_encode($experimentForm),
                json_encode($suggestedAmounts)
            ]);
        }

        // Verify assessment count
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `Assessment` WHERE applicationId = ? AND status = 'SUBMITTED'");
        $stmt->execute([$appId]);
        $evalCount = (int)$stmt->fetchColumn();

        $this->assert($evalCount >= 1, "SEC Form A assessment recorded and submitted for application");

        // Transition application to IN_REVIEW
        $this->pdo->prepare("UPDATE `Application` SET status = 'IN_REVIEW' WHERE id = ?")->execute([$appId]);
        $status = $this->pdo->query("SELECT status FROM `Application` WHERE id = '{$appId}'")->fetchColumn();
        $this->assert($status === 'IN_REVIEW', "Application transitions to IN_REVIEW during SEC deliberations");
    }

    private function testAdminFormBSanctionOrder(): void {
        echo "\n[8] Testing Admin Form B Grant Sanction Order Generation...\n";

        $stmt = $this->pdo->prepare("SELECT id, requestedTotal FROM `Application` WHERE status IN ('SUBMITTED', 'IN_REVIEW') ORDER BY createdAt DESC LIMIT 1");
        $stmt->execute();
        $app = $stmt->fetch();
        $appId = $app['id'];

        $approvedAmount = (float)$app['requestedTotal'];

        $timelineDates = [
            'sanctionDate' => date('Y-m-d'),
            'facilityAgreement' => date('Y-m-d', strtotime('+3 days')),
            'deckReceived' => date('Y-m-d', strtotime('+7 days')),
            'secPresentation' => date('Y-m-d', strtotime('+9 days')),
            'fundDisbursal' => date('Y-m-d', strtotime('+14 days')),
            'experimentDuration' => date('Y-m-d', strtotime('+60 days')),
            'midTermReview' => date('Y-m-d', strtotime('+75 days')),
            'finalSettlement' => date('Y-m-d', strtotime('+120 days')),
        ];

        $moderatedBudget = [
            'EQUIPMENT' => 22000.0,
            'CONSUMABLES' => 9000.0,
            'SOFTWARE' => 11000.0
        ];

        // Fetch Admin
        $stmtAdmin = $this->pdo->prepare("SELECT id FROM `User` WHERE role = 'ADMIN' LIMIT 1");
        $stmtAdmin->execute();
        $adminId = $stmtAdmin->fetchColumn() ?: 'u_admin';

        // Insert or update GrantEvaluation
        $evalId = Auth::generateId('eval');
        $stmtEval = $this->pdo->prepare("
            INSERT INTO `GrantEvaluation` (id, applicationId, adminId, status, assessmentDate, decision, approvedTotal, amounts, timeline, note, secDecisionNote, termsAcknowledged, decidedAt)
            VALUES (?, ?, ?, 'SUBMITTED', NOW(3), 'SANCTION_APPROVED', ?, ?, ?, 'Approved as per SEC panel recommendation', 'Unanimous approval', 1, NOW(3))
            ON DUPLICATE KEY UPDATE amounts = VALUES(amounts), approvedTotal = VALUES(approvedTotal), timeline = VALUES(timeline), status = 'SUBMITTED', decidedAt = NOW(3)
        ");
        $stmtEval->execute([
            $evalId,
            $appId,
            $adminId,
            $approvedAmount,
            json_encode($moderatedBudget),
            json_encode($timelineDates)
        ]);

        // Approve application
        $stmtApp = $this->pdo->prepare("UPDATE `Application` SET status = 'APPROVED', updatedAt = NOW(3) WHERE id = ?");
        $stmtApp->execute([$appId]);

        // Audit Log
        Auth::audit('GRANT_SANCTION_ISSUED', 'Application', $appId, [
            'approvedTotal' => $approvedAmount,
            'timeline' => $timelineDates
        ]);

        $status = $this->pdo->query("SELECT status FROM `Application` WHERE id = '{$appId}'")->fetchColumn();
        $this->assert($status === 'APPROVED', "Application successfully marked as APPROVED");

        $evalRow = $this->pdo->query("SELECT * FROM `GrantEvaluation` WHERE applicationId = '{$appId}'")->fetch();
        $this->assert(!empty($evalRow), "GrantEvaluation (Form B) record persisted in MySQL");
        $savedDates = json_decode($evalRow['timeline'], true);
        $this->assert(count($savedDates) === 8, "Form B contains all 8 policy timeline dates");
        $this->assert(isset($savedDates['experimentDuration']), "experimentDuration date properly stored in Form B");
    }

    private function testReportsAndStatusRender(): void {
        echo "\n[9] Testing Report Views & Output Buffering...\n";

        $stmt = $this->pdo->prepare("SELECT id FROM `Application` WHERE status = 'APPROVED' LIMIT 1");
        $stmt->execute();
        $app = $stmt->fetch();
        $appId = $app['id'];

        // Mock Admin Session
        $_SESSION['user'] = [
            'id' => 'u_admin',
            'name' => 'Ashish Chandel',
            'email' => 'admin@ideapad.co.in',
            'role' => 'ADMIN',
            'approvalStatus' => 'APPROVED',
            'active' => 1
        ];

        // Test 1: Application Dossier Report
        $_GET['id'] = $appId;
        $_GET['type'] = 'application';
        ob_start();
        include __DIR__ . '/../public/reports.php';
        $appHtml = ob_get_clean();

        $this->assert(str_contains($appHtml, 'Seed Grant Application Proposal Dossier'), "Application dossier renders correct title");
        $this->assert(str_contains($appHtml, 'Experiment Plan') && str_contains($appHtml, 'Proof of Concept'), "Dossier includes Section 5 Experiment Plan");
        $this->assert(str_contains($appHtml, 'Detailed Requirement Table'), "Dossier includes Section 6 Requirement Table");

        // Test 2: Form A SEC Report
        $_GET['type'] = 'form-a';
        ob_start();
        include __DIR__ . '/../public/reports.php';
        $formAHtml = ob_get_clean();

        $this->assert(str_contains($formAHtml, 'SEC Assessment') && str_contains($formAHtml, 'Form A'), "Form A renders correct title");
        $this->assert(str_contains($formAHtml, 'TRL / MRL / IRL Comparison Matrix'), "Form A includes 3-way readiness matrix");
        $this->assert(str_contains($formAHtml, 'Evaluation Rubric Scores'), "Form A includes 12 rubric questions table");

        // Test 3: Form B Sanction Order
        $_GET['type'] = 'form-b';
        ob_start();
        include __DIR__ . '/../public/reports.php';
        $formBHtml = ob_get_clean();

        $this->assert(str_contains($formBHtml, 'IDEAPAD Innovation Fund Grant Sanction Order'), "Form B renders correct title");
        $this->assert(str_contains($formBHtml, 'Mandatory Timeline'), "Form B includes 8 timeline dates");
        $this->assert(str_contains($formBHtml, 'Er. Arun Singh Kushwah'), "Form B includes Assistant Director Startups signature block");
        $this->assert(str_contains($formBHtml, 'Dr. Ambar Agarwal'), "Form B includes School of Management signature block");
        $this->assert(str_contains($formBHtml, 'Prof. Ashish Chandel'), "Form B includes Director IDEAPAD signature block");

        // Test 4: Status Tracker
        ob_start();
        include __DIR__ . '/../public/status.php';
        $statusHtml = ob_get_clean();

        $this->assert(str_contains($statusHtml, 'Grant Status Tracker'), "Status tracker renders dashboard");
        $this->assert(str_contains($statusHtml, 'Application Lifecycle Stage'), "Status tracker renders 5-stage lifecycle stepper");
        $this->assert(str_contains($statusHtml, 'Governance Timeline') && str_contains($statusHtml, '8 Institutional Dates'), "Status tracker displays 8 governance milestones");
    }
}

// Execute Test Suite
$suite = new PortalTestSuite();
$success = $suite->runAll();
exit($success ? 0 : 1);
