<?php
// tools/convert_html_to_php.php
// Simple converter: copies each file from pages/*.html to pages/*.php,
// updates internal links (.html -> .php), and injects includes for sidebar and footer.
// Run from project root with: php tools/convert_html_to_php.php

$dir = __DIR__ . "/../pages";
$files = glob($dir . "/*.html");
if (!$files) {
    echo "No HTML files found in pages/\n";
    exit(0);
}

foreach ($files as $html) {
    $php = preg_replace('/\.html$/', '.php', $html);
    $content = file_get_contents($html);
    // Update links: href="something.html" -> href="something.php"
    $content = preg_replace('/href="([^"]+)\.html"/', 'href="$1.php"', $content);
    // Inject includes: replace a marker <!-- SIDEBAR --> with include, else try to find the <aside ...> block and replace it.
    if (strpos($content, '<!-- SIDEBAR -->') !== false) {
        $content = str_replace('<!-- SIDEBAR -->', "<?php include __DIR__ . '/../inc/sidebar.php'; ?>", $content);
    } else {
        // naive replace: find first <aside ...>...</aside> and replace with include
        $content = preg_replace('/<aside[\s\S]*?<\/aside>/', "<?php include __DIR__ . '/../inc/sidebar.php'; ?>", $content, 1);
    }
    // Insert DB include at top if not present
    if (strpos($content, "inc/db.php") === false) {
        $content = preg_replace('/(<\!DOCTYPE[\s\S]*?<body[^>]*>)/i', "$1\n<?php require_once __DIR__ . '/../inc/db.php'; ?>", $content, 1);
    }
    // Append footer include before closing </body>
    if (strpos($content, "inc/footer.php") === false) {
        $content = preg_replace('/<\/body>\s*<\/html>/i', "<?php include __DIR__ . '/../inc/footer.php'; ?>", $content, 1);
    }
    file_put_contents($php, $content);
    echo "Created: " . basename($php) . "\n";
}

echo "Conversion complete. Review generated pages/*.php files.\n";
?>