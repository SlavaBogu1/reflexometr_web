<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reflexometr\Auth\AuthService;
use Reflexometr\Config;
use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Repositories\CategoryRepository;
use Reflexometr\Services\ImportService;

Config::load();

$db = Database::connection();

// 1) Seed the single hardcoded admin account (D7), idempotent.
$adminEmail = Config::get('ADMIN_EMAIL', 'admin@reflexometr.local');
$adminPassword = Config::get('ADMIN_SEED_PASSWORD', 'change-me-before-seeding');
$auth = new AuthService();
if ($auth->users()->findByEmail($adminEmail) === null) {
    $auth->register($adminEmail, $adminPassword);
    echo "Seeded admin user: {$adminEmail}\n";
} else {
    echo "Admin user already exists: {$adminEmail}\n";
}

// 2) Seed a "visual" category (CR-TEST-05) — admin-manageable, not hardcoded in the client.
$categories = new CategoryRepository($db);
$visual = $categories->findByName('visual');
$visualId = $visual !== null ? (int) $visual['id'] : $categories->create('visual');
echo "Category 'visual' id={$visualId}\n";

// 3) Seed the two concrete r-tests (CR-TEST-03, CR-TEST-04) via the same import mechanism the
//    admin API uses (CR-TEST-01) — not a special-cased seeding path.
$importer = new ImportService();

$seeds = [
    [
        'slug' => 'simple-reaction',
        'name' => 'Simple Visual Reaction Time',
        'description' => 'A shape starts one color and changes to another after a random delay; react as fast as you can.',
        'file' => __DIR__ . '/../database/seeds/simple-reaction.v1.json',
    ],
    [
        'slug' => 'two-hand-reaction',
        'name' => 'Two-Hand Reaction Time',
        'description' => 'One shared stimulus, two hands: react with both your left and right hand and compare the delta.',
        'file' => __DIR__ . '/../database/seeds/two-hand-reaction.v1.json',
    ],
];

foreach ($seeds as $seed) {
    try {
        $raw = file_get_contents($seed['file']);
        $importer->importNewTest($seed['slug'], $seed['name'], $seed['description'], $visualId, $raw);
        echo "Imported r-test '{$seed['slug']}' v1\n";
    } catch (ApiException $e) {
        if ($e->errorCode() === 'RTEST_SLUG_TAKEN') {
            echo "r-test '{$seed['slug']}' already exists, skipping\n";
            continue;
        }
        throw $e;
    }
}

echo "Seed complete.\n";
