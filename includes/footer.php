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
