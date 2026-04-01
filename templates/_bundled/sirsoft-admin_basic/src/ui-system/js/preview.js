/**
 * 그누보드7 Admin Template - Design Guide Preview
 * 공통 JavaScript 파일
 */

// ========================================
// Dark Mode Toggle
// ========================================
const darkModeToggle = document.getElementById('darkModeToggle');
const html = document.documentElement;

// Check for saved preference or system preference
if (localStorage.getItem('darkMode') === 'true' ||
    (!localStorage.getItem('darkMode') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
  html.classList.add('dark');
}

if (darkModeToggle) {
  darkModeToggle.addEventListener('click', () => {
    html.classList.toggle('dark');
    localStorage.setItem('darkMode', html.classList.contains('dark'));
  });
}

// ========================================
// Modal Functions
// ========================================
function openModal(modalId) {
  document.getElementById(modalId).classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
  document.getElementById(modalId).classList.add('hidden');
  document.body.style.overflow = '';
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) {
      overlay.classList.add('hidden');
      document.body.style.overflow = '';
    }
  });
});

// Close modal on ESC key
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(modal => {
      modal.classList.add('hidden');
    });
    document.body.style.overflow = '';
  }
});

// ========================================
// Custom Select Functions
// ========================================

// Custom Select 초기화 - 선택된 옵션에 체크 아이콘 추가
document.querySelectorAll('.select-custom').forEach(container => {
  const selectedOption = container.querySelector('.select-option.selected');
  if (selectedOption && !selectedOption.querySelector('.select-option-check')) {
    const checkIcon = document.createElement('i');
    checkIcon.className = 'fas fa-check select-option-check';
    selectedOption.appendChild(checkIcon);
  }
});

// Ghost Select 초기화 - 선택된 옵션에 체크 아이콘 추가
document.querySelectorAll('.select-ghost').forEach(container => {
  const selectedOption = container.querySelector('.select-ghost-option.selected');
  if (selectedOption && !selectedOption.querySelector('.select-ghost-check')) {
    const checkIcon = document.createElement('i');
    checkIcon.className = 'fas fa-check select-ghost-check';
    selectedOption.appendChild(checkIcon);
  }
});

// 부모 컨테이너 overflow 해제
function disableParentOverflow(element) {
  let parent = element.closest('.overflow-x-auto, .table-container, .overflow-hidden, [style*="overflow"]');
  while (parent) {
    const computedOverflow = window.getComputedStyle(parent).overflow;
    if (computedOverflow !== 'visible') {
      parent.dataset.originalOverflow = parent.style.overflow || '';
      parent.dataset.originalOverflowX = parent.style.overflowX || '';
      parent.style.overflow = 'visible';
      parent.style.overflowX = 'visible';
    }
    parent = parent.parentElement?.closest('.overflow-x-auto, .table-container, .overflow-hidden, [style*="overflow"]');
  }
}

// 부모 컨테이너 overflow 복원
function restoreParentOverflow(element) {
  let parent = element.closest('.overflow-x-auto, .table-container, .overflow-hidden, [style*="overflow"]');
  while (parent) {
    if (parent.dataset.originalOverflow !== undefined) {
      parent.style.overflow = parent.dataset.originalOverflow;
      delete parent.dataset.originalOverflow;
    }
    if (parent.dataset.originalOverflowX !== undefined) {
      parent.style.overflowX = parent.dataset.originalOverflowX;
      delete parent.dataset.originalOverflowX;
    }
    parent = parent.parentElement?.closest('.overflow-x-auto, .table-container, .overflow-hidden, [style*="overflow"]');
  }
}

// 모든 드롭다운의 부모 overflow 복원
function restoreAllParentOverflow() {
  document.querySelectorAll('.select-dropdown').forEach(d => {
    restoreParentOverflow(d);
  });
}

function toggleSelect(trigger) {
  const container = trigger.closest('.select-custom');
  const dropdown = container.querySelector('.select-dropdown');
  const icon = trigger.querySelector('.select-trigger-icon');

  // Close all other dropdowns
  document.querySelectorAll('.select-dropdown').forEach(d => {
    if (d !== dropdown) {
      d.classList.add('hidden');
      d.closest('.select-custom').querySelector('.select-trigger-icon').classList.remove('open');
      // 부모 컨테이너 overflow 복원
      restoreParentOverflow(d);
    }
  });

  // Toggle current dropdown
  dropdown.classList.toggle('hidden');
  icon.classList.toggle('open');

  // 드롭다운 열릴 때 부모 컨테이너 overflow 해제
  if (!dropdown.classList.contains('hidden')) {
    disableParentOverflow(dropdown);
  } else {
    restoreParentOverflow(dropdown);
  }
}

