<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $fileTmpPath = $_FILES['excel_file']['tmp_name'];
    
    // 1. Chargement du fichier Excel importé
    $spreadsheetIn = IOFactory::load($fileTmpPath);
    $sheetIn = $spreadsheetIn->getActiveSheet();
    $rows = $sheetIn->toArray(null, true, true, true);
    
    // Détection des index de colonnes d'après la première ligne (headers)
    $headers = array_map('trim', $rows[1]);
    $colNom = array_search('Nom', $headers);
    $colPrenom = array_search('Prénom', $headers);
    $colDN = array_search('DateDeNaissance', $headers); // Optionnel pour la recherche pure, gardé pour l'export

    if (!$colNom || !$colPrenom) {
        die("Erreur : Les colonnes 'Nom' et 'Prénom' sont obligatoires dans le fichier Excel.");
    }

    // 2. Création du nouvel Excel de sortie
    $spreadsheetOut = new Spreadsheet();
    $sheetOut = $spreadsheetOut->getActiveSheet();
    
    // Entêtes du fichier de sortie
    $sheetOut->setCellValue('A1', 'Nom');
    $sheetOut->setCellValue('B1', 'Prénom');
    $sheetOut->setCellValue('C1', 'DateDeNaissance');
    $sheetOut->setCellValue('D1', 'Identifiant FFA');
    $sheetOut->setCellValue('E1', 'Résultats');

    $currentRowOut = 2;

    // 3. Boucle sur chaque ligne du tableau (on saute la ligne 1 des entêtes)
    for ($i = 2; $i <= count($rows); $i++) {
        $nom = trim($rows[$i][$colNom] ?? '');
        $prenom = trim($rows[$i][$colPrenom] ?? '');
        $dateNaissance = trim($rows[$i][$colDN] ?? '');

        if (empty($nom) || empty($prenom)) continue;

        // --- ÉTAPE A : Trouver l'ID unique via le premier CURL ---
        $idFFA = "Introuvable";
        $resultatsFormates = "Aucun résultat trouvé";

        $urlRecherche = "https://www.athle.fr/bases/liste.aspx?frmmode=1";
        $postFields = "frmpostback=true&frmbase=resultats&frmmode=1&frmespace=0&frmsaison=2025&frmclub=&frmlicence=&frmnom=" . urlencode(strtoupper($nom)) . "&frmprenom=" . urlencode($prenom) . "&frmsexe=&frmdepartement=&frmligue=&frmcomprch=";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlRecherche);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $htmlRecherche = curl_exec($ch);
        curl_close($ch);

        // Extraction de l'ID via Regex
        if ($htmlRecherche && preg_match('/href="[^"]*athletes\/([0-9]+)\/resultats"/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];

            // --- ÉTAPE B : Récupérer les résultats via le deuxième CURL ---
            $urlResultats = "https://www.athle.fr/athletes/{$idFFA}/resultats";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urlResultats);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            $htmlResultats = curl_exec($ch);
            curl_close($ch);

            if ($htmlResultats) {
                // Analyse basique du HTML pour extraire les lignes de résultats
                // Note : Les sites fédéraux changent souvent, ceci cherche la structure des tableaux de résultats standard.
                // On va chercher les lignes contenant les performances et les places.
                // Pour l'exemple, on parse les lignes de résultats (balises <tr> contenant les classes de lignes de la FFA)
                
                preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $htmlResultats, $trMatches);
                
                $listeCompets = [];
                
                foreach ($trMatches[1] as $trContent) {
                    // Si la ligne contient un niveau ou une épreuve
                    if (strpos($trContent, 'Place') === false && preg_match('/<td>(.*?)<\/td>/is', $trContent)) {
                        // Extraction de toutes les cellules de la ligne de résultat
                        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $trContent, $tdMatches);
                        $donneesLigne = array_map('strip_tags', $tdMatches[1]);
                        $donneesLigne = array_map('trim', $donneesLigne);

                        // Structure classique FFA : Date | Épreuve | Performance | Vent | Tour | Place | Niveau ...
                        if (count($donneesLigne) >= 6) {
                            $date = $donneesLigne[0];
                            $epreuve = $donneesLigne[1];
                            $perf = $donneesLigne[2];
                            $placeRaw = $donneesLigne[5]; // Souvent écrit "1 (1M)" ou juste "1"

                            // Nettoyage de la place pour obtenir le chiffre pur
                            preg_match('/^\d+/', $placeRaw, $placeMatch);
                            $place = isset($placeMatch[0]) ? (int)$placeMatch[0] : 0;

                            // Attribution de la médaille ou du trophée
                            $medaille = "";
                            if ($place === 1) {
                                $medaille = "🏆 ";
                            } elseif ($place === 2) {
                                $medaille = "🥈 ";
                            } elseif ($place === 3) {
                                $medaille = "🥉 ";
                            } elseif ($place > 3) {
                                $medaille = "🏃 ({$place}e) ";
                            }

                            if (!empty($perf)) {
                                $listeCompets[] = "{$date} - {$epreuve} : {$medaille}{$perf}";
                            }
                        }
                    }
                }
                
                if (!empty($listeCompets)) {
                    // On regroupe les 5 derniers résultats max séparés par un retour à la ligne
                    $resultatsFormates = implode("\n", array_slice($listeCompets, 0, 5));
                }
            }
        }

        // Ecriture dans le fichier de sortie
        $sheetOut->setCellValue('A' . $currentRowOut, $nom);
        $sheetOut->setCellValue('B' . $currentRowOut, $prenom);
        $sheetOut->setCellValue('C' . $currentRowOut, $dateNaissance);
        $sheetOut->setCellValue('D' . $currentRowOut, $idFFA);
        $sheetOut->setCellValue('E' . $currentRowOut, $resultatsFormates);
        
        // Active le retour à la ligne automatique pour la cellule des résultats
        $sheetOut->getStyle('E' . $currentRowOut)->getAlignment()->setWrapText(true);

        $currentRowOut++;
    }

    // Ajustement automatique des colonnes pour la lisibilité
    foreach (range('A', 'E') as $col) {
        $sheetOut->getColumnDimension($col)->setAutoSize(true);
    }

    // 4. Envoi du fichier Excel généré au navigateur pour téléchargement
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Resultats_FFA_Export.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheetOut);
    $writer->save('php://output');
    exit;
} else {
    header('Location: index.php');
    exit;
}