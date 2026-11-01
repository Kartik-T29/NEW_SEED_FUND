/**
 * Save & Next Multi-Section Stepper and Validation Engine
 */

class FormStepper {
  constructor(options) {
    this.formId = options.formId;
    this.formEl = document.getElementById(this.formId);
    this.sectionClass = options.sectionClass || 'form-section';
    this.navItemClass = options.navItemClass || 'stepper-nav-item';
    this.saveEndpoint = options.saveEndpoint;
    this.onSectionChange = options.onSectionChange || null;
    this.totalSections = document.querySelectorAll(`.${this.sectionClass}`).length;
    this.currentSection = 1;
    this.isDirty = false;

    this.init();
  }

  init() {
    if (!this.formEl) return;

    // Track user input changes for unsaved changes warning
    this.formEl.addEventListener('input', () => {
      this.isDirty = true;
      this.updateCompletionCounters();
    });

    this.formEl.addEventListener('change', () => {
      this.isDirty = true;
      this.updateCompletionCounters();
    });

    window.addEventListener('beforeunload', (e) => {
      if (this.isDirty) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes in your application. Are you sure you want to leave?';
      }
    });

    // Hook stepper navigation links
    document.querySelectorAll(`.${this.navItemClass}`).forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        const targetStep = parseInt(el.getAttribute('data-step') || '1');
        this.goToSection(targetStep);
      });
    });

    this.showSection(this.currentSection);
    this.updateCompletionCounters();
  }

  showSection(stepNumber) {
    this.currentSection = Math.max(1, Math.min(this.totalSections, stepNumber));

    document.querySelectorAll(`.${this.sectionClass}`).forEach((sec, idx) => {
      if (idx + 1 === this.currentSection) {
        sec.classList.remove('hidden');
      } else {
        sec.classList.add('hidden');
      }
    });

    // Update nav stepper items
    document.querySelectorAll(`.${this.navItemClass}`).forEach((nav, idx) => {
      const stepIdx = idx + 1;
      nav.classList.remove('active', 'completed', 'bg-blue-600', 'text-white', 'border-blue-600', 'bg-blue-50', 'text-blue-700');
      if (stepIdx === this.currentSection) {
        nav.classList.add('active', 'bg-blue-600', 'text-white');
      } else if (stepIdx < this.currentSection) {
        nav.classList.add('completed', 'bg-green-50', 'text-green-700', 'border-green-300');
      } else {
        nav.classList.add('bg-gray-50', 'text-gray-600');
      }
    });

    // Scroll form container to top
    const scrollContainer = this.formEl.closest('.overflow-y-auto') || window;
    scrollContainer.scrollTo({ top: 0, behavior: 'smooth' });

    if (typeof this.onSectionChange === 'function') {
      this.onSectionChange(this.currentSection);
    }
  }

  async saveAndNext(buttonEl) {
    const originalText = buttonEl ? buttonEl.innerHTML : '';
    if (buttonEl) {
      buttonEl.disabled = true;
      buttonEl.innerHTML = `<span class="inline-block animate-spin mr-2">⟳</span> Saving...`;
    }

    try {
      if (this.saveEndpoint) {
        const formData = new FormData(this.formEl);
        formData.append('partial_step', this.currentSection.toString());

        const res = await fetch(this.saveEndpoint, {
          method: 'POST',
          body: formData
        });

        const data = await res.json();
        if (!data.success) {
          throw new Error(data.error || 'Failed to save section.');
        }
      }

      this.isDirty = false;
      this.showToast('Section saved successfully.', 'success');

      if (this.currentSection < this.totalSections) {
        this.showSection(this.currentSection + 1);
      }
    } catch (err) {
      this.showToast(err.message || 'Error saving changes. Please check your network.', 'error');
    } finally {
      if (buttonEl) {
        buttonEl.disabled = false;
        buttonEl.innerHTML = originalText;
      }
    }
  }

  prevSection() {
    if (this.currentSection > 1) {
      this.showSection(this.currentSection - 1);
    }
  }

  goToSection(step) {
    this.showSection(step);
  }

  updateCompletionCounters() {
    // Experiment counter: count non-empty textareas inside #step-3
    const expTextareas = document.querySelectorAll('#step-3 textarea');
    if (expTextareas.length > 0) {
      let filled = 0;
      expTextareas.forEach(t => { if (t.value.trim().length > 0) filled++; });
      const badge = document.getElementById('experiment-count-badge');
      if (badge) {
        badge.innerText = `${filled}/${expTextareas.length}`;
        badge.className = filled === expTextareas.length 
          ? 'px-2 py-0.5 text-xs font-bold rounded-full bg-green-100 text-green-800'
          : 'px-2 py-0.5 text-xs font-bold rounded-full bg-yellow-100 text-yellow-800';
      }
    }

    // 12 Questions counter inside Form A
    const questionRadios = document.querySelectorAll('input[type="radio"][name^="q_"]:checked');
    const qBadge = document.getElementById('questions-count-badge');
    if (qBadge) {
      qBadge.innerText = `${questionRadios.length}/12`;
      qBadge.className = questionRadios.length === 12
        ? 'px-2 py-0.5 text-xs font-bold rounded-full bg-green-100 text-green-800'
        : 'px-2 py-0.5 text-xs font-bold rounded-full bg-yellow-100 text-yellow-800';
    }
  }

  showToast(message, type = 'info') {
    let toast = document.getElementById('global-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'global-toast';
      toast.className = 'fixed bottom-5 right-5 z-50 px-4 py-3 rounded-xl shadow-2xl text-sm font-medium flex items-center gap-2 transition-all duration-300';
      document.body.appendChild(toast);
    }

    if (type === 'success') {
      toast.className = 'fixed bottom-5 right-5 z-50 px-4 py-3 rounded-xl shadow-2xl text-sm font-medium flex items-center gap-2 bg-green-600 text-white';
      toast.innerHTML = `<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> ${message}`;
    } else if (type === 'error') {
      toast.className = 'fixed bottom-5 right-5 z-50 px-4 py-3 rounded-xl shadow-2xl text-sm font-medium flex items-center gap-2 bg-red-600 text-white';
      toast.innerHTML = `<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg> ${message}`;
    } else {
      toast.className = 'fixed bottom-5 right-5 z-50 px-4 py-3 rounded-xl shadow-2xl text-sm font-medium flex items-center gap-2 bg-blue-600 text-white';
      toast.innerHTML = message;
    }

    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(10px)';
    }, 3500);
  }
}

window.FormStepper = FormStepper;
