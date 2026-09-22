<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reflexometr\Config;
use Reflexometr\Database;

Config::load();

$driver = Config::get('DB_DRIVER', 'sqlite');
$schemaFile = dirname(__DIR__) . '/database/schema.' . ($driver === 'mysql' ? 'mysql' : 'sqlite') . '.sql';

if (!is_file($schemaFile)) {
    fwrite(STDERR, "Schema file not found: {$schemaFile}\n");
    exit(1);
}

$sql = file_get_contents($schemaFile);
$db = Database::connection();

// HF-02 follow-up: strip `-- ...` line comments before splitting on `;` — a bare explode() would
// break mid-statement whenever a comment happens to contain a literal semicolon (English prose
// often does), producing a garbage fragment PDO then rejects with a confusing syntax error. Only
// removes `--` comment lines, never touches semicolons inside an actual SQL statement.
$withoutComments = preg_replace('/^\s*--.*$/m', '', $sql);

foreach (array_filter(array_map('trim', explode(';', $withoutComments))) as $statement) {
    if ($statement === '') {
        continue;
    }
    // query()->closeCursor(), not exec() — MySQL's PREPARE/EXECUTE/DEALLOCATE PREPARE sequence
    // (used by this schema file's idempotent ADD-COLUMN-IF-MISSING guards) behaves like a
    // multi-result-set call under real (non-emulated) prepared statements; exec() alone leaves a
    // result set open and the next exec() fails with "Cannot execute queries while other
    // unbuffered queries are active." query() + closeCursor() fully drains each statement first.
    $db->query($statement)->closeCursor();
}

echo "Migrated ({$driver} driver): {$schemaFile}\n";
