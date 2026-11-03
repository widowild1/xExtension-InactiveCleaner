<?php

class InactiveCleanerExtension extends Minz_Extension {
    
    public function init() {
        // Déclenchement manuel
    }

    public function handleConfigureAction() {
        if (Minz_Request::isPost()) {
            $action = Minz_Request::param('clean_action', '');
            // Récupération de la durée en jours (par défaut 365 jours)
            $days = (int)Minz_Request::param('inactivity_period', 365);
            if ($days <= 0) {
                $days = 365;
            }

            if ($action === 'export_opml') {
                $this->exportInactiveFeedsOPML($days);
            } elseif ($action === 'clean_feeds') {
                $count = $this->cleanInactiveFeeds($days);
                Minz_Request::good('Nettoyage terminé : ' . $count . ' flux inactif(s) supprimé(s).', 
                                   array('c' => 'extension', 'a' => 'configure', 'e' => $this->getName()));
            }
        }
    }

    private function getInactiveFeeds($days = 365) {
        $feedDAO = FreshRSS_Factory::createFeedDao();
        $entryDAO = FreshRSS_Factory::createEntryDao();
        
        $feeds = $feedDAO->listFeeds();
        $threshold = time() - ($days * 24 * 60 * 60);
        $inactive_feeds = array();

        foreach ($feeds as $feed) {
            // Récupère le dernier article publié
            $entries = $entryDAO->listWhere('f', $feed->id(), 0, 'DESC', 1);
            
            if (!empty($entries)) {
                $latest_entry = array_values($entries)[0]; 
                $entry_date = $latest_entry->date();
                $timestamp = is_numeric($entry_date) ? (int)$entry_date : strtotime($entry_date);
                
                if ($timestamp > 0 && $timestamp < $threshold) {
                    $inactive_feeds[] = $feed;
                }
            } else {
                // Pour les flux sans article : vérifie la date de dernière mise à jour/tentative du flux
                $last_update = $feed->lastUpdate();
                $timestamp = is_numeric($last_update) ? (int)$last_update : strtotime($last_update);
                if ($timestamp > 0 && $timestamp < $threshold) {
                    $inactive_feeds[] = $feed;
                }
            }
        }
        
        return $inactive_feeds;
    }

    private function exportInactiveFeedsOPML($days = 365) {
        $inactive_feeds = $this->getInactiveFeeds($days);
        $filename = 'freshrss_inactifs_' . $days . 'j_' . date('Y-m-d') . '.opml';
        
        if (ob_get_length()) {
            ob_clean();
        }
        
        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<opml version="2.0">' . "\n";
        echo '  <head>' . "\n";
        echo '    <title>Sauvegarde flux inactifs (' . $days . ' jours)</title>' . "\n";
        echo '    <dateCreated>' . date('r') . '</dateCreated>' . "\n";
        echo '  </head>' . "\n";
        echo '  <body>' . "\n";
        
        if (!empty($inactive_feeds)) {
            echo '    <outline text="Flux inactifs (' . $days . ' jours)" title="Flux inactifs">' . "\n";
            foreach ($inactive_feeds as $feed) {
                $name = htmlspecialchars($feed->name(), ENT_COMPAT, 'UTF-8');
                $url = htmlspecialchars($feed->url(), ENT_COMPAT, 'UTF-8');
                $website = htmlspecialchars($feed->website(), ENT_COMPAT, 'UTF-8');
                
                echo '      <outline text="' . $name . '" title="' . $name . '" type="rss" xmlUrl="' . $url . '" htmlUrl="' . $website . '"/>' . "\n";
            }
            echo '    </outline>' . "\n";
        }
        
        echo '  </body>' . "\n";
        echo '</opml>';
        
        die();
    }

    private function cleanInactiveFeeds($days = 365) {
        $inactive_feeds = $this->getInactiveFeeds($days);
        $feedDAO = FreshRSS_Factory::createFeedDao();
        $deleted_count = 0;
        
        foreach ($inactive_feeds as $feed) {
            $feedDAO->deleteFeed($feed->id());
            $deleted_count++;
        }
        
        return $deleted_count;
    }
}