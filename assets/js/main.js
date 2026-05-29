// RS Pharmacy - Main JS

// Toast notification
function showToast(message, type = 'success') {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    const icons = { success: 'fa-check-circle', error: 'fa-times-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
    toast.className = `toast ${type}`;
    toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i><span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateX(20px)'; toast.style.transition = 'all 0.3s'; setTimeout(() => toast.remove(), 300); }, 3500);
}

// Modal
function openModal(id) { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

document.querySelectorAll('.modal-backdrop').forEach(modal => {
    modal.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('show'); });
});

document.querySelectorAll('.modal-close').forEach(btn => {
    btn.addEventListener('click', function() { this.closest('.modal-backdrop')?.classList.remove('show'); });
});

// Confirm delete
function confirmDelete(msg, form) {
    if (confirm(msg || 'Are you sure you want to delete this record?')) form.submit();
}

// Table search filter
function filterTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;
    input.addEventListener('keyup', function() {
        const val = this.value.toLowerCase();
        table.querySelectorAll('tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
        });
    });
}

// Currency formatter
function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

// Sidebar toggle (mobile)
function toggleSidebar() {
    document.getElementById('sidebar')?.classList.toggle('open');
}

// Auto dismiss alerts
document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
    setTimeout(() => { alert.style.opacity = '0'; alert.style.transition = 'opacity 0.5s'; setTimeout(() => alert.remove(), 500); }, parseInt(alert.dataset.autoDismiss) || 4000);
});

// Highlight active nav
const current = location.pathname.split('/').pop();
document.querySelectorAll('.nav-item').forEach(item => {
    if (item.getAttribute('href') === current) item.classList.add('active');
});
