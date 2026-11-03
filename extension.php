<?php

class InactiveCleanerExtension extends Minz_Extension {
    
    public function init() {
        // Déclenchement manuel uniquement
    }

    public function handleConfigureAction() {
        if (Minz_Request::isPost()) {
            // Récupère l'action demandée par le formulaire (export ou nettoyage)
            $action = Minz_Request::param('clean_action', '');

            if ($action === 'export_opml') {
                $this->exportInactiveFeedsOPML();
            } elseif ($action === 'clean_feeds') {
                $count = $this->cleanInactiveFeeds();
                Minz_Request::good('Nettoyage terminé. ' . $count . ' flux inactif(s) supprimé(s).', 
                                   array('c' => 'extension', 'a' => 'configure', 'e' => $this->getName()));
            }
        }
    }

    // Fonction centralisée pour lister les flux de plus d'un an
    private function getInactiveFeeds() {
        $feedDAO = FreshRSS_Factory::createFeedDao();
        $entryDAO = FreshRSS_Factory::createEntryDao();
        
        $feeds = $feedDAO->listFeeds();
        $one_year_ago = time() - (365 * 24 * 60 * 60);
        $inactive_feeds = array();

        foreach ($feeds as $feed) {
            $entries = $entryDAO->listWhere('f', $feed->id(), 0, 'DESC', 1);
            
            if (!empty($entries)) {
                $latest_entry = array_values($entries)[0]; 
                
                $entry_date = $latest_entry->date();
                $timestamp = is_numeric($entry_date) ? (int)$entry_date : strtotime($entry_date);
                
                if ($timestamp > 0 && $timestamp < $one_year_ago) {
                    $inactive_feeds[] = $feed;
                }
            }
        }
        
        return $inactive_feeds;
    }

    // Génère et force le téléchargement du fichier OPML
    private function exportInactiveFeedsOPML() {
        $inactive_feeds = $this->getInactiveFeeds();
        $filename = 'freshrss_flux_inactifs_' . date('Y-m-d') . '.opml';
        
        // Vide le buffer pour éviter de corrompre le fichier XML avec d'éventuels retours à la ligne
        if (ob_get_length()) {
            ob_clean();
        }
        
        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<opml version="2.0">' . "\n";
        echo '  <head>' . "\n";
        echo '    <title>Sauvegarde des flux inactifs FreshRSS</title>' . "\n";
        echo '    <dateCreated>' . date('r') . '</dateCreated>' . "\n";
        echo '  </head>' . "\n";
        echo '  <body>' . "\n";
        
        if (!empty($inactive_feeds)) {
            echo '    <outline text="Flux inactifs (plus d\'un an)" title="Flux inactifs">' . "\n";
            foreach ($inactive_feeds as $feed) {
                // Échappe les caractères spéciaux pour assurer la validité du XML
                $name = htmlspecialchars($feed->name(), ENT_COMPAT, 'UTF-8');
                $url = htmlspecialchars($feed->url(), ENT_COMPAT, 'UTF-8');
                $website = htmlspecialchars($feed->website(), ENT_COMPAT, 'UTF-8');
                
                echo '      <outline text="' . $name . '" title="' . $name . '" type="rss" xmlUrl="' . $url . '" htmlUrl="' . $website . '"/>' . "\n";
            }
            echo '    </outline>' . "\n";
        }
        
        echo '  </body>' . "\n";
        echo '</opml>';
        
        die(); // Arrête l'exécution de la page ici pour que seul le XML soit téléchargé
    }

    // Supprime les flux identifiés
    private function cleanInactiveFeeds() {
        $inactive_feeds = $this->getInactiveFeeds();
        $feedDAO = FreshRSS_Factory::createFeedDao();
        $deleted_count = 0;
        
        foreach ($inactive_feeds as $feed) {
            $feedDAO->deleteFeed($feed->id());
            $deleted_count++;
        }
        
        return $deleted_count;
    }
}