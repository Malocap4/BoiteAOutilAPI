<?php
// On empêche le script de saturer la mémoire ou le temps système
set_time_limit(20);
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

$action = $_GET['action'] ?? '';
$fileId = $_GET['fileId'] ?? '';
$uploadDir = __DIR__ . '/uploads/';
$inputFile = $uploadDir . $fileId . '.xlsx';
$outputFile = $uploadDir . $fileId . '_out.xlsx';

// Vérification de sécurité de l'identifiant (uniquement des chiffres)
if (empty($fileId) || !preg_match('/^[0-9]+$/', $fileId)) {
    die(json_encode(['success' => false, 'message' => 'ID de fichier invalide.']));
}

function clean_ffa_text($val) {
    return trim(html_entity_decode(strip_tags($val)));
}

// ACTION 1 : TRAITEMENT PAR SÉQUENCE RAPIDE (AJAX)
if ($action === 'run') {
    $start = (int)($_GET['start'] ?? 2);
    $chunkSize = 2; // TRÈS IMPORTANT : On traite par paquets de 2 pour éviter le gel sur Render

    // Si le fichier temporaire initial n'existe plus mais que le fichier de sortie est là, c'est qu'on a déjà fini
    if (!file_exists($inputFile) && file_exists($outputFile) && $start > 2) {
        echo json_encode(['success' => true, 'next' => $start, 'done' => true]);
        exit;
    }

    if (!file_exists($inputFile)) {
        echo json_encode(['success' => false, 'message' => 'Fichier source introuvable. Veuillez réessayer l\'import.']);
        exit;
    }

    try {
        $spreadsheetIn = IOFactory::load($inputFile);
        $sheetIn = $spreadsheetIn->getActiveSheet();
        $rows = $sheetIn->toArray(null, true, true, true);
        $totalRows = count($rows);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur de lecture : ' . $e->getMessage()]);
        exit;
    }

    // Chargement ou création de l'export final
    if (file_exists($outputFile)) {
        $spreadsheetOut = IOFactory::load($outputFile);
        $sheetOut = $spreadsheetOut->getActiveSheet();
    } else {
        $spreadsheetOut = new Spreadsheet();
        $sheetOut = $spreadsheetOut->getActiveSheet();
        $sheetOut->setCellValue('A1', 'Nom');
        $sheetOut->setCellValue('B1', 'Prénom');
        $sheetOut->setCellValue('C1', 'DateDeNaissance');
        $sheetOut->setCellValue('D1', 'Identifiant FFA');
        $sheetOut->setCellValue('E1', 'Résultats');
    }

    $headers = array_map(function($h) { return strtoupper(trim($h ?? '')); }, $rows[1]);
    $colNom = array_search('NOM', $headers);
    $colPrenom = array_search('PRENOM', $headers);
    if ($colPrenom === false) { $colPrenom = array_search('PRÉNOM', $headers); }
    $colDN = array_search('DATEDENAISSANCE', $headers);

    $end = min($totalRows, $start + $chunkSize - 1);

    for ($i = $start; $i <= $end; $i++) {
        $nom = trim($rows[$i][$colNom] ?? '');
        $prenom = trim($rows[$i][$colPrenom] ?? '');
        $dateNaissance = ($colDN !== false) ? trim($rows[$i][$colDN] ?? '') : '';

        if (empty($nom) || empty($prenom)) {
            continue;
        }

        $idFFA = "";
        $resultatsFormates = "";

        // --- ÉTAPE A : Recherche de l'ID avec un Timeout ultra-strict (2 secondes) ---
        $urlRecherche = "https://www.athle.fr/bases/liste.aspx?frmbase=resultats&frmmode=1&frmnom=" . urlencode(strtoupper($nom)) . "&frmprenom=" . urlencode($prenom);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlRecherche);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); 
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $htmlRecherche = curl_exec($ch);
        curl_close($ch);

        if ($htmlRecherche && preg_match('/code=([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        } elseif ($htmlRecherche && preg_match('/athletes\/([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        }

        // --- ÉTAPE B : Extraction des résultats (Timeout 2 secondes) ---
        if (!empty($idFFA)) {
            $urlResultats = "https://www.athle.fr/athletes/{$idFFA}/resultats";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urlResultats);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
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
                            $place = isset($placeMatch[0]) ? $placeMatch[0] : $placeRaw;

                            $epreuveLower = strtolower($epreuve);
                            if (!empty($perf) && !empty($epreuve) && $epreuveLower !== "épreuve" && $epreuveLower !== "epreuve") {
                                $listeCompets[] = "{$epreuve} - {$date}\nPlace : {$place} / Temps : {$perf}";
                            }
                        }
                    }
                }
                
                if (!empty($listeCompets)) {
                    $resultatsFormates = implode("\n\n", array_slice($listeCompets, 0, 5));
                }
            }
        }

        // Enregistrement de la ligne
        $sheetOut->setCellValue('A' . $i, $nom);
        $sheetOut->setCellValue('B' . $i, $prenom);
        $sheetOut->setCellValue('C' . $i, $dateNaissance);
        $sheetOut->setCellValue('D' . $i, $idFFA);
        $sheetOut->setCellValue('E' . $i, $resultatsFormates);
        $sheetOut->getStyle('E' . $i)->getAlignment()->setWrapText(true);
    }

    // Sauvegarde immédiate du morceau traité
    $writer = new Xlsx($spreadsheetOut);
    $writer->save($outputFile);

    $done = ($end >= $totalRows);
    
    // On ne supprime le fichier d'entrée que si TOUT est définitivement achevé
    if ($done && file_exists($inputFile)) {
        unlink($inputFile);
    }

    echo json_encode(['success' => true, 'next' => $end + 1, 'done' => $done]);
    exit;
}

// ACTION 2 : TÉLÉCHARGEMENT DU FICHIER FINAL RECONSTRUIT
if ($action === 'download') {
    if (!file_exists($outputFile)) {
        die("Erreur : Le fichier généré est introuvable sur le serveur.");
    }

    $spreadsheetOut = IOFactory::load($outputFile);
    $sheetOut = $spreadsheetOut->getActiveSheet();

    $sheetOut->getColumnDimension('A')->setWidth(20);
    $sheetOut->getColumnDimension('B')->setWidth(20);
    $sheetOut->getColumnDimension('C')->setWidth(20);
    $sheetOut->getColumnDimension('D')->setWidth(20);
    $sheetOut->getColumnDimension('E')->setWidth(55);

    if (ob_get_contents()) ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Resultats_FFA_Fini.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheetOut);
    $writer->save('php://output');
    
    unlink($outputFile);
    exit;
}