function selectOption(option, value) {
  const container = option.closest('.select-custom');
  const trigger = container.querySelector('.select-trigger');
  const dropdown = container.querySelector('.select-dropdown');
  const icon = trigger.querySelector('.select-trigger-icon');

  // Update selected option
  container.querySelectorAll('.select-option').forEach(opt => {
    opt.classList.remove('selected');
    // Remove check icon from other options
    const checkIcon = opt.querySelector('.select-option-check');
    if (checkIcon) checkIcon.remove();
  });
  option.classList.add('selected');

  // Add check icon to selected option
  if (!option.querySelector('.select-option-check')) {
    const checkIcon = document.createElement('i');
    checkIcon.className = 'fas fa-check select-option-check';
    option.appendChild(checkIcon);
  }

  // Update trigger text
  trigger.querySelector('span').textContent = value;

  // Close dropdown
  dropdown.classList.add('hidden');
  icon.classList.remove('open');

  // 부모 컨테이너 overflow 복원
  restoreParentOverflow(dropdown);
}

// Close dropdown when clicking outside
document.addEventListener('click', (e) => {
  // Close custom select
  if (!e.target.closest('.select-custom')) {
    document.querySelectorAll('.select-dropdown').forEach(d => {
      d.classList.add('hidden');
      restoreParentOverflow(d);
    });
    document.querySelectorAll('.select-trigger-icon').forEach(icon => {
      icon.classList.remove('open');
    });
  }

  // Close multi select
  if (!e.target.closest('.multi-select')) {
    document.querySelectorAll('.multi-select-dropdown').forEach(d => {
      d.classList.add('hidden');
    });
  }

  // Close ghost select
  if (!e.target.closest('.select-ghost')) {
    document.querySelectorAll('.select-ghost-dropdown').forEach(d => {
      d.classList.add('hidden');
    });
  }
});

// ========================================
// Multi Select Functions
// ========================================
function toggleMultiSelect(trigger) {
  const container = trigger.closest('.multi-select');
  const dropdown = container.querySelector('.multi-select-dropdown');

  // Close all other multi select dropdowns
  document.querySelectorAll('.multi-select-dropdown').forEach(d => {
    if (d !== dropdown) {
      d.classList.add('hidden');
    }
  });

  // Toggle current dropdown
  dropdown.classList.toggle('hidden');
}

// ========================================
// Ghost Select Functions
// ========================================
function toggleGhostSelect(trigger) {
  const container = trigger.closest('.select-ghost');
  const dropdown = container.querySelector('.select-ghost-dropdown');
  const icon = trigger.querySelector('.select-trigger-icon');

  // Close all other ghost select dropdowns
  document.querySelectorAll('.select-ghost-dropdown').forEach(d => {
    if (d !== dropdown) {
      d.classList.add('hidden');
      d.closest('.select-ghost').querySelector('.select-trigger-icon')?.classList.remove('open');
    }
  });

  // Toggle current dropdown
  dropdown.classList.toggle('hidden');
  icon?.classList.toggle('open');
}

function selectGhostOption(option, value) {
  const container = option.closest('.select-ghost');
  const trigger = container.querySelector('.select-ghost-trigger');
  const dropdown = container.querySelector('.select-ghost-dropdown');
  const icon = trigger.querySelector('.select-trigger-icon');

  // Update selected option
  container.querySelectorAll('.select-ghost-option').forEach(opt => {
    opt.classList.remove('selected');
    // Remove check icon from other options
    const checkIcon = opt.querySelector('.select-ghost-check');
    if (checkIcon) checkIcon.remove();
  });
  option.classList.add('selected');

  // Add check icon to selected option
  if (!option.querySelector('.select-ghost-check')) {
    const checkIcon = document.createElement('i');
    checkIcon.className = 'fas fa-check select-ghost-check';
    option.appendChild(checkIcon);
  }

  // Update trigger text
  trigger.querySelector('span').textContent = value;

  // Close dropdown
  dropdown.classList.add('hidden');
  icon?.classList.remove('open');
}

// ========================================
// Searchable Dropdown Functions
// ========================================
function toggleSearchableDropdown(trigger) {
  const container = trigger.closest('.searchable-dropdown');
  const dropdown = container.querySelector('.searchable-dropdown-dropdown');
  const icon = trigger.querySelector('.searchable-dropdown-trigger-icon');
  const searchInput = dropdown.querySelector('.searchable-dropdown-search-input');

  // Close all other searchable dropdowns
  document.querySelectorAll('.searchable-dropdown-dropdown').forEach(d => {
    if (d !== dropdown) {
      d.classList.add('hidden');
      d.closest('.searchable-dropdown').querySelector('.searchable-dropdown-trigger-icon')?.classList.remove('open');
    }
  });

  // Toggle current dropdown
  dropdown.classList.toggle('hidden');
  icon?.classList.toggle('open');

  // Focus search input when opened
  if (!dropdown.classList.contains('hidden')) {
    setTimeout(() => searchInput?.focus(), 50);
  } else {
    // Clear search when closed
    if (searchInput) {
      searchInput.value = '';
      filterSearchableDropdown(searchInput);
    }
  }
}

