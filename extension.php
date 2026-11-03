<?php

class InactiveCleanerExtension extends Minz_Extension {

    public const THRESHOLDS = array(7, 30, 90, 180, 365, 730);

    public function init() {
        // Autonome : aucun fichier externe requis
    }

    public static function t(string $key, ...$params): string {
        $lang = class_exists('FreshRSS_Context') && FreshRSS_Context::hasUserConf() 
            ? FreshRSS_Context::userConf()->language 
            : (class_exists('Minz_Translate') ? Minz_Translate::language() : 'fr');
        $isFr = (strpos($lang, 'fr') === 0);

        $dict = array(
            'fr' => array(
                'title' => 'Nettoyage des flux inactifs',
                'desc' => 'Identifiez, sauvegardez et purgez les flux RSS inactifs qui n\'ont rien publié depuis une période donnée.',
                'warning_title' => 'Important :',
                'warning_desc' => 'La suppression est définitive. Pensez à télécharger le fichier .opml de sauvegarde avant de lancer la purge.',
                'period_label' => 'Seuil d\'inactivité :',
                'btn_export' => '📥 Sauvegarder les flux ciblés (.opml)',
                'btn_clean' => '🗑️ Supprimer les flux ciblés',
                'confirm_delete' => 'Attention : tous les flux correspondant à la période choisie seront définitivement supprimés. Confirmer ?',
                'period_7' => 'Semaine dernière (7 jours)',
                'period_30' => '1 mois (30 jours)',
                'period_90' => '3 mois (90 jours)',
                'period_180' => '6 mois (180 jours)',
                'period_365' => '1 an (365 jours)',
                'period_730' => '+1 an sans mise à jour (2 ans)',
                'feeds_count' => '%d flux',
                'no_feeds' => '0 flux',
                'msg_cleaned' => 'Nettoyage terminé : %d flux inactif(s) (%s) supprimé(s) avec succès.',
                'msg_no_feeds' => 'Aucun flux inactif correspondant au seuil sélectionné (%s) n\'a été trouvé.',
                'log_info' => 'Le détail des opérations est consigné dans Administration > Journaux ainsi que dans les logs Apache.',
                'backup_title' => 'Sauvegarde flux inactifs (%d jours)',
            ),
            'en' => array(
                'title' => 'Inactive Feeds Cleaner',
                'desc' => 'Identify, back up and purge inactive RSS feeds that haven\'t published any articles for a given duration.',
                'warning_title' => 'Warning:',
                'warning_desc' => 'Deletion is permanent. We strongly recommend downloading the .opml backup file before deleting.',
                'period_label' => 'Inactivity threshold:',
                'btn_export' => '📥 Export targeted feeds (.opml)',
                'btn_clean' => '🗑️ Delete targeted feeds',
                'confirm_delete' => 'Warning: all feeds matching the chosen inactivity period will be permanently deleted. Continue?',
                'period_7' => 'Last week (7 days)',
                'period_30' => '1 month (30 days)',
                'period_90' => '3 months (90 days)',
                'period_180' => '6 months (180 days)',
                'period_365' => '1 year (365 days)',
                'period_730' => '+1 year without updates (2 years)',
                'feeds_count' => '%d feed(s)',
                'no_feeds' => '0 feed',
                'msg_cleaned' => 'Clean-up completed: %d inactive feed(s) (%s) successfully removed.',
                'msg_no_feeds' => 'No inactive feed found for the selected period (%s).',
                'log_info' => 'Details are recorded in Administration > Logs and Apache error logs.',
                'backup_title' => 'Backup of inactive feeds (%d days)',
            )
        );

        $table = $isFr ? $dict['fr'] : $dict['en'];
        $text = isset($table[$key]) ? $table[$key] : $key;

        return empty($params) ? $text : vsprintf($text, $params);
    }

    private function logInfo(string $message): void {
        if (class_exists('Minz_Log') && method_exists('Minz_Log', 'notice')) {
            Minz_Log::notice('[InactiveCleaner] ' . $message);
        }
        error_log('[InactiveCleaner] ' . $message);
    }

    private function logError(string $message): void {
        if (class_exists('Minz_Log') && method_exists('Minz_Log', 'error')) {
            Minz_Log::error('[InactiveCleaner] ' . $message);
        }
        error_log('[InactiveCleaner][ERREUR] ' . $message);
    }

    public static function getThresholdCounts(): array {
        $feedDAO = FreshRSS_Factory::createFeedDao();
        $entryDAO = FreshRSS_Factory::createEntryDao();

        $feeds = $feedDAO->listFeeds();
        $now = time();

        $counts = array_fill_keys(self::THRESHOLDS, 0);
        $limits = array();
        foreach (self::THRESHOLDS as $days) {
            $limits[$days] = $now - ($days * 86400);
        }

        foreach ($feeds as $feed) {
            $latest = null;
            $entries = $entryDAO->listWhere('f', (int)$feed->id(), sort: 'date', order: 'DESC');
            foreach ($entries as $e) {
                $latest = $e;
                break;
            }

            if ($latest !== null) {
                $raw = $latest->date(true);
                $ts = is_numeric($raw) ? (int)$raw : strtotime((string)$latest->date());
                if ($ts > 0) {
                    foreach ($limits as $days => $limitTs) {
                        if ($ts < $limitTs) {
                            $counts[$days]++;
                        }
                    }
                }
            }
        }

        return $counts;
    }

