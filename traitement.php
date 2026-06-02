<?php
if (!function_exists('curl_init')) { die("L'extension cURL n'est toujours pas active sur ce serveur."); }
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Petite fonction d'aide pour nettoyer proprement le HTML renvoyé par la FFA
function clean_ffa_text($val) {
    return trim(html_entity_decode(strip_tags($val)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $fileTmpPath = $_FILES['excel_file']['tmp_name'];
    
    try {
        // 1. Chargement du fichier Excel importé
        $spreadsheetIn = IOFactory::load($fileTmpPath);
        $sheetIn = $spreadsheetIn->getActiveSheet();
        $rows = $sheetIn->toArray(null, true, true, true);
    } catch (\Exception $e) {
        die("Erreur lors de la lecture du fichier Excel : " . $e->getMessage());
    }
    
    if (count($rows) < 1) {
        die("Erreur : Le fichier Excel est vide.");
    }

    // Détection dynamique des index de colonnes (insensible aux majuscules/accents)
    $headers = array_map(function($h) {
        // On passe tout en majuscule et on nettoie les espaces cachés
        return strtoupper(trim($h ?? ''));
    }, $rows[1]);

    // On cherche les index correspondant à tes colonnes
    $colNom = array_search('NOM', $headers);
    $colPrenom = array_search('PRENOM', $headers);
    if ($colPrenom === false) {
        $colPrenom = array_search('PRÉNOM', $headers); // Au cas où l'accent réapparaîtrait
    }
    $colDN = array_search('DATEDENAISSANCE', $headers); 

    // Vérification de sécurité critique
    if ($colNom === false || $colPrenom === false) {
        die("Erreur : Les colonnes 'NOM' et 'PRENOM' sont obligatoires à la première ligne de votre fichier Excel.");
    }

    // 2. Création du nouvel Excel de sortie
    $spreadsheetOut = new Spreadsheet();
    $sheetOut = $spreadsheetOut->getActiveSheet();
    
    // Configuration des entêtes du fichier généré
    $sheetOut->setCellValue('A1', 'Nom');
    $sheetOut->setCellValue('B1', 'Prénom');
    $sheetOut->setCellValue('C1', 'DateDeNaissance');
    $sheetOut->setCellValue('D1', 'Identifiant FFA');
    $sheetOut->setCellValue('E1', 'Résultats');

    $currentRowOut = 2;

    // 3. Boucle de traitement (on démarre à 2 pour sauter la ligne des entêtes)
    for ($i = 2; $i <= count($rows); $i++) {
        $nom = trim($rows[$i][$colNom] ?? '');
        $prenom = trim($rows[$i][$colPrenom] ?? '');
        $dateNaissance = ($colDN !== false) ? trim($rows[$i][$colDN] ?? '') : '';

        // Si la ligne ne contient pas de nom ou prénom, on passe à la suivante
        if (empty($nom) || empty($prenom)) {
            continue;
        }

        $idFFA = "Introuvable";
        $resultatsFormates = "Aucun résultat trouvé";

        // --- ÉTAPE A : Recherche de l'ID Unique de l'athlète ---
        $urlRecherche = "https://www.athle.fr/bases/liste.aspx?frmbase=resultats&frmmode=1&frmnom=" . urlencode(strtoupper($nom)) . "&frmprenom=" . urlencode($prenom);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlRecherche);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $htmlRecherche = curl_exec($ch);
        curl_close($ch);

        // Extraction de l'ID via les structures d'URL classiques de la FFA
        if ($htmlRecherche && preg_match('/code=([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        } elseif ($htmlRecherche && preg_match('/athletes\/([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        }

        // --- ÉTAPE B : Si l'ID est trouvé, récupération de ses résultats ---
        if ($idFFA !== "Introuvable") {
            $urlResultats = "https://www.athle.fr/athletes/{$idFFA}/resultats";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urlResultats);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $htmlResultats = curl_exec($ch);
            curl_close($ch);

            if ($htmlResultats) {
                // Découpage du tableau HTML ligne par ligne (<tr>)
                preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $htmlResultats, $trMatches);
                $listeCompets = [];
                
                foreach ($trMatches[1] as $trContent) {
                    // On filtre les lignes qui contiennent des cellules de données d'épreuves
                    if (strpos($trContent, '<td>') !== false) {
                        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $trContent, $tdMatches);
                        
                        // Une ligne de résultat FFA contient au moins 6 colonnes
                        if (count($tdMatches[1]) >= 6) {
                            $donneesLigne = array_map('clean_ffa_text', $tdMatches[1]);
                            
                            $date = $donneesLigne[0];
                            $epreuve = $donneesLigne[1];
                            $perf = $donneesLigne[2];
                            $placeRaw = $donneesLigne[5]; // Ex: "1", "3 (3M)", "12"

                            // Extraction du premier chiffre pour identifier la place exacte
                            preg_match('/^\d+/', $placeRaw, $placeMatch);
                            $place = isset($placeMatch[0]) ? (int)$placeMatch[0] : 0;

                            // Attribution de la récompense visuelle selon le classement
                            $medaille = "";
                            if ($place === 1) {
                                $medaille = "🏆 ";
                            } elseif ($place === 2) {
                                $medaille = "🥈 ";
                            } elseif ($place === 3) {
                                $medaille = "🥉 ";
                            } elseif ($place > 3) {
                                $medaille = "🏃 (".$place."e) ";
                            }

                            // On n'enregistre la ligne que si elle contient une performance valide
                            if (!empty($perf) && !empty($epreuve) && $epreuve !== "Épreuve") {
                                $listeCompets[] = "{$date} - {$epreuve} : {$medaille}{$perf}";
                            }
                        }
                    }
                }
                
                // On assemble les 5 derniers résultats max avec des retours à la ligne
                if (!empty($listeCompets)) {
                    $resultatsFormates = implode("\n", array_slice($listeCompets, 0, 5));
                }
            }
        }

        // Écriture forcée sur les colonnes A, B, C, D, E du fichier de sortie
        $sheetOut->setCellValueByColumnAndRow(1, $currentRowOut, $nom);
        $sheetOut->setCellValueByColumnAndRow(2, $currentRowOut, $prenom);
        $sheetOut->setCellValueByColumnAndRow(3, $currentRowOut, $dateNaissance);
        $sheetOut->setCellValueByColumnAndRow(4, $currentRowOut, $idFFA);
        $sheetOut->setCellValueByColumnAndRow(5, $currentRowOut, $resultatsFormates);
        
        // Active l'affichage multiligne dans la cellule de l'Excel pour les émojis
        $sheetOut->getStyleByColumnAndRow(5, $currentRowOut)->getAlignment()->setWrapText(true);
        
        $currentRowOut++;
    }

    // Ajustement automatique de la largeur des colonnes pour un rendu propre
    foreach (range('A', 'E') as $col) {
        $sheetOut->getColumnDimension($col)->setAutoSize(true);
    }

    // 4. Envoi du fichier Excel généré en téléchargement direct
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Resultats_FFA_Export.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheetOut);
    $writer->save('php://output');
    exit;
} else {
    // Redirection automatique vers l'index si le script est appelé en direct
    header('Location: index.php');
    exit;
}