<?php
/**
 * Admidio-Plugin: Update eigener GitHub-Plugins
 *
 * Lädt ein Repository als ZIP über die GitHub-API, entpackt es in ein
 * Temp-Verzeichnis, übernimmt bestehende Konfigurationsdateien und tauscht
 * den Plugin-Ordner aus. Schlägt ein Schritt fehl, wird der vorherige Stand
 * wiederhergestellt. Nach erfolgreichem Update wird das Backup gelöscht.
 *
 * Konfiguration: config_sample.php nach config.php kopieren und anpassen.
 */

use Admidio\UI\Presenter\PagePresenter;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;

final class GitHubPluginUpdater
{
    /** @var array<int, array{type: string, text: string}> */
    private array $messages = [];

    /**
     * @param string   $owner         GitHub-Benutzer oder -Organisation
     * @param string   $token         Personal Access Token (leer für öffentliche Repos)
     * @param string   $pluginsPath   Absoluter Pfad zum Admidio-Plugin-Verzeichnis
     * @param string   $tempBasePath  Beschreibbares Verzeichnis für Download und Entpacken
     * @param string[] $preserveFiles Dateien (relativ zum Plugin-Ordner), die beim Update erhalten bleiben
     */
    public function __construct(
        private readonly string $owner,
        private readonly string $token,
        private readonly string $pluginsPath,
        private readonly string $tempBasePath,
        private readonly array $preserveFiles = ['config.php'],
    ) {
    }

    /** @return array<int, array{type: string, text: string}> */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function log(string $type, string $text): void
    {
        $this->messages[] = ['type' => $type, 'text' => $text];
    }

    /**
     * Installiert bzw. aktualisiert ein Plugin.
     *
     * @param array{repo: string, folder?: string, ref?: string, preserve?: string[]} $plugin
     * @throws RuntimeException bei jedem Fehler; der vorherige Stand bleibt dann erhalten
     */
    public function install(array $plugin): void
    {
        $repo     = $plugin['repo'];
        $folder   = $plugin['folder'] ?? strtolower($repo);
        $ref      = $plugin['ref'] ?? '';
        $preserve = array_unique(array_merge($this->preserveFiles, $plugin['preserve'] ?? []));

        $this->checkRequirements();

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $target  = $this->pluginsPath . DIRECTORY_SEPARATOR . $folder;
        $backup  = $target . '-old';
        $workDir = $this->tempBasePath . DIRECTORY_SEPARATOR . 'plugin-update-' . bin2hex(random_bytes(4));

        if (!@mkdir($workDir, 0755, true)) {
            throw new RuntimeException('Temp-Verzeichnis konnte nicht angelegt werden: ' . $workDir);
        }

        try {
            $zipFile = $workDir . DIRECTORY_SEPARATOR . 'download.zip';
            $this->download($repo, $ref, $zipFile);

            $source = $this->extract($zipFile, $workDir . DIRECTORY_SEPARATOR . 'extract');

            if (is_dir($target)) {
                $this->preserveFiles($target, $source, $preserve);
            }

            $this->swap($source, $target, $backup);

            // Backup erst löschen, wenn der neue Ordner sicher an seinem Platz ist.
            if (is_dir($backup) && !self::deleteDirectory($backup)) {
                $this->log('warning', 'Backup-Ordner ' . basename($backup) . ' konnte nicht gelöscht werden.');
            }

            $this->log('success', 'Plugin ' . $repo . ' wurde erfolgreich nach ' . $folder . ' installiert.');
        } finally {
            self::deleteDirectory($workDir);
        }
    }

