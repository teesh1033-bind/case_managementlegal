<?php
// inc/footer.php
// Put common script includes and closing tags here. Intended to be included just before </body> in converted pages.
require_once __DIR__ . '/portal-theme-head.php';
?>

<!-- Common scripts (Perfect Scrollbar required before Argon on Windows) -->
<script src="../assets/js/core/bootstrap.bundle.min.js"></script>
<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
<script src="../assets/js/argon-dashboard.min.js"></script>
<?php
require_once __DIR__ . '/legalpro-icons.php';
legalpro_icons_footer_scripts();
?>
</body>
</html>