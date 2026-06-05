<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="./content/img/Logo.png"sizes="32x32">
  <title>Boite à Outils</title>
  <style>
    body {
      margin: 0;
      font-family: 'Montserrat', sans-serif;
      background: 
        linear-gradient(rgba(55, 131, 159, 0.686), rgba(55, 131, 159, 0.686)),
        url('./content/img/Fond.png') center/cover no-repeat fixed;
      padding: 40px 20px;
    }

    h1 {
      text-align: center;
      font-size: 2.5rem;
      color: #ffffff;
      margin-bottom: 40px;
      text-shadow: 1px 1px 4px rgba(0, 0, 0, 0.4);
    }

    .container {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 25px;
      max-width: 1000px;
      margin: 0 auto;
    }

    .box {
      text-align: center;
      padding: 30px 20px;
      font-size: 1.3rem;
      font-weight: 600;
      border-radius: 20px;
      text-decoration: none;
      color: white;
      box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
      transition: transform 0.2s, box-shadow 0.2s;
      backdrop-filter: blur(2px);
    }

    .box:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
    }

    .green {
      background-color: #4b8cd1;
    }

    .gray {
      background-color: #757575;
    }

    @media (max-width: 500px) {
      h1 {
        font-size: 1.8rem;
      }

      .box {
        font-size: 1.1rem;
        padding: 20px;
      }
    }
  </style>
  
</head>
<script>
function launchExe(path) {
  // On envoie la requête sans attendre de confirmation complexe
  fetch('/launch/' + encodeURIComponent(path), { mode: 'no-cors' });
  // Optionnel : ajouter un petit feedback visuel immédiat sur le bouton
  console.log("Commande envoyée...");
}
</script>
<body>

  <h1>La Boite à Outil API</h1>
  <div class="container">
    <a href="./content/ResultsFFA/index.php" class="box blue">Profil de Dénivelé</a>
  </div>
  <div class="container">
    <a href="./content/ResultsFFAsynch/index.php" class="box blue">Profil de Dénivelé</a>
  </div>
</body>

    
</html>
