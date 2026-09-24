<?php
/**
 * Konfiguration für das Plugin "Update eigene GitHub-Plugins".
 *
 * Diese Datei nach config.php kopieren und anpassen. config.php ist per .gitignore
 * ausgeschlossen und bleibt bei einem Update dieses Plugins erhalten.
 */
return [
    // GitHub-Benutzer oder -Organisation, unter dem die Repositories liegen
    'github_owner' => 'lweruo0',

    // Personal Access Token (Berechtigung: Contents read). Leer lassen für öffentliche Repos.
    // Bleibt das Feld leer, wird als Fallback $githubtoken aus adm_my_files/config.php verwendet.
    'github_token' => '',

    // Dateien relativ zum Plugin-Ordner, die bei jedem Update aus der bestehenden Installation übernommen werden
    'preserve_files' => ['config.php'],

    // Plugins, die aktualisiert werden können. Der Array-Schlüssel ist ein interner Bezeichner.
    //   repo      Name des GitHub-Repositories (Pflicht)
    //   label     Anzeigename in der Übersicht (optional, Standard: repo)
    //   folder    Zielordner unterhalb des Plugin-Verzeichnisses (optional, Standard: repo in Kleinbuchstaben)
    //   ref       Branch, Tag oder Commit (optional, Standard: Default-Branch des Repositories)
    //   preserve  zusätzliche Dateien, die nur für dieses Plugin erhalten bleiben (optional)
    'plugins' => [
        'winner' => [
            'label' => 'Winner Import',
            'repo'  => 'winner-import',
        ],
        'arbeitsdienst' => [
            'label' => 'Arbeitsdienst',
            'repo'  => 'arbeitsdienst',
        ],
        'wpusersync' => [
            'label' => 'WP User Sync (API)',
            'repo'  => 'WpUserSync',
        ],
    ],
];
