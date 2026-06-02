<?php
if (!function_exists('curl_init')) { die("L'extension cURL n'est toujours pas active sur ce serveur."); }
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $fileTmpPath = $_FILES['excel_file']['tmp_name'];
    $uploadDir = __DIR__ . '/uploads/';
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // On sauvegarde temporairement le fichier sur le serveur
    $fileId = uniqid();
    $savedFilename = $uploadDir . $fileId . '.xlsx';
    move_uploaded_file($fileTmpPath, $savedFilename);
    
    try {
        $spreadsheetIn = IOFactory::load($savedFilename);
        $sheetIn = $spreadsheetIn->getActiveSheet();
        $rows = $sheetIn->toArray(null, true, true, true);
        $totalRows = count($rows);
    } catch (\Exception $e) {
        die("Erreur lors de la lecture du fichier Excel : " . $e->getMessage());
    }

    if ($totalRows <= 1) {
        die("Erreur : Le fichier Excel est vide ou ne contient que les entêtes.");
    }

    // Détection des entêtes
    $headers = array_map(function($h) { return strtoupper(trim($h ?? '')); }, $rows[1]);
    $colNom = array_search('NOM', $headers);
    $colPrenom = array_search('PRENOM', $headers);
    if ($colPrenom === false) { $colPrenom = array_search('PRÉNOM', $headers); }

    if ($colNom === false || $colPrenom === false) {
        unlink($savedFilename);
        die("Erreur : Les colonnes 'NOM' et 'PRENOM' sont obligatoires à la première ligne.");
    }

    // On affiche l'interface de progression à l'utilisateur
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <title>Traitement en cours...</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; background: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .box { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center; width: 400px; }
            .progress-bg { background: #e2e8f0; border-radius: 20px; height: 20px; width: 100%; margin: 20px 0; overflow: hidden; }
            .progress-bar { background: #2563eb; height: 100%; width: 0%; transition: width 0.3s ease; }
            .btn { display: none; background: #16a34a; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: bold; margin-top: 15px; }
            .btn:hover { background: #15803d; }
        </style>
    </head>
    <body>
        <div class="box">
            <h2>Analyse du fichier en cours</h2>
            <p id="status">Initialisation...</p>
            <div class="progress-bg"><div class="progress-bar" id="bar"></div></div>
            <a href="ajax_process.php?action=download&fileId=<?php echo $fileId; ?>" class="btn" id="dl-btn">Télécharger le fichier complété</a>
        </div>

        <script>
            const fileId = "<?php echo $fileId; ?>";
            const totalRows = <?php echo $totalRows; ?>;
            let currentLine = 2;

            function processNextChunk() {
                document.getElementById('status').innerText = "Traitement des participants : Ligne " + currentLine + " sur " + totalRows;
                
                fetch(`ajax_process.php?action=run&fileId=${fileId}&start=${currentLine}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            currentLine = data.next;
                            let percent = Math.min(100, Math.round((currentLine / totalRows) * 100));
                            document.getElementById('bar').style.width = percent + "%";

                            if (data.done) {
                                document.getElementById('status').innerText = "Traitement terminé avec succès !";
                                document.getElementById('dl-btn').style.display = "inline-block";
                            } else {
                                processNextChunk();
                            }
                        } else {
                            document.getElementById('status').innerText = "Erreur : " + data.message;
                        }
                    }).catch(err => {
                        document.getElementById('status').innerText = "Erreur réseau, tentative de reprise...";
                        setTimeout(processNextChunk, 2000);
                    });
            }

            // Lancement du traitement asynchrone
            processNextChunk();
        </script>
    </body>
    </html>
    <?php
    exit;
}
header('Location: index.php');
exit;