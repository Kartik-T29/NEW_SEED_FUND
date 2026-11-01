<?php
/**
 * Database Seeder for IDEAPAD Seed Fund Portal
 * Run via CLI: php database/seed.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Database;
use App\Config\Auth;

echo "==> Seeding IDEAPAD Seed Fund Database...\n";

$pdo = Database::getConnection();

// Create Administrator
$adminEmail = 'admin@ideapad.co.in';
$stmt = $pdo->prepare('SELECT id FROM `User` WHERE email = ? LIMIT 1');
$stmt->execute([$adminEmail]);
$admin = $stmt->fetch();

if (!$admin) {
    $adminId = Auth::generateId('u');
    $ins = $pdo->prepare('INSERT INTO `User` (id, email, name, passwordHash, role, approvalStatus, mustChangePassword, active, designation, department, createdAt) VALUES (?, ?, ?, ?, "ADMIN", "APPROVED", 0, 1, "Director IDEAPAD", "Innovation & Incubation", NOW(3))');
    $ins->execute([$adminId, $adminEmail, 'Ashish Chandel', Auth::hashPassword('Admin@IdeaPad2024')]);
    echo "  [+] Created Admin: {$adminEmail} / Admin@IdeaPad2024\n";
} else {
    $upd = $pdo->prepare('UPDATE `User` SET passwordHash = ?, approvalStatus = "APPROVED", active = 1 WHERE email = ?');
    $upd->execute([Auth::hashPassword('Admin@IdeaPad2024'), $adminEmail]);
    echo "  [~] Updated Admin: {$adminEmail}\n";
}

// Create SEC Evaluator 1 (Dr. Anand Verma)
$sec1Email = 'sec@ideapad.co.in';
$stmt = $pdo->prepare('SELECT id FROM `User` WHERE email = ? LIMIT 1');
$stmt->execute([$sec1Email]);
if (!$stmt->fetch()) {
    $sec1Id = Auth::generateId('u');
    $ins = $pdo->prepare('INSERT INTO `User` (id, email, name, passwordHash, role, approvalStatus, mustChangePassword, active, designation, department, createdAt) VALUES (?, ?, ?, ?, "SEC_MEMBER", "APPROVED", 0, 1, "Professor & HOD", "Computer Science & Engineering", NOW(3))');
    $ins->execute([$sec1Id, $sec1Email, 'Dr. Anand Verma', Auth::hashPassword('Sec@2026Password!')]);
    echo "  [+] Created SEC 1: {$sec1Email} / Sec@2026Password!\n";
} else {
    $upd = $pdo->prepare('UPDATE `User` SET passwordHash = ?, approvalStatus = "APPROVED", active = 1 WHERE email = ?');
    $upd->execute([Auth::hashPassword('Sec@2026Password!'), $sec1Email]);
    echo "  [~] Updated SEC 1: {$sec1Email}\n";
}

// Create SEC Evaluator 2 (Dr. Priya Mishra)
$sec2Email = 'sec2@ideapad.co.in';
$stmt = $pdo->prepare('SELECT id FROM `User` WHERE email = ? LIMIT 1');
$stmt->execute([$sec2Email]);
if (!$stmt->fetch()) {
    $sec2Id = Auth::generateId('u');
    $ins = $pdo->prepare('INSERT INTO `User` (id, email, name, passwordHash, role, approvalStatus, mustChangePassword, active, designation, department, createdAt) VALUES (?, ?, ?, ?, "SEC_MEMBER", "APPROVED", 0, 1, "Associate Professor", "School of Management", NOW(3))');
    $ins->execute([$sec2Id, $sec2Email, 'Dr. Priya Mishra', Auth::hashPassword('Sec@2026Password!')]);
    echo "  [+] Created SEC 2: {$sec2Email} / Sec@2026Password!\n";
} else {
    $upd = $pdo->prepare('UPDATE `User` SET passwordHash = ?, approvalStatus = "APPROVED", active = 1 WHERE email = ?');
    $upd->execute([Auth::hashPassword('Sec@2026Password!'), $sec2Email]);
    echo "  [~] Updated SEC 2: {$sec2Email}\n";
}

// Create SEC Evaluator 3 (Dr. Arun Singh Kushwah)
$sec3Email = 'sec3@ideapad.co.in';
$stmt = $pdo->prepare('SELECT id FROM `User` WHERE email = ? LIMIT 1');
$stmt->execute([$sec3Email]);
if (!$stmt->fetch()) {
    $sec3Id = Auth::generateId('u');
    $ins = $pdo->prepare('INSERT INTO `User` (id, email, name, passwordHash, role, approvalStatus, mustChangePassword, active, designation, department, createdAt) VALUES (?, ?, ?, ?, "SEC_MEMBER", "APPROVED", 0, 1, "Assistant Director (Startups)", "SOET, ITM Gwalior", NOW(3))');
    $ins->execute([$sec3Id, $sec3Email, 'Dr. Arun Singh Kushwah', Auth::hashPassword('Sec@2026Password!')]);
    echo "  [+] Created SEC 3: {$sec3Email} / Sec@2026Password!\n";
} else {
    $upd = $pdo->prepare('UPDATE `User` SET passwordHash = ?, approvalStatus = "APPROVED", active = 1 WHERE email = ?');
    $upd->execute([Auth::hashPassword('Sec@2026Password!'), $sec3Email]);
    echo "  [~] Updated SEC 3: {$sec3Email}\n";
}

// Create Startup Founder (Vikramaditya Sharma / SolarVidyut AI)
$founderEmail = 'founder@itm.ac.in';
$stmt = $pdo->prepare('SELECT id FROM `User` WHERE email = ? LIMIT 1');
$stmt->execute([$founderEmail]);
$founder = $stmt->fetch();

if (!$founder) {
    $founderId = Auth::generateId('u');
    $startupId = Auth::generateId('s');

    $ins = $pdo->prepare('INSERT INTO `User` (id, email, name, passwordHash, role, approvalStatus, mustChangePassword, active, createdAt) VALUES (?, ?, ?, ?, "STARTUP", "APPROVED", 0, 1, NOW(3))');
    $ins->execute([$founderId, $founderEmail, 'Vikramaditya Sharma', Auth::hashPassword('Startup@2026Secure')]);

    $sIns = $pdo->prepare('INSERT INTO `Startup` (id, userId, teamName, enrollmentNo, program, phone) VALUES (?, ?, ?, ?, ?, ?)');
    $sIns->execute([$startupId, $founderId, 'SolarVidyut AI', '0901IT221050', 'B.Tech Information Technology', '9876543210']);

    // Seed a sample application
    $appId = Auth::generateId('app');
    $formData = [
        'applicantName' => 'Vikramaditya Sharma',
        'teamName' => 'SolarVidyut AI',
        'enrollmentNo' => '0901IT221050',
        'program' => 'B.Tech Information Technology',
        'phone' => '9876543210',
        'email' => 'founder@itm.ac.in',
        'problemStatement' => 'Commercial solar installations suffer 15-22% annual revenue loss due to unpredicted micro-inverter thermal throttling and dust soiling anomalies.',
        'proposedSolution' => 'An edge-compute IoT sensor array coupled with a lightweight local transformer neural network providing predictive soiling diagnostics and inverter health analytics.',
        'effortTraction' => 'Conducted 24 customer discovery interviews with industrial rooftop operators; built breadboard hardware sensor rig collecting temperature/voltage telemetries on 5kW campus test array.',
        'experiments' => [
            '1' => 'Evaluate thermal anomaly detection accuracy of our low-power MCU inference module against high-precision calibrated pyranometer standards.',
            '2' => 'Existing cloud monitoring systems have high latency (>15 min) and miss micro-arcing events, causing preventable fires and system shut-downs.',
            '3' => 'Achieve >94% F1-score in detecting dust soiling degradation and inverter thermal drift within 120 seconds of onset at TRL-3 bench level.',
            '4' => 'Deploy 4 sensor probes on an experimental 3kW photovoltaic array and stream real-time current-voltage curves into the local edge inference unit.',
            '5' => 'ESP32-S3 inference microcontroller, 4 custom Hall-effect current sensors, 2 PT100 thermal probes, bench power supply, variable artificial soiling chamber.',
            '6' => 'High-frequency AC/DC ripple voltage, junction temperature (50Hz), irradiance flux (W/m2), output power loss percentage.',
            '7' => 'Statistically significant anomaly detection with false positive rate below 2% across 50 simulated environmental stress cycles.',
            '8' => 'Uncalibrated sensor drift under extreme ambient heat (>48C); dependence on timely component supply from specialized electronics vendors.',
            '9' => 'Month 1: Prototype PCB fabrication and calibration. Month 2: Sensor assembly and indoor test rig. Month 3: Solar bench trials and validation report.',
            '10' => 'Functional TRL-3 benchtop prototype, open validation dataset with 100,000 recorded telemetry vectors, and formal test demonstration dossier.'
        ],
        'selfAssessment' => [
            'trl' => 3,
            'mrl' => 3,
            'irl' => 2,
            'track' => 'PROTOTYPING',
            'milestones' => [
                'Customer interviews' => ['status' => 'COMPLETED', 'remarks' => 'Completed 24 interviews with local solar EPC contractors'],
                'Empathy map and root cause analysis' => ['status' => 'COMPLETED', 'remarks' => 'Synthesized pain points into customer journey map'],
                'Problem framing and validation' => ['status' => 'COMPLETED', 'remarks' => 'Validated problem statement with solar plant managers'],
                'Ideation sprint' => ['status' => 'COMPLETED', 'remarks' => 'Designed architecture for edge vs cloud computation split'],
                'MVP sketching and hypothesis' => ['status' => 'COMPLETED', 'remarks' => 'Schematic design completed in KiCAD'],
                'Early user feedback and target market clarity' => ['status' => 'COMPLETED', 'remarks' => 'Reviewed schematics with incubation mentor'],
                'No cost/low cost product/idea/service development and field testing' => ['status' => 'PENDING', 'remarks' => 'Awaiting seed grant to purchase testing sensors and PCB boards'],
                'Present findings to mentors/incubator team' => ['status' => 'PENDING', 'remarks' => 'Scheduled for SEC presentation session'],
                'MVP revision' => ['status' => 'PENDING', 'remarks' => 'Will iterate post-lab testing']
            ]
        ],
        'agreementAccepted' => true,
        'declarationSigned' => true,
        'declarationDate' => date('Y-m-d')
    ];

    $appIns = $pdo->prepare('INSERT INTO `Application` (id, startupId, version, status, grantType, requestedTotal, formData, submittedAt, createdAt, updatedAt) VALUES (?, ?, 1, "SUBMITTED", "PROOF", 48000.00, ?, NOW(3), NOW(3), NOW(3))');
    $appIns->execute([$appId, $startupId, json_encode($formData, JSON_UNESCAPED_UNICODE)]);

    // Seed sample budget items
    $budgetItems = [
        ['EQUIPMENT', 'High-Precision Current & Irradiance Sensor Rig', 'Robu.in / Mouser', 16000.00, 1, 16000.00, 'Essential measurement instrument required for continuous telemetry recording during the TRL-3 experiment.'],
        ['CONSUMABLES', 'Custom 4-Layer Sensor PCBs and Electronic Components', 'JLCPCB & Robu.in', 14500.00, 1, 14500.00, 'Microcontrollers, Hall effect sensors, and passive components consumed during physical circuit board assembly.'],
        ['SOFTWARE', 'Edge ML Dataset Pipeline & Embedded Tooling Licenses', 'AWS / Edge Impulse', 6500.00, 1, 6500.00, 'Compute credits for training edge neural network models and compiling quantized firmware.'],
        ['TRAVEL', 'Travel to Industrial Solar Farms for Real-world Calibration', 'IRCTC & Local Mobility', 4000.00, 1, 4000.00, 'Travel to 3 regional utility installations to calibrate sensors under direct sunlight.'],
        ['TESTING', 'Lab Testing and Signal Noise Calibration at Lab', 'ITM Gwalior Research Lab', 4000.00, 1, 4000.00, 'EMI and thermal drift characterization tests.'],
        ['CONTINGENCY', 'Contingency / Small Spare Purchases (approx 6%)', 'Local Hardware Suppliers', 3000.00, 1, 3000.00, 'Unplanned replacement of blown fuses, passive resistors, and rapid breadboard connectors.']
    ];

    $bIns = $pdo->prepare('INSERT INTO `BudgetItem` (id, applicationId, header, name, vendor, unitCost, qty, total, justification) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($budgetItems as $b) {
        $bIns->execute([Auth::generateId('b'), $appId, $b[0], $b[1], $b[2], $b[3], $b[4], $b[5], $b[6]]);
    }

    echo "  [+] Created Startup: {$founderEmail} / Startup@2026Secure (Application: {$appId})\n";
} else {
    $upd = $pdo->prepare('UPDATE `User` SET passwordHash = ?, approvalStatus = "APPROVED", active = 1 WHERE email = ?');
    $upd->execute([Auth::hashPassword('Startup@2026Secure'), $founderEmail]);
    echo "  [~] Updated Startup: {$founderEmail}\n";
}

echo "==> Seeding completed successfully.\n";
