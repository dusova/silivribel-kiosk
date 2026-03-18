function showTab(name, btn) {
    document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('visible'));
    document.getElementById('tab-' + name).classList.add('visible');
    document.querySelectorAll('.sb-link').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
}

document.querySelectorAll('.sb-link[data-tab]').forEach(btn => {
    btn.addEventListener('click', () => showTab(btn.dataset.tab, btn));
});

const fIconInput = document.getElementById('fIcon');
if (fIconInput) {
    fIconInput.addEventListener('input', () => {
        document.getElementById('icon-preview').className = fIconInput.value || 'bi bi-globe';
    });
}

const btnResetForm = document.getElementById('btn-reset-form');
if (btnResetForm) {
    btnResetForm.addEventListener('click', resetForm);
}

const settingsBgInput = document.getElementById('settingsBgUrl');
if (settingsBgInput) {
    settingsBgInput.addEventListener('input', () => updateSettingsBgPreview(settingsBgInput.value));
}

function fillEdit(item) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('editId').value = item.id;
    document.getElementById('fTitle').value = item.title;
    document.getElementById('fUrl').value = item.url || '';
    document.getElementById('fIcon').value = item.icon_class || 'bi bi-globe';
    document.getElementById('icon-preview').className = item.icon_class || 'bi bi-globe';
    document.getElementById('fOrder').value = item.sort_order;
    document.getElementById('fActive').checked = item.is_active == 1;
    document.getElementById('form-title').textContent = 'Servis Düzenle';
    document.getElementById('btnLabel').textContent = 'Güncelle';
    showTab('add', document.querySelectorAll('.sb-link')[1]);
}

function resetForm() {
    document.getElementById('service-form').reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('editId').value = '';
    document.getElementById('form-title').textContent = 'Yeni Servis Ekle';
    document.getElementById('btnLabel').textContent = 'Kaydet';
    document.getElementById('icon-preview').className = 'bi bi-globe';
    showTab('list', document.querySelectorAll('.sb-link')[0]);
}

const sortable = Sortable.create(document.getElementById('sortable-body'), {
    handle: '.drag-handle',
    animation: 150,
    ghostClass: 'sortable-ghost',
    onEnd: function () {
        const rows = document.querySelectorAll('#sortable-body tr[data-id]');
        const order = Array.from(rows).map((r, i) => ({ id: parseInt(r.dataset.id), order: i + 1 }));
        fetch('admin.php?action=sort', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CSRF_TOKEN
            },
            body: JSON.stringify(order),
        }).then(r => r.json()).then(d => {
            if (d.success) {
                rows.forEach((r, i) => {
                    const badge = r.querySelector('.badge.bg-secondary');
                    if (badge) badge.textContent = i + 1;
                });
            }
        });
    }
});

function updateSettingsBgPreview(url) {
    const img = document.getElementById('settings-bg-preview');
    const ph = document.getElementById('settings-bg-placeholder');
    if (!url) { if (img) img.style.display = 'none'; if (ph) ph.style.display = 'flex'; return; }
    if (img) { img.src = url; img.style.display = 'block'; }
    if (ph) ph.style.display = 'none';
}
