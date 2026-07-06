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

foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    if ($statement === '') {
        continue;
    }
    $db->exec($statement);
}

echo "Migrated ({$driver} driver): {$schemaFile}\n";