function filterSearchableDropdown(input) {
  const container = input.closest('.searchable-dropdown');
  const items = container.querySelector('.searchable-dropdown-items');
  const message = container.querySelector('.searchable-dropdown-message');
  const options = items.querySelectorAll('.searchable-dropdown-option');
  const searchTerm = input.value.toLowerCase().trim();

  let visibleCount = 0;

  options.forEach(option => {
    const label = option.querySelector('.searchable-dropdown-option-label')?.textContent.toLowerCase() || '';
    const description = option.querySelector('.searchable-dropdown-option-description')?.textContent.toLowerCase() || '';

    if (label.includes(searchTerm) || description.includes(searchTerm)) {
      option.style.display = '';
      visibleCount++;
    } else {
      option.style.display = 'none';
    }
  });

  // Show/hide no results message
  if (visibleCount === 0 && searchTerm.length > 0) {
    message.classList.remove('hidden');
  } else {
    message.classList.add('hidden');
  }
}

function selectSearchableOption(option, value) {
  const container = option.closest('.searchable-dropdown');
  const trigger = container.querySelector('.searchable-dropdown-trigger');
  const dropdown = container.querySelector('.searchable-dropdown-dropdown');
  const icon = trigger.querySelector('.searchable-dropdown-trigger-icon');
  const searchInput = dropdown.querySelector('.searchable-dropdown-search-input');

  // Update selected option
  container.querySelectorAll('.searchable-dropdown-option').forEach(opt => {
    opt.classList.remove('selected');
    // Remove check icon from other options
    const checkArea = opt.querySelector('.searchable-dropdown-check-area');
    if (checkArea) checkArea.innerHTML = '';
  });
  option.classList.add('selected');

  // Add check icon to selected option
  const checkArea = option.querySelector('.searchable-dropdown-check-area');
  if (checkArea && !checkArea.querySelector('.searchable-dropdown-check')) {
    checkArea.innerHTML = `
      <svg class="searchable-dropdown-check" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
      </svg>
    `;
  }

  // Update trigger text
  trigger.querySelector('span').textContent = value;

  // Close dropdown
  dropdown.classList.add('hidden');
  icon?.classList.remove('open');

  // Clear search
  if (searchInput) {
    searchInput.value = '';
    filterSearchableDropdown(searchInput);
  }
}

function toggleSearchableOption(option) {
  const container = option.closest('.searchable-dropdown');
  const trigger = container.querySelector('.searchable-dropdown-trigger');
  const checkArea = option.querySelector('.searchable-dropdown-check-area');
  const isSelected = option.classList.contains('selected');

  if (isSelected) {
    // Deselect
    option.classList.remove('selected');
    if (checkArea) checkArea.innerHTML = '';
  } else {
    // Select
    option.classList.add('selected');
    if (checkArea && !checkArea.querySelector('.searchable-dropdown-check')) {
      checkArea.innerHTML = `
        <svg class="searchable-dropdown-check" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
      `;
    }
  }

  // Update trigger text with count
  const selectedCount = container.querySelectorAll('.searchable-dropdown-option.selected').length;
  const triggerSpan = trigger.querySelector('span');
  const baseText = triggerSpan.textContent.replace(/\s*\(\d+\)$/, '');
  triggerSpan.textContent = selectedCount > 0 ? `${baseText} (${selectedCount})` : baseText;
}

// Close searchable dropdown when clicking outside
document.addEventListener('click', (e) => {
  if (!e.target.closest('.searchable-dropdown')) {
    document.querySelectorAll('.searchable-dropdown-dropdown').forEach(d => {
      d.classList.add('hidden');
      d.closest('.searchable-dropdown').querySelector('.searchable-dropdown-trigger-icon')?.classList.remove('open');
      // Clear search
      const searchInput = d.querySelector('.searchable-dropdown-search-input');
      if (searchInput) {
        searchInput.value = '';
        filterSearchableDropdown(searchInput);
      }
    });
  }
});

// ========================================
// Tag Select Functions
// ========================================
function removeTagSelectItem(removeBtn) {
  const tag = removeBtn.closest('.tag');
  const container = removeBtn.closest('.tag-select');

  if (container.classList.contains('disabled')) {
    return;
  }

  // Remove the tag
  tag.remove();

  // Check if container is now empty
  const remainingTags = container.querySelectorAll('.tag');
  if (remainingTags.length === 0) {
    // Add placeholder if no tags left
    const placeholder = document.createElement('span');
    placeholder.className = 'tag-select-placeholder';
    placeholder.textContent = 'No items selected';
    container.appendChild(placeholder);
  }
}

// ========================================
// Accordion Functions
// ========================================
function toggleAccordion(header) {
  const card = header.closest('.card-accordion');
  const body = card.querySelector('.card-accordion-body');
  const icon = header.querySelector('.card-accordion-icon');

  body.classList.toggle('hidden');
  icon.classList.toggle('open');
}
