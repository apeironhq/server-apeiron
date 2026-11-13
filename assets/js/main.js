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
    
    // Confirm delete actions (memakai popup konfirmasi bersama)
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-confirm-delete]');
        if (!btn || btn.dataset.confirmed === '1') return;
        e.preventDefault();
        appConfirm({
            title: 'Hapus item?',
            message: 'Item ini akan dihapus permanen dan tidak dapat dikembalikan.',
            confirmText: 'Ya, hapus',
            variant: 'danger'
        }).then(function(ok) {
            if (!ok) return;
            btn.dataset.confirmed = '1';
            btn.click();
            delete btn.dataset.confirmed;
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
                appToast('Mohon lengkapi semua kolom yang wajib diisi.', 'warning');
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

// ---------------------------------------------------------------------------
// Popup konfirmasi bersama — pengganti confirm() bawaan browser
//
//   appConfirm({ title, message, confirmText, variant }) -> Promise<boolean>
//   variant: 'danger' (hapus), 'warning' (suspend/revoke), 'primary' (lainnya)
//
//   Form cukup diberi atribut:
//     <form data-confirm="Pesan" data-confirm-title="Judul"
//           data-confirm-ok="Teks tombol" data-confirm-variant="danger">
// ---------------------------------------------------------------------------
(function() {
    let resolver = null;
    let lastFocus = null;

    function els() {
        return {
            modal: document.getElementById('confirmModal'),
            title: document.getElementById('confirmTitle'),
            message: document.getElementById('confirmMessage'),
            icon: document.getElementById('confirmIcon'),
            ok: document.getElementById('confirmOk'),
            cancel: document.getElementById('confirmCancel')
        };
    }

    function finish(result) {
        const e = els();
        if (e.modal) e.modal.classList.remove('show');
        if (resolver) {
            const r = resolver;
            resolver = null;
            r(result);
        }
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    window.appConfirm = function(opts) {
        opts = opts || {};
        const e = els();
        // Cadangan bila popup tidak ada di halaman (mis. halaman tanpa footer)
        if (!e.modal) return Promise.resolve(window.confirm(opts.message || 'Lanjutkan?'));

        if (resolver) finish(false);
        const variant = opts.variant || 'danger';
        const icons = { danger: 'fa-trash-alt', warning: 'fa-exclamation-triangle', primary: 'fa-question-circle' };

        e.title.textContent = opts.title || 'Konfirmasi';
        e.message.textContent = opts.message || 'Apakah Anda yakin?';
        e.icon.className = 'confirm-icon tone-' + variant;
        e.icon.innerHTML = '<i class="fas ' + (icons[variant] || icons.danger) + '"></i>';
        e.ok.className = 'btn ' + (variant === 'primary' ? 'btn-primary' : variant === 'warning' ? 'btn-warning-solid' : 'btn-danger-solid');
        e.ok.textContent = opts.confirmText || 'Ya, lanjutkan';
        e.cancel.textContent = opts.cancelText || 'Batal';

        lastFocus = document.activeElement;
        e.modal.classList.add('show');
        setTimeout(function() { e.cancel.focus(); }, 30);

        return new Promise(function(resolve) { resolver = resolve; });
    };

    document.addEventListener('DOMContentLoaded', function() {
        const e = els();
        if (!e.modal) return;
        e.ok.addEventListener('click', function() { finish(true); });
        e.cancel.addEventListener('click', function() { finish(false); });
        // Klik di luar kotak atau Escape = batal
        e.modal.addEventListener('click', function(ev) { if (ev.target === e.modal) finish(false); });
        document.addEventListener('keydown', function(ev) {
            if (ev.key === 'Escape' && resolver) {
                // Hanya tutup konfirmasi, jangan ikut menutup popup di bawahnya
                ev.stopPropagation();
                finish(false);
            }
        }, true);
    });

    // Form dengan data-confirm: tahan submit, tampilkan popup, lalu kirim ulang
    // lewat requestSubmit() supaya tombol yang diklik (name/value) ikut terkirim.
    document.addEventListener('submit', function(ev) {
        const form = ev.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
        if (form.dataset.confirmed === '1') {
            delete form.dataset.confirmed;
            return;
        }
        ev.preventDefault();
        const submitter = ev.submitter || null;
        window.appConfirm({
            title: form.getAttribute('data-confirm-title') || 'Konfirmasi',
            message: form.getAttribute('data-confirm'),
            confirmText: form.getAttribute('data-confirm-ok') || 'Ya, lanjutkan',
            variant: form.getAttribute('data-confirm-variant') || 'danger'
        }).then(function(ok) {
            if (!ok) return;
            form.dataset.confirmed = '1';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            } else {
                if (submitter && submitter.name) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = submitter.name;
                    hidden.value = submitter.value;
                    form.appendChild(hidden);
                }
                form.submit();
            }
        });
    }, true);
})();

// Toast sederhana untuk pesan singkat (pengganti alert())
window.appToast = function(message, tone) {
    const host = document.getElementById('toastHost');
    if (!host) return;
    const el = document.createElement('div');
    el.className = 'toast tone-' + (tone || 'warning');
    el.setAttribute('role', 'status');
    const icon = tone === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
    el.innerHTML = '<span class="toast-icon"><i class="fas ' + icon + '"></i></span>' +
        '<span class="toast-body"><span></span></span>' +
        '<button type="button" class="toast-close" aria-label="Tutup">&times;</button>';
    el.querySelector('.toast-body span').textContent = message;
    el.querySelector('.toast-close').addEventListener('click', function() { el.remove(); });
    host.appendChild(el);
    setTimeout(function() { el.classList.add('is-leaving'); setTimeout(function() { el.remove(); }, 300); }, 5000);
};

// ---------------------------------------------------------------------------
// Notifikasi request API yang gagal (lonceng di topbar)
// ---------------------------------------------------------------------------
(function() {
    const SEEN_KEY = 'apeiron_notif_seen_id';
    const POLL_MS = 60000;
    let lastLatest = null; // id terbaru dari pengecekan sebelumnya (null = belum pernah)
    let items = [];

    function readSeen() {
        try { return parseInt(localStorage.getItem(SEEN_KEY) || '0', 10) || 0; } catch (e) { return 0; }
    }
    function writeSeen(id) {
        try { localStorage.setItem(SEEN_KEY, String(id)); } catch (e) {}
    }
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function tone(status) {
        return status >= 500 ? 'danger' : 'warning';
    }

    function render(root, data) {
        const badge = document.getElementById('notifBadge');
        const list = document.getElementById('notifList');
        const sub = document.getElementById('notifSub');
        const seen = readSeen();
        const unseen = items.filter(function(it) { return it.id > seen; }).length;

        if (badge) {
            badge.hidden = unseen === 0;
            badge.textContent = unseen > 9 ? '9+' : String(unseen);
        }
        root.classList.toggle('has-unseen', unseen > 0);

        if (sub) {
            if (data && data.ok === false) {
                sub.textContent = data.error || 'Data belum tersedia';
            } else if (data && data.logging_enabled === false) {
                sub.textContent = 'API Logging nonaktif';
            } else {
                sub.textContent = (data && data.failed_24h ? data.failed_24h : 0) + ' gagal dalam 24 jam';
            }
        }

        if (!list) return;
        if (!items.length) {
            list.innerHTML = '<div class="notif-empty"><i class="fas fa-check-circle"></i><span>Tidak ada request gagal.</span></div>';
            return;
        }
        const base = root.getAttribute('data-requests-url') || 'requests.php';
        list.innerHTML = items.map(function(it) {
            return '<button type="button" class="notif-item' + (it.id > seen ? ' is-new' : '') + '"' +
                ' data-modal-url="' + esc(base + '?detail=' + it.id + '&modal=1') + '"' +
                ' data-modal-title="' + esc('Request #' + it.id) + '">' +
                '<span class="notif-icon tone-' + tone(it.status) + '"><i class="fas fa-exclamation-triangle"></i></span>' +
                '<span class="notif-body">' +
                    '<span class="notif-title">' + esc(it.message) + ' <span class="badge badge-' + tone(it.status) + '">' + esc(it.status) + '</span></span>' +
                    '<span class="notif-text">' + esc(it.explain) + '</span>' +
                    '<span class="notif-meta">' + esc(it.site || '(tanpa website)') + ' · ' + esc(it.endpoint) + (it.ago ? ' · ' + esc(it.ago) : '') + '</span>' +
                '</span>' +
            '</button>';
        }).join('');
    }

    function toast(it, extra) {
        const host = document.getElementById('toastHost');
        if (!host) return;
        const el = document.createElement('div');
        el.className = 'toast tone-' + tone(it.status);
        el.setAttribute('role', 'status');
        el.innerHTML =
            '<span class="toast-icon"><i class="fas fa-exclamation-triangle"></i></span>' +
            '<span class="toast-body">' +
                '<strong>Request gagal' + (extra > 0 ? ' (+' + extra + ' lainnya)' : '') + '</strong>' +
                '<span>' + esc(it.message) + ' — ' + esc(it.site || '(tanpa website)') + '</span>' +
                '<span class="toast-sub">' + esc(it.explain) + '</span>' +
            '</span>' +
            '<button type="button" class="toast-close" aria-label="Tutup">&times;</button>';
        el.querySelector('.toast-close').addEventListener('click', function() { el.remove(); });
        host.appendChild(el);
        setTimeout(function() { el.classList.add('is-leaving'); setTimeout(function() { el.remove(); }, 300); }, 8000);
    }

    function load(root) {
        const url = root.getAttribute('data-url');
        if (!url) return;
        fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                items = Array.isArray(data.items) ? data.items : [];
                const latest = data.latest_id || 0;
                // Toast hanya untuk kegagalan yang masuk saat halaman terbuka
                if (lastLatest !== null && latest > lastLatest) {
                    const fresh = items.filter(function(it) { return it.id > lastLatest; });
                    if (fresh.length) toast(fresh[0], fresh.length - 1);
                }
                lastLatest = latest;
                render(root, data);
            })
            .catch(function() { /* sesi habis atau jaringan putus: abaikan diam-diam */ });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const root = document.getElementById('notif');
        const bell = document.getElementById('notifBell');
        const panel = document.getElementById('notifPanel');
        if (!root || !bell || !panel) return;

        function close() {
            panel.hidden = true;
            bell.setAttribute('aria-expanded', 'false');
        }

        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            const open = panel.hidden;
            panel.hidden = !open;
            bell.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function(e) {
            if (!panel.hidden && !root.contains(e.target)) close();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') close();
        });
        // Klik salah satu notifikasi membuka popup detail; tutup panelnya
        panel.addEventListener('click', function(e) {
            if (e.target.closest('.notif-item')) close();
        });

        const markRead = document.getElementById('notifMarkRead');
        if (markRead) {
            markRead.addEventListener('click', function() {
                if (items.length) writeSeen(items[0].id);
                render(root, null);
                const sub = document.getElementById('notifSub');
                if (sub) sub.textContent = 'Semua sudah dibaca';
            });
        }

        load(root);
        setInterval(function() {
            if (!document.hidden) load(root);
        }, POLL_MS);
    });
})();

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