    private function checkRequirements(): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Erweiterung cURL ist nicht verfügbar.');
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Die PHP-Erweiterung zip (ZipArchive) ist nicht verfügbar.');
        }
        if (!is_dir($this->pluginsPath) || !is_writable($this->pluginsPath)) {
            throw new RuntimeException('Plugin-Verzeichnis ist nicht beschreibbar: ' . $this->pluginsPath);
        }
        if (!is_dir($this->tempBasePath) || !is_writable($this->tempBasePath)) {
            throw new RuntimeException('Temp-Verzeichnis ist nicht beschreibbar: ' . $this->tempBasePath);
        }
    }

    /**
     * Lädt das Repository als ZIP über die GitHub-API (Default-Branch oder angegebene Ref).
     * Der API-Endpunkt leitet auf eine vorsignierte URL weiter, daher darf cURL den
     * Authorization-Header beim Host-Wechsel verwerfen.
     */
    private function download(string $repo, string $ref, string $zipFile): void
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/zipball',
            rawurlencode($this->owner),
            rawurlencode($repo)
        );
        if ($ref !== '') {
            $url .= '/' . rawurlencode($ref);
        }
        $this->log('info', 'Download von ' . $url);

        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $fh = @fopen($zipFile, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Download-Datei konnte nicht angelegt werden: ' . $zipFile);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'admidio-plugin-updater',
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $ok       = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        fclose($fh);

        if ($ok === false) {
            throw new RuntimeException('cURL-Fehler: ' . $error);
        }

        if ($httpCode !== 200) {
            $hint = match ($httpCode) {
                401     => 'Token ungültig oder abgelaufen',
                403     => 'Zugriff verweigert oder API-Rate-Limit erreicht',
                404     => 'Repository nicht gefunden (privates Repo ohne Token?)',
                default => '',
            };
            $body   = (string) @file_get_contents($zipFile, false, null, 0, 300);
            $detail = '';
            if ($body !== '') {
                $json = json_decode($body, true);
                if (is_array($json) && isset($json['message'])) {
                    $detail = ' GitHub: ' . $json['message'];
                }
            }
            throw new RuntimeException(
                'HTTP-Status ' . $httpCode . ($hint !== '' ? ' (' . $hint . ')' : '') . '.' . $detail
            );
        }

        if (@file_get_contents($zipFile, false, null, 0, 2) !== 'PK') {
            throw new RuntimeException('Die Antwort ist keine ZIP-Datei.');
        }

        $this->log('info', sprintf('Download abgeschlossen (%.1f KB).', filesize($zipFile) / 1024));
    }

    /**
     * Entpackt das ZIP in $extractDir und liefert den Pfad des einzigen Hauptordners.
     */
    private function extract(string $zipFile, string $extractDir): string
    {
        $zip = new ZipArchive();
        $res = $zip->open($zipFile);
        if ($res !== true) {
            throw new RuntimeException('ZIP konnte nicht geöffnet werden (Code ' . $res . ').');
        }

        // Zip-Slip-Schutz: keine absoluten Pfade, keine ".."-Segmente, keine Laufwerksangaben.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry    = (string) $zip->getNameIndex($i);
            $segments = preg_split('~[\\\\/]+~', $entry) ?: [];
            if ($entry === ''
                || $entry[0] === '/'
                || $entry[0] === '\\'
                || in_array('..', $segments, true)
                || str_contains($entry, ':')
            ) {
                $zip->close();
                throw new RuntimeException('ZIP enthält einen unzulässigen Pfad: ' . $entry);
            }
        }

        if (!@mkdir($extractDir, 0755, true) || !$zip->extractTo($extractDir)) {
            $zip->close();
            throw new RuntimeException('ZIP konnte nicht entpackt werden.');
        }
        $zip->close();

        $entries = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
        if (count($entries) !== 1 || !is_dir($extractDir . DIRECTORY_SEPARATOR . $entries[0])) {
            throw new RuntimeException('Das ZIP enthält nicht genau einen Hauptordner.');
        }

        return $extractDir . DIRECTORY_SEPARATOR . $entries[0];
    }

    /**
     * Kopiert Konfigurationsdateien aus dem installierten Plugin in den neuen Ordner.
     * Eine mitgelieferte Datei gleichen Namens wird überschrieben, die lokale Konfiguration gewinnt.
     *
     * @param string[] $files
     */
    private function preserveFiles(string $oldDir, string $newDir, array $files): void
    {
        foreach ($files as $relative) {
            $relative = trim(str_replace('\\', '/', (string) $relative), '/');
            if ($relative === '' || in_array('..', explode('/', $relative), true)) {
                continue;
            }

            $from = $oldDir . DIRECTORY_SEPARATOR . $relative;
            if (!is_file($from)) {
                continue;
            }

            $to = $newDir . DIRECTORY_SEPARATOR . $relative;
            if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0755, true)) {
                throw new RuntimeException('Zielordner für ' . $relative . ' konnte nicht angelegt werden.');
            }
            if (!@copy($from, $to)) {
                throw new RuntimeException('Konfigurationsdatei konnte nicht übernommen werden: ' . $relative);
            }
            $this->log('info', 'Bestehende Datei übernommen: ' . $relative);
        }
    }

    /**
     * Tauscht den Plugin-Ordner aus. Bei Fehlern wird der alte Ordner zurückgeholt.
     */
    private function swap(string $source, string $target, string $backup): void
    {
        if (is_dir($backup) && !self::deleteDirectory($backup)) {
            throw new RuntimeException('Altes Backup konnte nicht gelöscht werden: ' . basename($backup));
        }

        $hadPrevious = is_dir($target);
        if ($hadPrevious && !@rename($target, $backup)) {
            throw new RuntimeException('Aktueller Plugin-Ordner konnte nicht gesichert werden: ' . basename($target));
        }

        if (self::moveDirectory($source, $target)) {
            return;
        }

        // Rollback
        if (is_dir($target)) {
            self::deleteDirectory($target);
        }
        if ($hadPrevious) {
            if (@rename($backup, $target)) {
                throw new RuntimeException(
                    'Neuer Plugin-Ordner konnte nicht angelegt werden. Der vorherige Stand wurde wiederhergestellt.'
                );
            }
            throw new RuntimeException(
                'Neuer Plugin-Ordner konnte nicht angelegt werden und das Rollback ist fehlgeschlagen. '
                . 'Bitte ' . basename($backup) . ' manuell nach ' . basename($target) . ' umbenennen.'
            );
        }
        throw new RuntimeException('Neuer Plugin-Ordner konnte nicht angelegt werden.');
    }

    /** Verschiebt ein Verzeichnis; fällt bei Dateisystemgrenzen auf Kopieren zurück. */
    private static function moveDirectory(string $from, string $to): bool
    {
        if (@rename($from, $to)) {
            return true;
        }
        if (!self::copyDirectory($from, $to)) {
            return false;
        }
        self::deleteDirectory($from);
        return true;
    }

    private static function copyDirectory(string $from, string $to): bool
    {
        if (!is_dir($to) && !@mkdir($to, 0755, true)) {
            return false;
        }
        foreach (scandir($from) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $src = $from . DIRECTORY_SEPARATOR . $item;
            $dst = $to . DIRECTORY_SEPARATOR . $item;
            $ok  = is_dir($src) ? self::copyDirectory($src, $dst) : @copy($src, $dst);
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    public static function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            $ok   = (is_dir($path) && !is_link($path)) ? self::deleteDirectory($path) : @unlink($path);
            if (!$ok) {
                return false;
            }
        }
        return @rmdir($dir);
    }
}

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $rootPath = dirname(__DIR__, 2);
    require_once($rootPath . '/system/common.php');

    if (!$gValidLogin || !$gCurrentUser->isAdministrator()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    // Konfiguration laden
    $configFile = __DIR__ . '/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('config.php fehlt. Bitte config_sample.php nach config.php kopieren und anpassen.');
    }
    $config = require $configFile;
    if (!is_array($config) || empty($config['github_owner']) || empty($config['plugins']) || !is_array($config['plugins'])) {
        throw new RuntimeException('config.php ist unvollständig: github_owner und plugins müssen gesetzt sein.');
    }
    // Abwärtskompatibel: Token aus adm_my_files/config.php ($githubtoken) verwenden, wenn keins gesetzt ist.
    $token = (string) ($config['github_token'] ?? '');
    if ($token === '' && !empty($GLOBALS['githubtoken'])) {
        $token = (string) $GLOBALS['githubtoken'];
    }

    $pluginsPath  = dirname(__DIR__);
    $tempBasePath = (defined('ADMIDIO_PATH') && defined('FOLDER_DATA') && is_writable(ADMIDIO_PATH . FOLDER_DATA))
        ? ADMIDIO_PATH . FOLDER_DATA
        : sys_get_temp_dir();

    $headline  = 'Update eigene GitHub-Plugins';
    $pluginUrl = ADMIDIO_URL . FOLDER_PLUGINS . '/' . basename(__DIR__) . '/update.php';
    $gNavigation->addStartUrl($pluginUrl, $headline, 'bi-cloud');

    $pagePresenter = PagePresenter::withHtmlIDAndHeadline('adm_plugin_update', $headline);
    $html = '';

    // Update ausführen: nur per POST mit gültigem CSRF-Token
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));

        $key = (string) ($_POST['plugin'] ?? '');
        if (!isset($config['plugins'][$key]) || empty($config['plugins'][$key]['repo'])) {
            throw new RuntimeException('Unbekanntes Plugin.');
        }

        $updater = new GitHubPluginUpdater(
            (string) $config['github_owner'],
            $token,
            $pluginsPath,
            $tempBasePath,
            (array) ($config['preserve_files'] ?? ['config.php'])
        );

        try {
            $updater->install($config['plugins'][$key]);
        } catch (RuntimeException $e) {
            $updater->log('danger', 'Update fehlgeschlagen: ' . $e->getMessage());
        }

        foreach ($updater->getMessages() as $message) {
            $html .= '<div class="alert alert-' . e($message['type']) . '" role="alert">' . e($message['text']) . '</div>';
        }
    }

    // Übersicht mit Update-Buttons
    $csrfToken = $gCurrentSession->getCsrfToken();
    $html .= '<div class="table-responsive"><table class="table table-hover align-middle">'
        . '<thead><tr><th>Plugin</th><th>Repository</th><th>Ordner</th><th>Status</th><th></th></tr></thead><tbody>';

    foreach ($config['plugins'] as $key => $plugin) {
        $repo      = (string) ($plugin['repo'] ?? '');
        $label     = (string) ($plugin['label'] ?? $repo);
        $folder    = (string) ($plugin['folder'] ?? strtolower($repo));
        $repoUrl   = 'https://github.com/' . rawurlencode((string) $config['github_owner']) . '/' . rawurlencode($repo);
        $installed = is_dir($pluginsPath . DIRECTORY_SEPARATOR . $folder);
        $refLabel  = !empty($plugin['ref']) ? ' <span class="text-muted">@' . e((string) $plugin['ref']) . '</span>' : '';
        $status    = $installed
            ? '<span class="badge bg-success">installiert</span>'
            : '<span class="badge bg-secondary">nicht installiert</span>';

        $html .= '<tr>'
            . '<td>' . e($label) . '</td>'
            . '<td><a href="' . e($repoUrl) . '" target="_blank" rel="noopener">'
            . e($config['github_owner'] . '/' . $repo) . '</a>' . $refLabel . '</td>'
            . '<td><code>' . e($folder) . '</code></td>'
            . '<td>' . $status . '</td>'
            . '<td class="text-end">'
            . '<form method="post" action="' . e($pluginUrl) . '" class="d-inline">'
            . '<input type="hidden" name="adm_csrf_token" value="' . e($csrfToken) . '">'
            . '<input type="hidden" name="plugin" value="' . e((string) $key) . '">'
            . '<button type="submit" class="btn btn-sm btn-primary" data-label="' . e($label) . '"'
            . ' onclick="return confirm(this.dataset.label + \' jetzt aktualisieren?\');">'
            . '<i class="bi bi-github"></i> ' . ($installed ? 'Aktualisieren' : 'Installieren')
            . '</button></form></td>'
            . '</tr>';
    }
    $html .= '</tbody></table></div>';

    $pagePresenter->addHtml($html);
    $pagePresenter->show();
} catch (Throwable $e) {
    if (isset($gMessage)) {
        $gMessage->show($e->getMessage());
    } else {
        echo htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
