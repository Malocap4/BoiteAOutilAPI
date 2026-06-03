<?php
set_time_limit(60); // Augmentation du temps max par sécurité

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
    $chunkSize = 2; // Traitement par paquet de 3 lignes pour éviter les surcharges réseau

    // Si le fichier source a déjà été nettoyé et que le traitement est fini
    if (!file_exists($inputFile) && file_exists($outputFile) && $start > 2) {
        echo json_encode(['success' => true, 'next' => $start, 'done' => true]);
        exit;
    }

    if (!file_exists($inputFile) && !file_exists($outputFile)) {
        echo json_encode(['success' => false, 'message' => 'Fichier source introuvable.']);
        exit;
    }

    try {
        // OPTIMISATION RÉSEAU : Si le fichier de sortie existe déjà, on travaille dessus.
        // Sinon, on démarre depuis le fichier initial.
        $currentFileToLoad = file_exists($outputFile) ? $outputFile : $inputFile;
        
        $spreadsheet = IOFactory::load($currentFileToLoad);
        $sheet = $spreadsheet->getActiveSheet();
        
        // On récupère uniquement la matrice de données nécessaire
        $rows = $sheet->toArray(null, true, true, true);
        $totalRows = $maxRows > 0 ? min(count($rows), $maxRows) : count($rows);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur Excel : ' . $e->getMessage()]);
        exit;
    }

    // Détection dynamique des colonnes Nom et Prénom
    $headers = array_map(function($h) { return strtoupper(trim($h ?? '')); }, $rows[1]);
    $colNom = array_search('NOM', $headers);
    $colPrenom = array_search('PRENOM', $headers);
    if ($colPrenom === false) { $colPrenom = array_search('PRÉNOM', $headers); }

    if ($colNom === false || $colPrenom === false) {
        echo json_encode(['success' => false, 'message' => "Colonnes 'NOM' et 'PRENOM' introuvables."]);
        exit;
    }

    // Détermination ou récupération des colonnes de sortie
    $configFile = $uploadDir . $fileId . '_cols.json';
    if (!file_exists($configFile)) {
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        
        $colIndexFFA = $highestColumnIndex + 1;
        $colIndexLicence = $highestColumnIndex + 2;
        $colIndexResultats = $highestColumnIndex + 3;
        
        file_put_contents($configFile, json_encode([
            'ffa' => $colIndexFFA,
            'licence' => $colIndexLicence,
            'res' => $colIndexResultats
        ]));

        // Écriture des entêtes
        $sheet->setCellValueByColumnAndRow($colIndexFFA, 1, 'Identifiant FFA');
        $sheet->setCellValueByColumnAndRow($colIndexLicence, 1, 'Licence');
        $sheet->setCellValueByColumnAndRow($colIndexResultats, 1, 'Résultats');
    } else {
        $colsConfig = json_decode(file_get_contents($configFile), true);
        $colIndexFFA = $colsConfig['ffa'];
        $colIndexLicence = $colsConfig['licence'];
        $colIndexResultats = $colsConfig['res'];
    }

    $end = min($totalRows, $start + $chunkSize - 1);

    // Boucle de traitement du paquet courant en mémoire vive (Ultra rapide)
    for ($i = $start; $i <= $end; $i++) {
        $nom = trim($rows[$i][$colNom] ?? '');
        $prenom = trim($rows[$i][$colPrenom] ?? '');

        if (empty($nom) || empty($prenom)) {
            continue;
        }

        $idFFA = "";
        $numLicence = "";
        $resultatsFormates = "";

        // 1. Appel cURL Recherche ID
        $urlRecherche = "https://www.athle.fr/bases/liste.aspx?frmbase=resultats&frmmode=1&frmnom=" . urlencode(strtoupper($nom)) . "&frmprenom=" . urlencode($prenom);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlRecherche);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 3); 
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $htmlRecherche = curl_exec($ch);
        curl_close($ch);

        if ($htmlRecherche && preg_match('/code=([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        } elseif ($htmlRecherche && preg_match('/athletes\/([0-9]+)/', $htmlRecherche, $matches)) {
            $idFFA = $matches[1];
        }

        // 2. Appel cURL Fiche Athlète
        if (!empty($idFFA)) {
            $urlResultats = "https://www.athle.fr/athletes/{$idFFA}/resultats";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urlResultats);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            $htmlResultats = curl_exec($ch);
            curl_close($ch);

            if ($htmlResultats) {
                if (preg_match('/Licence\s*:\s*<\/b>\s*([0-9]+)/is', $htmlResultats, $licenceMatches)) {
                    $numLicence = trim($licenceMatches[1]);
                }

                preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $htmlResultats, $trMatches);
                $listeCompets = [];
                
                foreach ($trMatches[1] as $trContent) {
                    if (strpos($trContent, '<td>') !== false) {
                        preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $trContent, $tdMatches);
                        
                        if (count($tdMatches[1]) >= 6) {
                            $donneesLigne = array_map('clean_ffa_text', $tdMatches[1]);
                            
                            $date = $donneesLigne[0];
                            $courseType = $donneesLigne[1]; 
                            $perf = $donneesLigne[2];       
                            $lieu = $donneesLigne[3]; 
                            $evenement = $donneesLigne[4];  
                            $placeRaw = $donneesLigne[5];   

                            $courseLower = strtolower($courseType);
                            if ($courseLower === "épreuve" || $courseLower === "epreuve" || empty($perf)) {
                                continue;
                            }

                            if (empty($evenement) || strtolower($evenement) === $courseLower) {
                                $evenement = $courseType . " de " . $lieu;
                            } else {
                                $evenement = $evenement . " [" . $courseType . "]";
                            }

                            preg_match('/^\d+/', $placeRaw, $placeMatch);
                            $emojiClassement = "";
                            
                            if (isset($placeMatch[0]) && is_numeric($placeMatch[0])) {
                                $place = (int)$placeMatch[0];
                                if ($place === 1) {
                                    $emojiClassement = "🏆 ";
                                    $placeFormatee = "1er";
                                } elseif ($place === 2) {
                                    $emojiClassement = "🥈 ";
                                    $placeFormatee = "2e";
                                } elseif ($place === 3) {
                                    $emojiClassement = "🥉 ";
                                    $placeFormatee = "3e";
                                } else {
                                    $placeFormatee = $place . "e";
                                }
                            } else {
                                $placeFormatee = "Classé";
                            }

                            $blocResultat = "- {$evenement} ({$date}) -> Place : {$emojiClassement}{$placeFormatee} / Temps : {$perf}";
                            $listeCompets[] = $blocResultat;
                        }
                    }
                }
                
                if (!empty($listeCompets)) {
                    $resultatsFormates = implode("\n", array_slice($listeCompets, 0, 5));
                }
            }
        }

        // Écriture des données calculées en mémoire
        $sheet->setCellValueByColumnAndRow($colIndexFFA, $i, $idFFA);
        $sheet->setCellValueByColumnAndRow($colIndexLicence, $i, $numLicence);
        $sheet->setCellValueByColumnAndRow($colIndexResultats, $i, $resultatsFormates);
        $sheet->getCellByColumnAndRow($colIndexResultats, $i)->getStyle()->getAlignment()->setWrapText(true);
    }

    // SAUVEGARDE UNIQUE À LA FIN DU CHUNK (Évite l'asphyxie d'I/O disque de Render)
    $writer = new Xlsx($spreadsheet);
    $writer->save($outputFile);

    // Libération immédiate de la mémoire PHP
    $spreadsheet->disconnectCells();
    unset($spreadsheet);

    $done = ($end >= $totalRows);
    if ($done) {
        if (file_exists($inputFile)) {
            unlink($inputFile);
        }
        @unlink($configFile);
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

    $highestColumn = $sheetOut->getHighestColumn();
    $sheetOut->getColumnDimension($highestColumn)->setWidth(85);

    if (ob_get_contents()) ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Resultats_FFA_Complet.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheetOut);
    $writer->save('php://output');
    unlink($outputFile);
    exit;
}