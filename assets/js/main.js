// Main JavaScript for License Server

// Confirm delete
document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide alerts after 5 seconds
    // Hanya notifikasi halaman; peringatan di dalam popup tidak ikut hilang
    const alerts = document.querySelectorAll('.container > .alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });
    
    // Confirm delete actions
    const deleteButtons = document.querySelectorAll('[data-confirm-delete]');
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });
    
    // Copy to clipboard (delegasi: juga bekerja untuk isi popup yang dimuat belakangan)
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-copy]');
        if (!btn) return;
        e.preventDefault();
        const text = btn.getAttribute('data-copy');
        copyText(text).then(() => {
            if (btn.dataset.copyBusy) return;
            btn.dataset.copyBusy = '1';
            const original = btn.innerHTML;
            // Tombol ikon cukup berganti ikon centang agar lebarnya tidak berubah
            btn.innerHTML = btn.classList.contains('btn-icon')
                ? '<i class="fas fa-check"></i>'
                : '<i class="fas fa-check"></i> Copied!';
            setTimeout(() => {
                btn.innerHTML = original;
                delete btn.dataset.copyBusy;
            }, 1500);
        });
    });

    // Tombol Edit di popup detail lisensi saat dibuka di luar halaman Licenses
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-edit-license]');
        if (!btn || typeof window.showEditModal === 'function') return;
        e.preventDefault();
        try {
            const data = JSON.parse(btn.getAttribute('data-edit-license'));
            window.location.href = 'licenses.php?view=' + encodeURIComponent(data.id);
        } catch (err) {
            window.location.href = 'licenses.php';
        }
    });

    // Popup yang isinya dimuat dari URL: <button data-modal-url="..." data-modal-title="...">
    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('[data-modal-url]');
        if (!trigger) return;
        e.preventDefault();
        openAjaxModal(trigger.getAttribute('data-modal-url'), trigger.getAttribute('data-modal-title') || '');
    });
    
    // Tutup popup yang terbuka paling atas dengan tombol Escape
    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        const open = document.querySelectorAll('.modal.show');
        if (open.length) open[open.length - 1].classList.remove('show');
    });

    // Modal handling
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                modal.classList.remove('show');
            });
        }
        
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('show');
            }
        });
    });
    
    // Form validation
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const required = form.querySelectorAll('[required]');
            let valid = true;
            
            required.forEach(input => {
                if (!input.value.trim()) {
                    valid = false;
                    input.style.borderColor = 'var(--err)';
                } else {
                    input.style.borderColor = '';
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Please fill in all required fields');
            }
        });
    });
    
    // Dropdown menu for mobile (click to toggle)
    const navDropdowns = document.querySelectorAll('.nav-dropdown');
    navDropdowns.forEach(dropdown => {
        const link = dropdown.querySelector('.nav-link');
        const content = dropdown.querySelector('.nav-dropdown-content');
        
        if (link && content) {
            // Mobile: click to toggle
            if (window.innerWidth <= 768) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    // Close other dropdowns
                    navDropdowns.forEach(other => {
                        if (other !== dropdown) {
                            other.querySelector('.nav-dropdown-content').style.display = 'none';
                        }
                    });
                    // Toggle current dropdown
                    const isOpen = content.style.display === 'block';
                    content.style.display = isOpen ? 'none' : 'block';
                });
            }
        }
    });
    
    // Close dropdown when clicking outside (mobile)
    if (window.innerWidth <= 768) {
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.nav-dropdown')) {
                document.querySelectorAll('.nav-dropdown-content').forEach(content => {
                    content.style.display = 'none';
                });
            }
        });
    }
});

// Salin teks; fallback untuk browser tanpa Clipboard API
function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        // Jika Clipboard API ditolak (mis. izin), pakai cara lama
        return navigator.clipboard.writeText(text).catch(function() { return copyTextFallback(text); });
    }
    return copyTextFallback(text);
}

function copyTextFallback(text) {
    return new Promise(function(resolve, reject) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            ta.remove();
            if (ok) resolve(); else reject(new Error('copy failed'));
        } catch (err) {
            reject(err);
        }
    });
}

// Buka popup generik (#ajaxModal di footer) dan muat isinya dari URL
function openAjaxModal(url, title) {
    const modal = document.getElementById('ajaxModal');
    const body = document.getElementById('ajaxModalBody');
    const heading = document.getElementById('ajaxModalTitle');
    if (!modal || !body) {
        window.location.href = url;
        return;
    }
    if (heading) heading.textContent = title || 'Detail';
    body.innerHTML = '<div class="modal-loading"><span class="loading"></span><span>Memuat…</span></div>';
    modal.classList.add('show');

    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function(r) {
            return r.text().then(function(html) { return { ok: r.ok, html: html }; });
        })
        .then(function(res) {
            // Hanya terima potongan popup; halaman penuh (mis. sesi habis -> login) ditolak
            if (!res.ok || res.html.indexOf('data-modal-fragment') === -1) {
                body.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span>Gagal memuat data. Muat ulang halaman lalu coba lagi.</span></div>';
                return;
            }
            body.innerHTML = res.html;
        })
        .catch(function() {
            body.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span>Gagal memuat data. Periksa koneksi lalu coba lagi.</span></div>';
        });
}

// Show modal
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
    }
}

// Hide modal
function hideModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
    }
}

