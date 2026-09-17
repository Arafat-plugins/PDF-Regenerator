<?php

declare(strict_types=1);

/*
 * Stock Templates (Part D §13): every discovered template with thumbnail, name,
 * description and a large preview. Choosing a template happens on the Preview & Edit page.
 */

use App\Core\View;
use App\Data\FieldDictionary;
use App\Templates\PaletteManager;
use App\Templates\TemplateManager;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();

$cards = [];
foreach ((new TemplateManager())->all() as $template) {
    $fields = [];
    foreach ($template->fields as $key) {
        $section = FieldDictionary::SECTIONS[FieldDictionary::section($key)] ?? 'General';
        $fields[$section][] = FieldDictionary::label($key);
    }
    $palettes = [];
    foreach ($template->palettes as $id => $palette) {
        $palettes[] = ['label' => $palette['label'] !== '' ? $palette['label'] : $id, 'color' => $palette['primary'], 'default' => $id === $template->defaultPalette()];
    }
    $cards[] = [
        'template' => $template,
        'fields' => $fields,
        'field_count' => count($template->fields),
        'palettes' => $palettes,
        'made_for' => $template->sourceProfiles === [] ? 'Any PDF' : 'Weekly WordPress maintenance PDFs',
        'theme' => PaletteManager::theme($template, $template->defaultPalette()),
    ];
}

View::render('pages/templates', [
    'pageTitle' => 'Stock Templates',
    'activeNav' => 'templates',
    'user' => $user,
    'cards' => $cards,
    'scripts' => ['templates.js'],
]);
