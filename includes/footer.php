    </div> <!-- end container -->

    <footer class="footer">
        <div class="footer-content">
            <p style="display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap;">
                <span><?= APP_NAME ?></span>
                <span style="opacity: 0.4;">•</span>
                <span>v<?= APP_VERSION ?></span>
                <span style="opacity: 0.4;">•</span>
                <span>&copy; <?= date('Y') ?></span>
            </p>
        </div>
    </footer>

    <?php if (!empty($__app_shell_open)): ?>
        </div> <!-- end app-main -->
    </div> <!-- end app-shell -->

    <!-- Wadah toast notifikasi -->
    <div class="toast-host" id="toastHost" aria-live="polite"></div>

    <!-- Popup konfirmasi bersama (dipakai appConfirm() di main.js, pengganti confirm() browser) -->
    <div id="confirmModal" class="modal" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmMessage">
        <div class="modal-content modal-confirm">
            <div class="confirm-body">
                <span class="confirm-icon" id="confirmIcon"><i class="fas fa-exclamation-triangle"></i></span>
                <div class="confirm-text">
                    <h2 id="confirmTitle">Konfirmasi</h2>
                    <p id="confirmMessage"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="confirmCancel">Batal</button>
                <button type="button" class="btn btn-danger" id="confirmOk">Ya, lanjutkan</button>
            </div>
        </div>
    </div>

    <!-- Popup generik: isinya dimuat lewat [data-modal-url] (lihat main.js) -->
    <div id="ajaxModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h2 id="ajaxModalTitle">Detail</h2>
                <button type="button" class="modal-close" aria-label="Tutup">&times;</button>
            </div>
            <div id="ajaxModalBody"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $is_admin = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false;
    $base_path = $is_admin ? '../' : '';
    ?>
    <script src="<?= $base_path ?>assets/js/main.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>
</body>
</html>
