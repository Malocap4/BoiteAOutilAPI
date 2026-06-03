<?php
set_time_limit(20);

// On remonte de deux dossiers pour atteindre la racine du projet Docker
require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

$action = $_GET['action'] ?? '';
$fileId = $_GET['fileId'] ?? '';
$uploadDir = __DIR__ . '/uploads/';
$inputFile = $uploadDir . $fileId . '.xlsx';
$outputFile = $uploadDir . $fileId . '_out.xlsx';

if (empty($fileId) || !preg_match('/^[0-9]+$/', $fileId)) {
    die(json_encode(['success' => false, 'message' => 'ID de fichier invalide.']));
}

function clean_ffa_text($val) {
    return trim(html_entity_decode(strip_tags($val ?? '')));
}

if ($action === 'run') {
    $start = (int)($_GET['start'] ?? 2);
    $maxRows = (int)($_GET['maxRows'] ?? 0); 
    $chunkSize = 2; 

    if (!file_exists($inputFile) && file_exists($outputFile) && $start > 2) {
        echo json_encode(['success' => true, 'next' => $start, 'done' => true]);
        exit;
    }

    if (!file_exists($inputFile)) {
        echo json_encode(['success' => false, 'message' => 'Fichier source introuvable.']);
        exit;
    }

    try {
        $spreadsheetIn = IOFactory::load($inputFile);
        $sheetIn = $spreadsheetIn->getActiveSheet();
        $rows = $sheetIn->toArray(null, true, true, true);
        $totalRows = $maxRows > 0 ? min(count($rows), $maxRows) : count($rows);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur de lecture : ' . $e->getMessage()]);
        exit;
    }

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

        $urlRecherche = "https://www.athle.fr/bases/liste.aspx?frmbase=resultats&frmmode=1&frmnom=" . urlencode(strtoupper($nom)) . "&frmprenom=" . urlencode($prenom);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlRecherche);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 4); 
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        $htmlRecherche = curl_exec($ch);
        curl_close($ch);

        if ($htmlRecherche && preg_match('/code=([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        } elseif ($htmlRecherche && preg_match('/athletes\/([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        }

        if (!empty($idFFA)) {
            $urlResultats = "https://www.athle.fr/athletes/{$idFFA}/resultats";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urlResultats);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            $htmlResultats = curl_exec($ch);
            curl_close($ch);

            if ($htmlResultats) {
                preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $htmlResultats, $trMatches);
                $listeCompets = [];
                
                foreach ($trMatches[1] as $trContent) {
                    if (strpos($trContent, '<td>') !== false) {
                        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $trContent, $tdMatches);
                        
                        // Si la ligne contient bien le nombre de colonnes attendu
                        if (count($tdMatches[1]) >= 6) {
                            $donneesLigne = array_map('clean_ffa_text', $tdMatches[1]);
                            
                            $date = $donneesLigne[0];
                            $courseType = $donneesLigne[1]; 
                            $perf = $donneesLigne[2];       
                            $lieu = $donneesLigne[3]; // Contient la ville/département
                            $evenement = $donneesLigne[4];  
                            $placeRaw = $donneesLigne[5];   

                            $courseLower = strtolower($courseType);
                            if ($courseLower === "épreuve" || $courseLower === "epreuve" || empty($perf)) {
                                continue;
                            }

                            // CORRECTION 1 : Si l'évènement est vide ou identique au type, on combine intelligemment avec le lieu
                            if (empty($evenement) || strtolower($evenement) === $courseLower) {
                                $evenement = $courseType . " de " . $lieu;
                            } else {
                                // Sinon, on affiche le combo complet : "Nom de l'évènement - [Type de course]"
                                $evenement = $evenement . " [" . $courseType . "]";
                            }

                            // CORRECTION 2 : Gestion du classement décalé par le lieu
                            // On extrait uniquement le premier nombre rencontré dans la colonne place
                            preg_match('/^\d+/', $placeRaw, $placeMatch);
                            
                            if (isset($placeMatch[0]) && is_numeric($placeMatch[0])) {
                                $place = $placeMatch[0];
                                $placeFormatee = ($place == 1) ? "1er" : $place . "e";
                            } else {
                                // Si aucun chiffre n'est détecté au début (ex: un nom de ville s'est glissé ici), on n'affiche pas de classement erroné
                                $placeFormatee = "Classé";
                            }

                            $blocResultat = "★ {$evenement}\n📅 {$date}\n🏆 Place : {$placeFormatee} / Temps : {$perf}";
                            $listeCompets[] = $blocResultat;
                        }
                    }
                }
                
                if (!empty($listeCompets)) {
                    // On prend les 5 compétitions les plus récentes trouvées
                    $resultatsFormates = implode("\n\n", array_slice($listeCompets, 0, 5));
                }
            }
        }

        $sheetOut->setCellValue('A' . $i, $nom);
        $sheetOut->setCellValue('B' . $i, $prenom);
        $sheetOut->setCellValue('C' . $i, $dateNaissance);
        $sheetOut->setCellValue('D' . $i, $idFFA);
        $sheetOut->setCellValue('E' . $i, $resultatsFormates);
        $sheetOut->getStyle('E' . $i)->getAlignment()->setWrapText(true);
    }

    $writer = new Xlsx($spreadsheetOut);
    $writer->save($outputFile);

    $done = ($end >= $totalRows);
    if ($done && file_exists($inputFile)) {
        unlink($inputFile);
    }

    echo json_encode(['success' => true, 'next' => $end + 1, 'done' => $done]);
    exit;
}

if ($action === 'download') {
    if (!file_exists($outputFile)) {
        die("Erreur : Le fichier de résultats temporaire est introuvable.");
    }

    $spreadsheetOut = IOFactory::load($outputFile);
    $sheetOut = $spreadsheetOut->getActiveSheet();

    $sheetOut->getColumnDimension('A')->setWidth(18);
    $sheetOut->getColumnDimension('B')->setWidth(18);
    $sheetOut->getColumnDimension('C')->setWidth(18);
    $sheetOut->getColumnDimension('D')->setWidth(18);
    $sheetOut->getColumnDimension('E')->setWidth(75); // Cellule élargie pour accueillir la mise en forme claire

    if (ob_get_contents()) ob_get_contents(); ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Resultats_FFA_Complet.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheetOut);
    $writer->save('php://output');
    unlink($outputFile);
    exit;
}