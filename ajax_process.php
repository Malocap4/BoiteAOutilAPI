<?php
set_time_limit(30);
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

$action = $_GET['action'] ?? '';
$fileId = $_GET['fileId'] ?? '';
$uploadDir = __DIR__ . '/uploads/';
$inputFile = $uploadDir . $fileId . '.xlsx';
$outputFile = $uploadDir . $fileId . '_out.xlsx';

if (empty($fileId) || !preg_match('/^[a-z0-9\.]+$/i', $fileId)) {
    die(json_encode(['success' => false, 'message' => 'ID de fichier invalide.']));
}

function clean_ffa_text($val) {
    return trim(html_entity_decode(strip_tags($val)));
}

// ACTION 1 : TRAITEMENT PAR SÉQUENCE (AJAX)
if ($action === 'run') {
    $start = (int)($_GET['start'] ?? 2);
    $chunkSize = 5; // Nombre de personnes traitées par appel (anti-timeout)

    if (!file_exists($inputFile)) {
        echo json_encode(['success' => false, 'message' => 'Fichier source introuvable.']);
        exit;
    }

    $spreadsheetIn = IOFactory::load($inputFile);
    $sheetIn = $spreadsheetIn->getActiveSheet();
    $rows = $sheetIn->toArray(null, true, true, true);
    $totalRows = count($rows);

    // Initialisation ou chargement du fichier de sortie
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

        // Scraping Étape A : Recherche ID
        $urlRecherche = "https://www.athle.fr/bases/liste.aspx?frmbase=resultats&frmmode=1&frmnom=" . urlencode(strtoupper($nom)) . "&frmprenom=" . urlencode($prenom);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlRecherche);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        $htmlRecherche = curl_exec($ch);
        curl_close($ch);

        if ($htmlRecherche && preg_match('/code=([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        } elseif ($htmlRecherche && preg_match('/athletes\/([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        }

        // Scraping Étape B : Extraction des Résultats
        if (!empty($idFFA)) {
            $urlResultats = "https://www.athle.fr/athletes/{$idFFA}/resultats";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urlResultats);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
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

                            if (!empty($perf) && !empty($epreuve) && mb_strtolower($epreuve, 'UTF-8') !== "épreuve") { 
                                // NOUVEAU FORMATTAGE STRUCTURÉ DEMANDÉ
                                $listeCompets[] = "{$epreuve} - {$date}\nPlace : {$place} / Temps : {$perf}";
                            }
                        }
                    }
                }
                
                if (!empty($listeCompets)) {
                    // On prend les 5 dernières compétitions max
                    $resultatsFormates = implode("\n\n", array_slice($listeCompets, 0, 5));
                }
            }
        }

        // Écriture à la même ligne correspondante dans l'export
        $sheetOut->setCellValue('A' . $i, $nom);
        $sheetOut->setCellValue('B' . $i, $prenom);
        $sheetOut->setCellValue('C' . $i, $dateNaissance);
        $sheetOut->setCellValue('D' . $i, $idFFA);
        $sheetOut->setCellValue('E' . $i, $resultatsFormates);
        $sheetOut->getStyle('E' . $i)->getAlignment()->setWrapText(true);
    }

    // Sauvegarde de l'état d'avancement
    $writer = new Xlsx($spreadsheetOut);
    $writer->save($outputFile);

    $done = ($end >= $totalRows);
    
    // Nettoyage du fichier temporaire initial si c'est fini
    if ($done && file_exists($inputFile)) {
        unlink($inputFile);
    }

    echo json_encode(['success' => true, 'next' => $end + 1, 'done' => $done]);
    exit;
}

// ACTION 2 : TÉLÉCHARGEMENT FINAL DU FICHIER RECONSTRUIT
if ($action === 'download') {
    if (!file_exists($outputFile)) {
        die("Erreur : Le fichier de résultats n'est pas disponible.");
    }

    $spreadsheetOut = IOFactory::load($outputFile);
    $sheetOut = $spreadsheetOut->getActiveSheet();

    // Largeurs de colonnes sécurisées
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
    
    // Suppression du fichier du serveur après livraison
    unlink($outputFile);
    exit;
}