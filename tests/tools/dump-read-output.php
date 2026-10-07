<?php
/**
 * Dump the read-layer output for a fixture, in the shape the snapshot tests
 * compare against. Regenerate a snapshot after an intentional output change:
 *
 *   php tests/tools/dump-read-output.php atomic-only > tests/fixtures/elementor/atomic-only.expected.json
 *
 * Review the diff before committing it.
 */
require __DIR__ . '/../bootstrap.php';
$name     = $argv[1] ?? 'atomic-only';
$elements = iato_test_fixture( $name );
echo json_encode( iato_test_read_snapshot( $elements ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