    public function handleConfigureAction() {
        if (Minz_Request::isPost()) {
            $action = Minz_Request::param('clean_action', '');
            $days = (int)Minz_Request::param('inactivity_period', 365);
            if (!in_array($days, self::THRESHOLDS, true)) {
                $days = 365;
            }

            @set_time_limit(300);

            if ($action === 'export_opml') {
                $this->exportInactiveFeedsOPML($days);
            } elseif ($action === 'clean_feeds') {
                $count = $this->cleanInactiveFeeds($days);
                $label = self::t('period_' . $days);

                if ($count > 0) {
                    $msg = self::t('msg_cleaned', $count, $label);
                } else {
                    $msg = self::t('msg_no_feeds', $label);
                }

                $url = array(
                    'c' => 'extension',
                    'a' => 'configure',
                    'params' => array('e' => Minz_Request::param('e', $this->getName()))
                );
                Minz_Request::good($msg, $url);
            }
        }
    }

    private function getInactiveFeeds(int $days = 365): array {
        $feedDAO = FreshRSS_Factory::createFeedDao();
        $entryDAO = FreshRSS_Factory::createEntryDao();

        $feeds = $feedDAO->listFeeds();
        $threshold = time() - ($days * 86400);
        $inactive = array();

        foreach ($feeds as $feed) {
            $latest = null;
            $entries = $entryDAO->listWhere('f', (int)$feed->id(), sort: 'date', order: 'DESC');
            foreach ($entries as $e) {
                $latest = $e;
                break;
            }

            if ($latest !== null) {
                $raw = $latest->date(true);
                $ts = is_numeric($raw) ? (int)$raw : strtotime((string)$latest->date());
                if ($ts > 0 && $ts < $threshold) {
                    $inactive[] = $feed;
                }
            }
        }

        return $inactive;
    }

    private function exportInactiveFeedsOPML(int $days = 365): void {
        $label = self::t('period_' . $days);
        $this->logInfo("Exportation OPML demandée pour : {$days} jours ({$label}).");

        $inactive = $this->getInactiveFeeds($days);
        $count = count($inactive);
        $this->logInfo("Exportation OPML : {$count} flux exporté(s).");

        $filename = 'freshrss_inactifs_' . $days . 'd_' . date('Y-m-d') . '.opml';

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<opml version="2.0">' . "\n";
        echo '  <head>' . "\n";
        echo '    <title>' . htmlspecialchars(self::t('backup_title', $days), ENT_COMPAT, 'UTF-8') . '</title>' . "\n";
        echo '    <dateCreated>' . date('r') . '</dateCreated>' . "\n";
        echo '  </head>' . "\n";
        echo '  <body>' . "\n";

        if (!empty($inactive)) {
            echo '    <outline text="' . htmlspecialchars($label, ENT_COMPAT, 'UTF-8') . '" title="Flux inactifs">' . "\n";
            foreach ($inactive as $feed) {
                $name = htmlspecialchars($feed->name() ?? '', ENT_COMPAT, 'UTF-8');
                $url = htmlspecialchars($feed->url() ?? '', ENT_COMPAT, 'UTF-8');
                $website = htmlspecialchars($feed->website() ?? '', ENT_COMPAT, 'UTF-8');

                echo '      <outline text="' . $name . '" title="' . $name . '" type="rss" xmlUrl="' . $url . '" htmlUrl="' . $website . '"/>' . "\n";
            }
            echo '    </outline>' . "\n";
        }

        echo '  </body>' . "\n";
        echo '</opml>';
        exit;
    }

    private function cleanInactiveFeeds(int $days = 365): int {
        $label = self::t('period_' . $days);
        $this->logInfo("Démarrage de la purge des flux inactifs : {$days} jours ({$label})...");

        $inactive = $this->getInactiveFeeds($days);
        $total = count($inactive);
        $this->logInfo("Flux ciblés : {$total} flux inactif(s) détecté(s).");

        $deleted = 0;
        foreach ($inactive as $feed) {
            $id = (int)$feed->id();
            $name = $feed->name();

            ob_start();
            $success = false;
            try {
                if (class_exists('FreshRSS_feed_Controller') && method_exists('FreshRSS_feed_Controller', 'deleteFeed')) {
                    $success = FreshRSS_feed_Controller::deleteFeed($id);
                } else {
                    $feedDAO = FreshRSS_Factory::createFeedDao();
                    $success = $feedDAO->deleteFeed($id);
                }
            } catch (Throwable $t) {
                $this->logError("Erreur suppression flux #{$id} ({$name}) : " . $t->getMessage());
            }
            ob_end_clean();

            if ($success) {
                $deleted++;
                $this->logInfo("Flux #{$id} '{$name}' supprimé avec succès.");
            } else {
                $this->logError("Échec de la suppression du flux #{$id} '{$name}'.");
            }
        }

        $this->logInfo("Nettoyage achevé : {$deleted}/{$total} flux inactif(s) purgé(s).");
        return $deleted;
    }
}
