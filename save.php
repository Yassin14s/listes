<?php
require_once 'events.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $date_inscription = date("Y-m-d");
    $heure_inscription = date("H:i:s");
    $eventId = isset($_POST["eventId"]) ? $_POST["eventId"] : "1"; // Utiliser l'événement 1 par défaut si non spécifié
    
    // Si l'événement spécifié n'existe pas, utiliser l'événement 1
    if (getEventById($eventId) === null) {
        $eventId = "1";
    }
    
    $nom = $_POST["nom"];
    $prenom = $_POST["prenom"];
    $email = $_POST["email"];
    $genre = isset($_POST["genre"]) ? $_POST["genre"] : ""; // Récupérer le genre
    
    // Vérifier si l'utilisateur existe déjà dans la base de données
    $dbFolder = "database";
    $dataFile = $dbFolder . "/data.csv";
    $emailExists = false;
    
    if (file_exists($dataFile)) {
        $file = fopen($dataFile, "r");
        while (($data = fgetcsv($file)) !== false) {
            if (isset($data[5]) && $data[5] === $email) {
                $emailExists = true;
                $participantId = $data[11]; // Récupérer l'ID du participant existant
                break;
            }
        }
        fclose($file);
    }
    
    if ($emailExists) {
        // L'utilisateur existe déjà, on ne l'enregistre pas à nouveau, mais on peut mettre à jour sa présence
        $eventName = getEventById($eventId)[1];
        $qrUrl = "view_participant_qr.php?id=" . $participantId;
        
        // Mettre à jour sa présence pour l'événement actuel
        markAttendance($email, $eventId);
        
        echo "<div class='success-message'>Vous êtes déjà inscrit(e). Votre présence a été mise à jour pour l'événement : " . $eventName . "</div>";
        echo "<div style='margin-top: 15px;'><a href='" . $qrUrl . "' target='_blank' style='background-color: #4CAF50; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>Voir mon QR code</a></div>";
        exit;
    }
    
    // Générer un identifiant unique pour ce participant
    $participantId = uniqid('p_');
    
    // 1. ENREGISTREMENT DANS LA BASE DE DONNÉES
    // Structure du fichier: data.csv - Base de données principale
    $data = [
        $nom,                   // Nom
        $prenom,                // Prénom
        $_POST["organisme"],    // Organisme
        $_POST["fonction"],     // Fonction
        $_POST["portable"],     // Téléphone
        $email,                 // Email
        $_POST["age"],          // Âge
        $date_inscription,      // Date d'inscription
        $heure_inscription,     // Heure d'inscription
        $_POST["signature"],    // Signature
        $eventId,               // ID de l'événement d'inscription
        $participantId,         // ID unique du participant
        $genre                  // Genre (H/F)
    ];

    // Créer le dossier de base de données s'il n'existe pas
    if (!file_exists($dbFolder)) {
        mkdir($dbFolder, 0777, true);
    }
    
    // Sauvegarder dans le fichier principal de la base de données
    $file = fopen($dataFile, "a");
    fputcsv($file, $data);
    fclose($file);
    
    // 2. CRÉATION ET STOCKAGE DU QR CODE
    // Créer les données du QR code
    $qrData = json_encode([
        "id" => $participantId,
        "nom" => $nom,
        "prenom" => $prenom,
        "email" => $email,
        "genre" => $genre // Ajouter le genre dans les données du QR code
    ]);
    
    // Sauvegarder le QR code dans un fichier
    $qrFolder = "qr_codes";
    if (!file_exists($qrFolder)) {
        mkdir($qrFolder, 0777, true);
    }
    
    // Sauvegarder les données QR au format JSON
    $qrFilename = $qrFolder . "/" . $participantId . ".json";
    file_put_contents($qrFilename, $qrData);
    
    // 3. ENREGISTREMENT DE LA PRÉSENCE
    markAttendance($email, $eventId);
    
    // Obtenir le nom de l'événement
    $event = getEventById($eventId);
    $eventName = $event[1];
    
    // Créer l'URL pour afficher le QR code
    $qrUrl = "view_participant_qr.php?id=" . $participantId;
    
    // Réponse avec lien vers le QR code
    echo "<div class='success-message'>Inscription enregistrée avec succès pour l'événement : " . $eventName . "</div>";
    echo "<div style='margin-top: 15px;'><a href='" . $qrUrl . "' target='_blank' style='background-color: #4CAF50; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>Voir mon QR code</a></div>";
}

// Fonction pour marquer la présence
function markAttendance($email, $eventId) {
    $dataFile = "database/data.csv";
    $found = false;
    $userData = null;
    
    // Rechercher l'utilisateur par son email
    if (file_exists($dataFile)) {
        $file = fopen($dataFile, "r");
        
        while (($data = fgetcsv($file)) !== false) {
            if (isset($data[5]) && $data[5] === $email) {
                $found = true;
                $userData = $data;
                break;
            }
        }
        fclose($file);
    }
    
    if ($found) {
        $presenceFolder = "presence";
        if (!file_exists($presenceFolder)) {
            mkdir($presenceFolder, 0777, true);
        }
        
        $attendanceFile = $presenceFolder . "/attendance.csv";
        $today = date("Y-m-d");
        $now = date("H:i:s");
        
        // Préparer l'entrée de présence
        $attendanceEntry = [
            $email,             // Email (identifiant unique)
            $today,             // Date
            $now,               // Heure
            $userData[0],       // Nom
            $userData[1],       // Prénom
            $userData[2],       // Organisme
            $userData[3],       // Fonction
            $eventId,           // ID de l'événement
            isset($userData[12]) ? $userData[12] : "" // Genre (si disponible)
        ];
        
        $file = fopen($attendanceFile, "a");
        fputcsv($file, $attendanceEntry);
        fclose($file);
        
        return true;
    }
    
    return false;
}
?>