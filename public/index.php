<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Constants;
use App\Config\Auth;

$pageTitle = "IDEAPAD Seed Fund Portal | ITM University Gwalior";
require_once __DIR__ . '/../templates/header.php';
?>

<div class="py-12 bg-linear-to-b from-blue-50/50 to-white">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-16">
    
    <!-- Hero Section -->
    <div class="text-center max-w-3xl mx-auto space-y-5">
      <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-100 text-blue-800 text-xs font-bold tracking-wide uppercase">
        ITM University Innovation Fund
      </div>
      <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 tracking-tight leading-tight">
        Seed Grant Support for Student & Faculty Startups
      </h1>
      <p class="text-base sm:text-lg text-gray-600 leading-relaxed">
        IDEAPAD (Technology Business Incubator of ITM University) provides structured non-dilutive seed funding to accelerate innovative ideas from concept to proof-of-concept (TRL 3) and investment readiness.
      </p>
      
      <div class="pt-4 flex flex-wrap items-center justify-center gap-4">
        <?php if (!Auth::check()): ?>
          <a href="/register.php" class="px-6 py-3.5 rounded-xl bg-blue-600 text-white font-bold text-sm shadow-md hover:bg-blue-700 hover:shadow-lg transition-all">
            Apply for Seed Grant →
          </a>
          <a href="/login.php" class="px-6 py-3.5 rounded-xl bg-white border border-gray-300 text-gray-700 font-bold text-sm shadow-xs hover:bg-gray-50 transition-all">
            Sign In to Account
          </a>
        <?php elseif (Auth::role() === 'STARTUP'): ?>
          <a href="/dashboard.php" class="px-6 py-3.5 rounded-xl bg-blue-600 text-white font-bold text-sm shadow-md hover:bg-blue-700 hover:shadow-lg transition-all">
            Go to Application Form →
          </a>
          <a href="/status.php" class="px-6 py-3.5 rounded-xl bg-white border border-gray-300 text-gray-700 font-bold text-sm shadow-xs hover:bg-gray-50 transition-all">
            Track Application Status
          </a>
        <?php elseif (Auth::role() === 'SEC_MEMBER'): ?>
          <a href="/sec.php" class="px-6 py-3.5 rounded-xl bg-purple-600 text-white font-bold text-sm shadow-md hover:bg-purple-700 hover:shadow-lg transition-all">
            Open SEC Evaluation Panel →
          </a>
        <?php elseif (Auth::role() === 'ADMIN'): ?>
          <a href="/admin.php" class="px-6 py-3.5 rounded-xl bg-blue-600 text-white font-bold text-sm shadow-md hover:bg-blue-700 hover:shadow-lg transition-all">
            Open Administration Console →
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- P1 & P2: Grant Tiers Cards -->
    <div class="space-y-6">
      <div class="text-center">
        <h2 class="text-2xl font-bold text-gray-900 tracking-tight">Structured Grant Tiers</h2>
        <p class="text-xs text-gray-500 mt-1">Evaluated by the Startup Evaluation Committee (SEC) against TRL & MRL readiness</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <?php foreach (Constants::GRANT_TIERS as $key => $tier): if ($key === 'EMERGENCY') continue; ?>
          <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200 hover:border-blue-300 hover:shadow-md transition-all flex flex-col justify-between space-y-4">
            <div>
              <div class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-1"><?= htmlspecialchars($tier['target_trl']) ?></div>
              <h3 class="text-lg font-bold text-gray-900"><?= htmlspecialchars($tier['name']) ?></h3>
              <div class="text-2xl font-extrabold text-gray-900 my-2">
                ₹<?= number_format($tier['min']) ?> <span class="text-xs font-normal text-gray-500">to</span> ₹<?= number_format($tier['max']) ?>
              </div>
              <p class="text-xs text-gray-600 leading-relaxed"><?= htmlspecialchars($tier['purpose']) ?></p>
            </div>
            
            <div class="pt-4 border-t border-gray-100 text-[11px] text-gray-500">
              <span class="font-bold text-gray-700">Target MRL:</span> <?= htmlspecialchars($tier['target_mrl']) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- P7. Annexure 1: Major Grant Headers -->
    <div class="bg-white rounded-3xl p-8 sm:p-10 shadow-sm border border-gray-200 space-y-8">
      <div>
        <h2 class="text-2xl font-bold text-gray-900 tracking-tight">Annexure 1: Major Grant Headers</h2>
        <p class="text-xs text-gray-500 mt-1">Itemized funding categories and procurement compliance rules</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php foreach (Constants::BUDGET_HEADERS as $hKey => $head): ?>
          <div class="p-5 rounded-2xl bg-gray-50 border border-gray-200/80 space-y-2">
            <div class="flex items-center justify-between">
              <h4 class="text-sm font-bold text-gray-900"><?= htmlspecialchars($head['name']) ?></h4>
              <?php if (!empty($head['requires_quotation'])): ?>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">Quotation Req.</span>
              <?php endif; ?>
            </div>
            <p class="text-xs text-gray-600"><?= htmlspecialchars($head['description']) ?></p>
            <p class="text-[11px] text-gray-400 italic">e.g. <?= htmlspecialchars($head['examples']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Governance & Compliance Notice (P10 & P11) -->
    <div class="rounded-2xl p-6 bg-blue-50 border border-blue-200 text-xs text-blue-900 space-y-2">
      <h4 class="font-bold text-sm text-blue-950 flex items-center gap-2">
        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
        Governance & Disbursement Protocols (Policy P10 & P11)
      </h4>
      <p class="leading-relaxed">
        Sanctioned seed funds are disbursed directly to verified vendors or issued via institutional managed cards for approved consumable prototyping. All capital equipment and prototypes remain property of ITM University. Maximum of 3 grants per team unless waived by the SEC. Half-yearly audit reports are published by IDEAPAD.
      </p>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
