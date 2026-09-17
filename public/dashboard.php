<?php

declare(strict_types=1);

/*
 * Dashboard (Part D §12, mockup 09_17_52): live counts from the user's JSON indexes,
 * the five most recent generated PDFs (uploaded source PDFs are listed on the Upload page only),
 * quick actions, how it works.
 */

use App\Core\View;
use App\Files\GeneratedFileManager;
use App\Files\GeneratedPresenter;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Profiles\BlueprintManager;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
$storage = new UserStorage($user->id);

$uploads = (new UploadRepository($storage))->all();
$generated = (new GeneratedFileManager($storage))->all();
$profiles = (new BlueprintManager($storage))->count();

$recent = [];
foreach ($generated as $item) {
    $row = GeneratedPresenter::row($item);
    $recent[] = [
        'kind' => 'generated',
        'name' => $row['file_name'],
        'sub' => $row['title'],
        'created_at' => $row['created_at'],
        'created_label' => $row['created_label'],
        'status_label' => $row['missing'] ? 'File missing' : 'Generated',
        'status_tone' => $row['missing'] ? 'red' : 'blue',
        'actions' => $row['missing'] ? [
            ['label' => 'Generated Files', 'href' => url('downloads.php'), 'icon' => 'file-text'],
        ] : [
            ['label' => 'View PDF', 'href' => $row['preview_url'], 'icon' => 'eye', 'new' => true],
            ['label' => 'Download', 'href' => $row['download_url'], 'icon' => 'download'],
            ['label' => 'Generated Files', 'href' => url('downloads.php'), 'icon' => 'file-text'],
        ],
    ];
}
usort($recent, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

View::render('pages/dashboard', [
    'pageTitle' => 'Dashboard',
    'activeNav' => 'dashboard',
    'user' => $user,
    'stats' => [
        'uploads' => count($uploads),
        'profiles' => $profiles,
        'generated' => count($generated),
        'total' => count($uploads) + count($generated),
    ],
    'recent' => array_slice($recent, 0, 5),
    'latestKind' => 'generated',
]);
