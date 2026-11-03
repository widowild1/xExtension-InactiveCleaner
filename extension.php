<?php

class InactiveCleanerExtension extends Minz_Extension {
    
    public function init() {
        // Aucune action automatique à l'initialisation, le déclenchement est manuel.
    }

    public function handleConfigureAction() {
        // Si le bouton de nettoyage est cliqué (requête POST)
        if (Minz_Request::isPost()) {
            $count = $this->cleanInactiveFeeds();
            
            // Affiche un message de succès et redirige vers la page de configuration
            Minz_Request::good('Nettoyage terminé. ' . $count . ' flux inactif(s) supprimé(s).', 
                               array('c' => 'extension', 'a' => 'configure', 'e' => $this->getName()));
        }
    }

    private function cleanInactiveFeeds() {
        $feedDAO = FreshRSS_Factory::createFeedDao();
        $entryDAO = FreshRSS_Factory::createEntryDao();
        
        $feeds = $feedDAO->listFeeds();
        $one_year_ago = time() - (365 * 24 * 60 * 60);
        $deleted_count = 0;

        foreach ($feeds as $feed) {
            // Récupère l'article le plus récent de ce flux (type 'f', id du flux, état 0=tous, ordre décroissant, limite 1)
            $entries = $entryDAO->listWhere('f', $feed->id(), 0, 'DESC', 1);
            
            if (!empty($entries)) {
                $latest_entry = array_values($entries)[0]; 
                
                // S'assure que la date est au format timestamp (entier)
                $entry_date = $latest_entry->date();
                $timestamp = is_numeric($entry_date) ? (int)$entry_date : strtotime($entry_date);
                
                if ($timestamp > 0 && $timestamp < $one_year_ago) {
                    // Supprime le flux et incrémente le compteur
                    $feedDAO->deleteFeed($feed->id());
                    $deleted_count++;
                }
            }
            // Note : Si un flux a 0 article (soit il est tout neuf, soit la politique de rétention a tout purgé), 
            // l'extension l'ignore par sécurité pour ne pas effacer un flux fraîchement ajouté.
        }
        
        return $deleted_count;
    }
}