<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reflexometr\Auth\AuthService;
use Reflexometr\Config;
use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Repositories\TagRepository;
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

// 2) Seed the tag catalog (CR-TEST-05, CR-TEST-25) — admin-manageable, not hardcoded in the
//    client. 'visual' existed pre-Sprint-11 (was a category); 'dynamic' is new this sprint for
//    the Circle Collision tests (CR-TEST-23/24), which are visual AND involve continuous motion
//    rather than a single discrete stimulus onset.
$tags = new TagRepository($db);
$tagIds = [];
foreach (['visual', 'dynamic'] as $tagName) {
    $existing = $tags->findByName($tagName);
    $tagIds[$tagName] = $existing !== null ? (int) $existing['id'] : $tags->create($tagName);
    echo "Tag '{$tagName}' id={$tagIds[$tagName]}\n";
}

// 3) Seed the concrete r-tests via the same import mechanism the admin API uses (CR-TEST-01) —
//    not a special-cased seeding path. tag_ids threads through ImportService::importNewTest()
//    exactly as the admin API's POST /admin/r-tests does (CR-TEST-25).
$importer = new ImportService();

$seeds = [
    [
        'slug' => 'simple-reaction',
        'name' => 'Simple Visual Reaction Time',
        'description' => 'A shape starts one color and changes to another after a random delay; react as fast as you can.',
        'file' => __DIR__ . '/../database/seeds/simple-reaction.v1.json',
        'tags' => ['visual'],
    ],
    [
        'slug' => 'two-hand-reaction',
        'name' => 'Two-Hand Reaction Time',
        'description' => 'One shared stimulus, two hands: react with both your left and right hand and compare the delta.',
        'file' => __DIR__ . '/../database/seeds/two-hand-reaction.v1.json',
        'tags' => ['visual'],
    ],
    [
        'slug' => 'circle-collision-simple',
        'name' => 'Circle Collision (Simple)',
        'description' => 'Two circles move toward each other at a constant speed; click the instant you think their centers meet.',
        'file' => __DIR__ . '/../database/seeds/circle-collision-simple.v1.json',
        'tags' => ['visual', 'dynamic'],
    ],
    [
        'slug' => 'circle-collision-complex',
        'name' => 'Circle Collision (Complex)',
        'description' => 'Like Circle Collision (Simple), but circle sizes and closing speed vary within each trial — a harder coincidence-timing challenge.',
        'file' => __DIR__ . '/../database/seeds/circle-collision-complex.v1.json',
        'tags' => ['visual', 'dynamic'],
    ],
];

foreach ($seeds as $seed) {
    try {
        $raw = file_get_contents($seed['file']);
        $seedTagIds = array_map(static fn (string $t): int => $tagIds[$t], $seed['tags']);
        $importer->importNewTest($seed['slug'], $seed['name'], $seed['description'], $seedTagIds, $raw);
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
