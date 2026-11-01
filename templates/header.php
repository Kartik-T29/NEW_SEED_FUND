<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

use App\Config\Auth;

$currentUser = Auth::user();
$currentRole = $currentUser['role'] ?? null;
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'IDEAPAD Seed Fund Portal | ITM University') ?></title>
  
  <!-- Favicon -->
  <link rel="icon" type="image/png" href="/brand/ideapad-logo.png">
  <link rel="apple-touch-icon" href="/brand/ideapad-logo.png">

  <!-- Tailwind CSS via CDN with brand styling -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: {
              50: '#eff6ff',
              100: '#dbeafe',
              500: '#3b82f6',
              600: '#2563eb',
              700: '#1d4ed8',
              800: '#1e40af',
              900: '#1e3a8a',
            }
          }
        }
      }
    }
  </script>

  <!-- Application Custom & Print Stylesheets -->
  <link rel="stylesheet" href="/css/app.css">
</head>
<body class="flex flex-col min-h-full font-sans antialiased text-gray-800 bg-gray-50">

  <!-- Top Institutional Bar (Hidden in print) -->
  <header class="no-print bg-white border-b border-gray-200 sticky top-0 z-40 shadow-xs">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        
        <!-- Brand Logo & University Title -->
        <div class="flex items-center gap-3">
          <a href="/index.php" class="flex items-center gap-3 group">
            <img 
              src="/brand/ideapad-logo.png" 
              alt="IDEAPAD - Technology Business Incubator, ITM University" 
              class="h-9 w-auto object-contain transition-transform group-hover:scale-105"
            />
            <div class="hidden sm:block border-l border-gray-300 pl-3">
              <span class="block text-xs font-bold text-gray-900 tracking-tight uppercase">Seed Fund Portal</span>
              <span class="block text-[10px] text-gray-500 font-medium">ITM University Gwalior</span>
            </div>
          </a>
        </div>

        <!-- Role-based Navigation Links -->
        <nav class="hidden md:flex items-center gap-1 text-sm font-medium">
          <?php if (!$currentUser): ?>
            <a href="/index.php" class="px-3 py-2 rounded-lg <?= $currentPage === 'index.php' ? 'text-blue-600 bg-blue-50 font-semibold' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100' ?>">Home</a>
            <a href="/register.php" class="px-3 py-2 rounded-lg <?= $currentPage === 'register.php' ? 'text-blue-600 bg-blue-50 font-semibold' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100' ?>">Startup Registration</a>
            <a href="/sec-register.php" class="px-3 py-2 rounded-lg <?= $currentPage === 'sec-register.php' ? 'text-purple-600 bg-purple-50 font-semibold' : 'text-gray-600 hover:text-purple-900 hover:bg-purple-50' ?>">SEC Evaluator Join</a>
            <a href="/login.php" class="ml-2 px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 shadow-xs">Sign In</a>
          <?php elseif ($currentRole === 'STARTUP'): ?>
            <a href="/dashboard.php" class="px-3 py-2 rounded-lg <?= $currentPage === 'dashboard.php' ? 'text-blue-600 bg-blue-50 font-semibold' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100' ?>">Application Form</a>
            <a href="/status.php" class="px-3 py-2 rounded-lg <?= $currentPage === 'status.php' ? 'text-blue-600 bg-blue-50 font-semibold' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100' ?>">Lifecycle Tracker</a>
            <a href="/reports.php" class="px-3 py-2 rounded-lg <?= $currentPage === 'reports.php' ? 'text-blue-600 bg-blue-50 font-semibold' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100' ?>">Proposal Dossier</a>
          <?php elseif ($currentRole === 'SEC_MEMBER'): ?>
            <a href="/sec.php" class="px-3 py-2 rounded-lg <?= $currentPage === 'sec.php' ? 'text-purple-600 bg-purple-50 font-semibold' : 'text-gray-600 hover:text-purple-900 hover:bg-purple-50' ?>">Evaluation Panel</a>
            <a href="/reports.php" class="px-3 py-2 rounded-lg <?= $currentPage === 'reports.php' ? 'text-purple-600 bg-purple-50 font-semibold' : 'text-gray-600 hover:text-purple-900 hover:bg-purple-50' ?>">Form A Reports</a>
          <?php elseif ($currentRole === 'ADMIN'): ?>
            <a href="/admin.php" class="px-3 py-2 rounded-lg <?= $currentPage === 'admin.php' ? 'text-blue-600 bg-blue-50 font-semibold' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100' ?>">Administration Console</a>
            <a href="/reports.php" class="px-3 py-2 rounded-lg <?= $currentPage === 'reports.php' ? 'text-blue-600 bg-blue-50 font-semibold' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100' ?>">Form A & B Dossiers</a>
          <?php endif; ?>
        </nav>

        <!-- User Profile Dropdown / Actions -->
        <?php if ($currentUser): ?>
          <div class="flex items-center gap-3">
            <!-- Role Badge -->
            <span class="hidden sm:inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider <?= $currentRole === 'ADMIN' ? 'bg-amber-100 text-amber-800' : ($currentRole === 'SEC_MEMBER' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800') ?>">
              <?= $currentRole === 'SEC_MEMBER' ? 'SEC Evaluator' : htmlspecialchars($currentRole) ?>
            </span>

            <div class="text-right hidden lg:block">
              <span class="block text-xs font-semibold text-gray-900 leading-tight"><?= htmlspecialchars($currentUser['name']) ?></span>
              <span class="block text-[11px] text-gray-500 leading-tight truncate max-w-[160px]"><?= htmlspecialchars($currentUser['email']) ?></span>
            </div>

            <a href="/change-password.php" title="Security Settings" class="p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
            </a>

            <a href="/logout.php" title="Sign Out" class="p-2 text-red-500 hover:text-red-700 rounded-lg hover:bg-red-50">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
            </a>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </header>

  <!-- Main Content Wrap -->
  <main class="flex-1 flex flex-col">
