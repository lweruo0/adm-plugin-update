<?php
use Admidio\UI\Presenter\PagePresenter;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;


function deleteFolder($folderPath) {
    if (!is_dir($folderPath)) {
        return false;
    }

    $items = scandir($folderPath);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullPath = $folderPath . DIRECTORY_SEPARATOR . $item;

        if (is_dir($fullPath)) {
            deleteFolder($fullPath);
        } else {
            unlink($fullPath);
        }
    }

    return rmdir($folderPath);
}

/* Klasse mit der Installationsfunktion */
class My_GitHubLoader {
	private $token = '';
    public function __construct() {
        global $githubtoken;
        $this->token = $githubtoken;
	}

	/* Seitenaufruf zur GitHub installation */
	public function GitHub_Install($name) {
		echo "Installiere Plugin <br />";
		$zipquellen = array(
			"https://github.com/lweruo0/".$name."/archive/refs/heads/main.zip",
			"https://github.com/lweruo0/".$name."/archive/refs/heads/master.zip"
		);

		$zieldatei = __DIR__.'/../'.$name.'-main.zip';
        if (file_exists ( $zieldatei )) {
		    unlink ( $zieldatei );
        }

		$downloadOk = false;
		$verwendeteQuelle = '';

		foreach ($zipquellen as $zipquelle) {
			$message = 'Download wird gestartet von <a href="' . $zipquelle . '">' . $zipquelle . '</a>.<br />';
        	echo '<div class="alert alert-info" role="alert">' . $message . '</div>';

			$ch = curl_init($zipquelle);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'winner-import-updater');

			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: token ' . $this->token));

			$zipInhalt = curl_exec($ch);
			$error = curl_error($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			if ($error) {
				$message = "cURL Error: " . $error . "<br />";
				echo '<div class="alert alert-danger" role="alert">' . $message . '</div>';
				continue;
			}

			if ($httpCode !== 200) {
				$message = "HTTP Status " . $httpCode . " bei dieser Quelle.<br />";
				echo '<div class="alert alert-danger" role="alert">' . $message . '</div>';
				continue;
			}

			if ($zipInhalt === false || strlen($zipInhalt) < 4) {
				$message = "Leere oder ungültige Antwort.<br />";
				echo '<div class="alert alert-danger" role="alert">' . $message . '</div>';
				continue;
			}

			$zipHeader = substr($zipInhalt, 0, 2);
			if ($zipHeader !== 'PK') {
				$message = "Antwort ist keine ZIP-Datei (kein PK-Header).<br />";
				echo '<div class="alert alert-danger" role="alert">' . $message . '</div>';
				continue;
			}

			file_put_contents($zieldatei, $zipInhalt);
			$downloadOk = true;
			$verwendeteQuelle = $zipquelle;
			break;
		}

		if (!$downloadOk) {
			$message = "Download fehlgeschlagen: Keine gültige ZIP-Datei gefunden.<br />";
			echo '<div class="alert alert-danger" role="alert">' . $message . '</div>';
			return;
		}

		if (! file_exists ( $zieldatei )) {
			$message = "Download fehlgeschlagen.<br />";
			echo '<div class="alert alert-danger" role="alert">' . $message . '</div>';
		} else {
			$message = "Download abgeschlossen.<br />";
			echo '<div class="alert alert-success" role="alert">' . $message . '</div>';
			$message = "Quelle: " . $verwendeteQuelle . "<br />";
			echo '<div class="alert alert-info" role="alert">' . $message . '</div>';
			$message = "Dateigröße: " . filesize($zieldatei) . " bytes<br />";
			echo '<div class="alert alert-info" role="alert">' . $message . '</div>';
		}

		$zip = new ZipArchive ();
		$res = $zip->open ( $zieldatei );



		if ($res === TRUE) {			
			$zip->extractTo ( __DIR__ . "/../" );
			$zip->close ();
			// im ziparchiv ist der Ordner winner-import-main
			$zip_folder = __DIR__ . "/../" . $name . "-main";
			$zip_folder2 = __DIR__ . "/../" . $name . "-master";
			$new_folder = __DIR__ . "/../" . strtolower($name);
			$old_folder = __DIR__ . "/../" . strtolower($name) . "-old";
			// alte sicherung löschen
			deleteFolder($old_folder);
			if (file_exists ( $new_folder )){
				rename ($new_folder, $old_folder);
			}
			if (file_exists ( $zip_folder )){
				rename ($zip_folder, $new_folder);
			}
			if (file_exists ( $zip_folder2 )){
				rename ($zip_folder2, $new_folder);
			}

			$message = "Die Datei wurde erfolgreich nach ". basename($new_folder) ." entpackt.<br />";
			echo '<div class="alert alert-success" role="alert">' . $message . '</div>';
		}

        if (file_exists ( $zieldatei )) {
		    unlink ($zieldatei );
        }
		return;
	}
}

try {
    $rootPath = dirname(__DIR__, 2);
    require_once($rootPath . '/system/common.php');

    if (!$gValidLogin || !$gCurrentUser->isAdministrator()) {
        throw new Exception('SYS_NO_RIGHTS');
    }


    $headline = 'Update eigene Github Plugins';
    $pluginUrl = ADMIDIO_URL . FOLDER_PLUGINS . '/update/update.php';
    $gNavigation->addStartUrl($pluginUrl, $headline, 'bi-cloud');

    $pagePresenter = PagePresenter::withHtmlIDAndHeadline('update', $headline);

    // Top action buttons in the canonical Admidio page-function menu.
    $pagePresenter->addPageFunctionsMenuItem('menu_item_import', 'update winner import', SecurityUtils::encodeUrl($pluginUrl, array('plugin' => 'winner')), 'github');
    $pagePresenter->addPageFunctionsMenuItem('menu_item_newfields', 'Update Arbeitsdienst', SecurityUtils::encodeUrl($pluginUrl, array('plugin' => 'arbeitsdienst')), 'github');
    $pagePresenter->addPageFunctionsMenuItem('menu_item_api', 'Update API', SecurityUtils::encodeUrl($pluginUrl, array('plugin' => 'WpUserSync')), 'github');
    $pagePresenter->addPageFunctionsMenuItem('menu_item_delete', 'Update xxx', SecurityUtils::encodeUrl($pluginUrl, array('plugin' => 'xxx')), 'github');
    $pagePresenter->addPageFunctionsMenuItem('menu_item_test', 'Update yyy', SecurityUtils::encodeUrl($pluginUrl, array('plugin' => 'yyy')), 'github');

    ob_start();

	if (isset($_GET['plugin']) && $_GET['plugin'] === 'winner') {
		$My_GitHubLoader_instance = new My_GitHubLoader ();
		$My_GitHubLoader_instance->GitHub_Install('winner-import');
	}
	if (isset($_GET['plugin']) && $_GET['plugin'] === 'arbeitsdienst') {
		$My_GitHubLoader_instance = new My_GitHubLoader ();
		$My_GitHubLoader_instance->GitHub_Install('arbeitsdienst');
	}
	if (isset($_GET['plugin']) && $_GET['plugin'] === 'WpUserSync') {
		$My_GitHubLoader_instance = new My_GitHubLoader ();
		$My_GitHubLoader_instance->GitHub_Install('WpUserSync');
	}

    $pagePresenter->addHtml(ob_get_clean());
    $pagePresenter->show();

} catch (Throwable $e) {
    echo $e->getMessage();
}
