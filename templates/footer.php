  </main>

<?php if (empty($hideFooter)): ?>
  <!-- Institutional Footer (Hidden in print) -->
  <footer class="no-print bg-white border-t border-gray-200 mt-auto py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-gray-500">
      <div class="flex items-center gap-2">
        <img src="/brand/ideapad-logo.png" alt="IDEAPAD" class="h-6 w-auto opacity-75">
        <span>© <?= date('Y') ?> IDEAPAD — Technology Business Incubator, ITM Gwalior. All rights reserved.</span>
      </div>
      <div class="flex items-center gap-4 text-gray-400">
        <span>Grant Policy Version 2026</span>
        <span>•</span>
        <span>Half-Yearly ITM Gwalior Audit Compliance</span>
      </div>
    </div>
  </footer>
<?php endif; ?>

</body>
</html>
