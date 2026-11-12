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
    <?php endif; ?>

    <?php
    $is_admin = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false;
    $base_path = $is_admin ? '../' : '';
    ?>
    <script src="<?= $base_path ?>assets/js/main.js"></script>
</body>
</html>
