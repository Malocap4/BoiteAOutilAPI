<?php
// Force PHP à ne pas couper le script s'il prend du temps
set_time_limit(0); 

if (!function_exists('curl_init')) { die("L'extension cURL n'est toujours pas active sur ce serveur."); }
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Fonction de nettoyage du HTML de la FFA
function clean_ffa_text($val) {
    return trim(html_entity_decode(strip_tags($val)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $fileTmpPath = $_FILES['excel_file']['tmp_name'];
    
    try {
        $spreadsheetIn = IOFactory::load($fileTmpPath);
        $sheetIn = $spreadsheetIn->getActiveSheet();
        $rows = $sheetIn->toArray(null, true, true, true);
    } catch (\Exception $e) {
        die("Erreur lors de la lecture du fichier Excel : " . $e->getMessage());
    }
    
    if (count($rows) < 1) {
        die("Erreur : Le fichier Excel est vide.");
    }

    // Détection des colonnes
    $headers = array_map(function($h) {
        return strtoupper(trim($h ?? ''));
    }, $rows[1]);

    $colNom = array_search('NOM', $headers);
    $colPrenom = array_search('PRENOM', $headers);
    if ($colPrenom === false) {
        $colPrenom = array_search('PRÉNOM', $headers);
    }
    $colDN = array_search('DATEDENAISSANCE', $headers); 

    if ($colNom === false || $colPrenom === false) {
        die("Erreur : Les colonnes 'NOM' et 'PRENOM' sont obligatoires à la première ligne.");
    }

    // Création du fichier de sortie
    $spreadsheetOut = new Spreadsheet();
    $sheetOut = $spreadsheetOut->getActiveSheet();
    
    $sheetOut->setCellValue('A1', 'Nom');
    $sheetOut->setCellValue('B1', 'Prénom');
    $sheetOut->setCellValue('C1', 'DateDeNaissance');
    $sheetOut->setCellValue('D1', 'Identifiant FFA');
    $sheetOut->setCellValue('E1', 'Résultats');

    $currentRowOut = 2;
    $totalLignes = count($rows);

    // Boucle sur l'intégralité du fichier Excel sans limite arbitraire
    for ($i = 2; $i <= $totalLignes; $i++) {
        $nom = trim($rows[$i][$colNom] ?? '');
        $prenom = trim($rows[$i][$colPrenom] ?? '');
        $dateNaissance = ($colDN !== false) ? trim($rows[$i][$colDN] ?? '') : '';

        if (empty($nom) || empty($prenom)) {
            continue;
        }

        // On initialise par défaut à vide selon ta demande
        $idFFA = "";
        $resultatsFormates = "";

        // --- ÉTAPE A : Recherche de l'identifiant ---
        $urlRecherche = "https://www.athle.fr/bases/liste.aspx?frmbase=resultats&frmmode=1&frmnom=" . urlencode(strtoupper($nom)) . "&frmprenom=" . urlencode($prenom);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlRecherche);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Timeout court (3s max) pour garder le script rapide
        $htmlRecherche = curl_exec($ch);
        curl_close($ch);

        if ($htmlRecherche && preg_match('/code=([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        } elseif ($htmlRecherche && preg_match('/athletes\/([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        }

        // --- ÉTAPE B : Récupération des résultats (uniquement si l'ID a été trouvé) ---
        if (!empty($idFFA)) {
            $urlResultats = "https://www.athle.fr/athletes/{$idFFA}/resultats";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urlResultats);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3s max
            $htmlResultats = curl_exec($ch);
            curl_close($ch);

            if ($htmlResultats) {
                preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $htmlResultats, $trMatches);
                $listeCompets = [];
                
                foreach ($trMatches[1] as $trContent) {
                    if (strpos($trContent, '<td>') !== false) {
                        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $trContent, $tdMatches);
                        
                        if (count($tdMatches[1]) >= 6) {
                            $donneesLigne = array_map('clean_ffa_text', $tdMatches[1]);
                            
                            $date = $donneesLigne[0];
                            $epreuve = $donneesLigne[1];
                            $perf = $donneesLigne[2];
                            $placeRaw = $donneesLigne[5];

                            preg_match('/^\d+/', $placeRaw, $placeMatch);
                            $place = isset($placeMatch[0]) ? (int)$placeMatch[0] : 0;

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

                            if (!empty($perf) && !empty($epreuve) && $epreuve !== "Épreuve") {
                                $listeCompets[] = "{$date} - {$epreuve} : {$medaille}{$perf}";
                            }
                        }
                    }
                }
                
                if (!empty($listeCompets)) {
                    $resultatsFormates = implode("\n", array_slice($listeCompets, 0, 5));
                }
            }
        }

        // Écriture dans le fichier Excel (laisse vide si rien n'a été trouvé)
        $sheetOut->setCellValue('A' . $currentRowOut, $nom);
        $sheetOut->setCellValue('B' . $currentRowOut, $prenom);
        $sheetOut->setCellValue('C' . $currentRowOut, $dateNaissance);
        $sheetOut->setCellValue('D' . $currentRowOut, $idFFA);
        $sheetOut->setCellValue('E' . $currentRowOut, $resultatsFormates);
        
        $sheetOut->getStyle('E' . $currentRowOut)->getAlignment()->setWrapText(true);
        $currentRowOut++;
    }

    // Configuration des dimensions fixes
    $sheetOut->getColumnDimension('A')->setWidth(20);
    $sheetOut->getColumnDimension('B')->setWidth(20);
    $sheetOut->getColumnDimension('C')->setWidth(20);
    $sheetOut->getColumnDimension('D')->setWidth(20);
    $sheetOut->getColumnDimension('E')->setWidth(50);

    if (ob_get_contents()) ob_end_clean();
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Resultats_FFA_Export.xlsx"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheetOut);
    $writer->save('php://output');
    exit;
} else {
    header('Location: index.php');
    exit;
}