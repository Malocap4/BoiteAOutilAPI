<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scraper Athlé FFA</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --background: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background);
            color: var(--text);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background: var(--card);
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            width: 100%;
            max-width: 450px;
            text-align: center;
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            color: #0f172a;
        }
        p {
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 2rem;
        }
        .file-drop {
            border: 2px dashed #cbd5e1;
            padding: 2rem;
            border-radius: 8px;
            background: #f1f5f9;
            cursor: pointer;
            margin-bottom: 1.5rem;
            transition: all 0.2s ease;
        }
        .file-drop:hover {
            border-color: var(--primary);
            background: #eff6ff;
        }
        input[type="file"] {
            display: none;
        }
        button {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            width: 100%;
            transition: background 0.2s;
        }
        button:hover {
            background-color: var(--primary-hover);
        }
        .info-cols {
            margin-top: 1rem;
            font-size: 0.8rem;
            background: #fef08a;
            color: #713f12;
            padding: 0.5rem;
            border-radius: 4px;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>Scraper de Résultats FFA</h1>
    <p>Enoyez un fichier Excel pour récupérer automatiquement les ID et les résultats des athlètes.</p>
    
    <form action="traitement.php" method="post" enctype="multipart/form-data">
        <label class="file-drop" id="drop-zone">
            <span>Cliquez pour choisir le fichier Excel (.xlsx)</span>
            <input type="file" name="excel_file" accept=".xlsx" required onchange="updateFileName(this)">
        </label>
        <div id="file-name" style="margin-bottom: 15px; font-weight: bold; color: var(--primary);"></div>
        <button type="submit">Lancer la recherche et exporter</button>
    </form>

    <div class="info-cols">
        ⚠️ Colonnes requises dans le fichier : <strong>Nom</strong>, <strong>Prénom</strong>
    </div>
</div>

<script>
function updateFileName(input) {
    const fileName = input.files[0] ? input.files[0].name : "";
    document.getElementById('file-name').textContent = fileName;
}
</script>

</body>
