'use strict';
document.querySelector('.menu-button')?.addEventListener('click', function () {
  const open = document.querySelector('#sidebar').classList.toggle('open');
  this.setAttribute('aria-expanded', String(open));
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    document.querySelector('#sidebar')?.classList.remove('open');
    document.querySelector('.menu-button')?.setAttribute('aria-expanded', 'false');
  }
});
document.querySelectorAll('.edit-student').forEach(button => {
  button.addEventListener('click', () => {
    const student = JSON.parse(button.dataset.student);
    ['student_id', 'bus_no', 'bus_status'].forEach(field => {
      document.getElementById('edit-' + field).value = student[field] ?? '';
    });
    updateDropPoints(student.drop_point ?? '');
    document.getElementById('edit-name').textContent = student.student_name;
  });
});
document.querySelectorAll('form[method="post"]').forEach(form => {
  form.addEventListener('submit', event => {
    if (form.dataset.submitting) { event.preventDefault(); return; }
    form.dataset.submitting = 'true';
    // Keep submit buttons enabled so the clicked attendance status is submitted.
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => button.setAttribute('aria-busy', 'true'));
  });
});
window.addEventListener('pageshow', () => {
  document.querySelectorAll('form').forEach(form => delete form.dataset.submitting);
  document.querySelectorAll('[aria-busy]').forEach(button => button.removeAttribute('aria-busy'));
});

// Submit filters on selection, and search after a short typing pause.
document.querySelectorAll('form.auto-filter').forEach(form => {
  let timer;
  const submit = () => { clearTimeout(timer); if (form.reportValidity()) { form.requestSubmit(); } };
  form.querySelectorAll('select,input[type="date"]').forEach(input => input.addEventListener('change', submit));
  form.querySelector('[name="q"]')?.addEventListener('input', event => { if (event.isComposing) return; clearTimeout(timer); timer = setTimeout(submit, 500); });
  form.querySelector('[name="q"]')?.addEventListener('compositionend', () => { clearTimeout(timer); timer = setTimeout(submit, 500); });
});

// Repeatable route stops and staff assignments.
document.querySelectorAll('.repeat-list').forEach(list => {
  const add = document.querySelector('[data-list="' + list.id + '"]');
  const refresh = () => {
    const rows = [...list.querySelectorAll('.repeat-row')];
    rows.forEach((row, index) => {
      const label = (list.id === 'drop-point-list' ? 'Drop point ' : 'Staff in charge ') + (index + 1);
      row.querySelector('.row-label').textContent = label;
      row.querySelector('.remove-row').setAttribute('aria-label', 'Remove ' + label.toLowerCase());
    });
    add.disabled = rows.length >= Number(list.dataset.max);
  };
  add.addEventListener('click', () => {
    const rows = list.querySelectorAll('.repeat-row');
    if (rows.length >= Number(list.dataset.max)) return;
    const last = rows[rows.length - 1].querySelector('input,select');
    if (!last.value.trim()) { last.focus(); last.reportValidity(); return; }
    const row = rows[0].cloneNode(true);
    row.querySelector('input,select').value = '';
    list.append(row);refresh();row.querySelector('input,select').focus();
  });
  list.addEventListener('click', event => {
    const remove = event.target.closest('.remove-row');
    if (!remove) return;
    const row = remove.closest('.repeat-row');
    if (list.children.length === 1) row.querySelector('input,select').value = '';
    else row.remove();
    refresh();list.querySelector('input,select').focus();
  });
  refresh();
});

function updateDropPoints(selected = '') {
  const bus = document.getElementById('edit-bus_no');
  const drop = document.getElementById('edit-drop_point');
  if (!bus || !drop) return;
  const routes = JSON.parse(bus.dataset.routes || '{}');
  const points = routes[bus.value] || [];
  drop.replaceChildren(new Option(bus.value ? 'Select a drop point' : 'Select a bus first', ''));
  points.forEach(point => drop.add(new Option(point, point)));
  drop.value = points.includes(selected) ? selected : '';
  document.getElementById('route-help').textContent = points.length ? 'Transport details can be edited in every bus status.' : 'Add drop points for this bus on the Add bus page, then select one here.';
}
document.getElementById('edit-bus_no')?.addEventListener('change', () => updateDropPoints());
// Decorative field icons supplement accessible text labels.
function decorateLabels(root = document) {
  root.querySelectorAll('label').forEach(label => {
    if (label.querySelector('.field-icon')) return;
    const field = label.querySelector('input,select') || document.getElementById(label.htmlFor);
    const name = field?.name || '';
    const icon = /phone/.test(name) ? 'telephone' : /drop/.test(name) ? 'geo-alt' : /bus/.test(name) ? 'bus-front' : /staff|faculty|username|driver_name/.test(name) ? 'person' : /date|year/.test(name) ? 'calendar3' : /dept/.test(name) ? 'buildings' : /password|token/.test(name) ? 'lock' : /status|access/.test(name) ? 'check-circle' : 'search';
    const image = document.createElement('i');image.className = 'bi bi-' + icon + ' field-icon';image.setAttribute('aria-hidden','true');label.prepend(image);
  });
}
decorateLabels();
