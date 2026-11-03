<?php

class InactiveCleanerExtension extends Minz_Extension {

    public const THRESHOLDS = array(7, 30, 90, 180, 365, 730);

    public function init() {
        // Déclenchement manuel
    }

    public static function t(string $key, ...$params): string {
        $fullKey = 'ext.inactive_cleaner.' . $key;
        $translated = _t($fullKey);

        if ($translated === $fullKey || $translated === '') {
            $lang = class_exists('FreshRSS_Context') && FreshRSS_Context::hasUserConf() 
                ? FreshRSS_Context::userConf()->language 
                : 'en';
            $isFr = (substr($lang, 0, 2) === 'fr');

            $defaults = array(
                'period_7' => $isFr ? 'Semaine dernière (7 jours)' : 'Last week (7 days)',
                'period_30' => $isFr ? '1 mois (30 jours)' : '1 month (30 days)',
                'period_90' => $isFr ? '3 mois (90 jours)' : '3 months (90 days)',
                'period_180' => $isFr ? '6 mois (180 jours)' : '6 months (180 days)',
                'period_365' => $isFr ? '1 an (365 jours)' : '1 year (365 days)',
                'period_730' => $isFr ? '+1 an sans mise à jour (2 ans)' : '+1 year without updates (2 years)',
                'feeds_count' => $isFr ? '%d flux' : '%d feed(s)',
                'no_feeds' => $isFr ? '0 flux' : '0 feed',
            );
            $translated = $defaults[$key] ?? $key;
        }

        return empty($params) ? $translated : vsprintf($translated, $params);
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
