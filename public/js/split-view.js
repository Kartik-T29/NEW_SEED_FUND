/**
 * Resizable Split View & Synchronized Auto-Scrolling for SEC Evaluation
 */

document.addEventListener('DOMContentLoaded', function () {
  const leftPane = document.getElementById('split-left-pane');
  const divider = document.getElementById('split-divider');
  const rightPane = document.getElementById('split-right-pane');
  const container = document.getElementById('split-container');
  const mobileToggleApp = document.getElementById('mobile-toggle-app');
  const mobileToggleForm = document.getElementById('mobile-toggle-form');

  if (!leftPane || !divider || !rightPane || !container) {
    return;
  }

  // Restore saved split width on desktop
  const savedWidth = localStorage.getItem('ideapad_sec_split_width');
  if (savedWidth && window.innerWidth >= 1024) {
    const clamped = Math.max(30, Math.min(70, parseFloat(savedWidth)));
    leftPane.style.width = clamped + '%';
    rightPane.style.width = `calc(100% - ${clamped}% - 8px)`;
  }

  // Draggable divider logic
  let isDragging = false;

  divider.addEventListener('mousedown', function (e) {
    e.preventDefault();
    isDragging = true;
    divider.classList.add('is-dragging');
    document.body.style.cursor = 'col-resize';
  });

  document.addEventListener('mousemove', function (e) {
    if (!isDragging) return;
    const containerRect = container.getBoundingClientRect();
    const newLeftWidthPx = e.clientX - containerRect.left;
    const newWidthPercent = (newLeftWidthPx / containerRect.width) * 100;
    const clamped = Math.max(25, Math.min(75, newWidthPercent));

    leftPane.style.width = clamped + '%';
    rightPane.style.width = `calc(100% - ${clamped}% - 8px)`;
    localStorage.setItem('ideapad_sec_split_width', clamped.toString());
  });

  document.addEventListener('mouseup', function () {
    if (isDragging) {
      isDragging = false;
      divider.classList.remove('is-dragging');
      document.body.style.cursor = '';
    }
  });

  // Mobile View Toggle
  if (mobileToggleApp && mobileToggleForm) {
    mobileToggleApp.addEventListener('click', function () {
      leftPane.classList.remove('hidden');
      leftPane.classList.add('block', 'w-full');
      rightPane.classList.add('hidden');
      mobileToggleApp.classList.add('bg-blue-600', 'text-white');
      mobileToggleApp.classList.remove('bg-gray-100', 'text-gray-700');
      mobileToggleForm.classList.remove('bg-blue-600', 'text-white');
      mobileToggleForm.classList.add('bg-gray-100', 'text-gray-700');
    });

    mobileToggleForm.addEventListener('click', function () {
      rightPane.classList.remove('hidden');
      rightPane.classList.add('block', 'w-full');
      leftPane.classList.add('hidden');
      mobileToggleForm.classList.add('bg-blue-600', 'text-white');
      mobileToggleForm.classList.remove('bg-gray-100', 'text-gray-700');
      mobileToggleApp.classList.remove('bg-blue-600', 'text-white');
      mobileToggleApp.classList.add('bg-gray-100', 'text-gray-700');
    });
  }

  // Synchronized Auto-Scrolling
  // Mapping between Form A right tabs and left dossier section anchors
  const sectionMap = {
    'sop': 'dossier-sop',
    'levels': 'dossier-levels',
    'milestones': 'dossier-milestones',
    'experiment': 'dossier-experiment',
    'grant': 'dossier-grant'
  };

  window.syncLeftPaneToSection = function (tabKey) {
    const targetAnchorId = sectionMap[tabKey];
    if (!targetAnchorId) return;

    const targetEl = document.getElementById(targetAnchorId);
    if (targetEl && leftPane) {
      targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  };
});
