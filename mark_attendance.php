<?php
// Cette page traite le marquage de présence via scan QR code
require_once 'events.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Journalisation pour le débogage 
function logDebug($message) {
    file_put_contents('qr_debug.log', date('Y-m-d H:i:s') . ": " . $message . "\n", FILE_APPEND);
}

logDebug("Début du traitement de la requête");

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["qrData"])) {
    // Récupérer les données du QR code et l'ID de l'événement
    $qrData = $_POST["qrData"];
    $eventId = isset($_POST["eventId"]) ? $_POST["eventId"] : "1"; // Utiliser l'événement 1 par défaut
    
    logDebug("Données QR reçues: " . $qrData . ", Événement: " . $eventId);
    
    // Décoder les données JSON du QR code avec gestion d'erreur
    try {
        $userData = json_decode($qrData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erreur de décodage JSON: " . json_last_error_msg());
        }
        
        logDebug("Données JSON décodées: " . print_r($userData, true));
        
        if (!$userData || !isset($userData["email"])) {
            throw new Exception("Format QR invalide: email manquant");
        }
        
        $email = $userData["email"];
        logDebug("Email extrait: " . $email);
        
        // Vérifier que l'événement existe
        $event = getEventById($eventId);
        if ($event === null) {
            throw new Exception("Événement non trouvé: " . $eventId);
        }
        
        $eventName = $event[1];
        logDebug("Événement trouvé: " . $eventName);
        
        // Lire les données existantes pour vérifier si l'utilisateur existe
        $dataFile = "database/data.csv";
        
        if (!file_exists($dataFile)) {
            throw new Exception("Fichier de données non trouvé: " . $dataFile);
        }
        
        $found = false;
        $userData = null;
        
        $file = fopen($dataFile, "r");
        if (!$file) {
            throw new Exception("Impossible d'ouvrir le fichier de données: " . $dataFile);
        }
        
        logDebug("Recherche de l'utilisateur avec email: " . $email);
        
        while (($data = fgetcsv($file)) !== false) {
            // Email est à l'index 5 dans la structure CSV
            if (isset($data[5]) && $data[5] === $email) {
                $found = true;
                $userData = $data;
                logDebug("Utilisateur trouvé: " . print_r($userData, true));
                break;
            }
        }
        fclose($file);
        
        if (!$found) {
            throw new Exception("Participant non trouvé avec cet email: " . $email);
        }
        
        // Créer ou mettre à jour le fichier des présences
        $presenceFolder = "presence";
        if (!file_exists($presenceFolder)) {
            mkdir($presenceFolder, 0777, true);
        }
        
        $attendanceFile = $presenceFolder . "/attendance.csv";
        $today = date("Y-m-d");
        $now = date("H:i:s");
        
        // Initialiser les variables pour stocker les données d'origine
        $alreadyPresent = false;
        $originalEntry = null;
        $updatedAttendance = [];
        
        logDebug("Vérification de présence existante pour la date: " . $today . " et l'événement: " . $eventId);
        
        if (file_exists($attendanceFile)) {
            $attFile = fopen($attendanceFile, "r");
            if (!$attFile) {
                throw new Exception("Impossible d'ouvrir le fichier des présences: " . $attendanceFile);
            }
            
            while (($attData = fgetcsv($attFile)) !== false) {
                // Si même email, même date et même événement, mémoriser l'entrée originale
                if (isset($attData[0]) && $attData[0] === $email && 
                    isset($attData[1]) && $attData[1] === $today &&
                    isset($attData[7]) && $attData[7] === $eventId) {
                    $alreadyPresent = true;
                    $originalEntry = $attData;
                    logDebug("Présence existante trouvée: " . print_r($attData, true));
                    // Ne pas ajouter cette ligne à updatedAttendance car on va la remplacer
                } else {
                    // Conserver toutes les autres lignes
                    $updatedAttendance[] = $attData;
                }
            }
            fclose($attFile);
        } else {
            logDebug("Création d'un nouveau fichier de présence: " . $attendanceFile);
        }
        
        // Préparer la nouvelle entrée avec la date et l'heure actuelles
        $newEntry = [
            $email,                 // Email (identifiant unique)
            $today,                 // Date
            $now,                   // Heure
            $userData[0],           // Nom
            $userData[1],           // Prénom
            $userData[2],           // Organisme
            $userData[3],           // Fonction
            $eventId                // ID de l'événement
        ];
        
        logDebug("Nouvelle entrée à enregistrer: " . print_r($newEntry, true));
        
        // Ajouter la nouvelle entrée aux données mises à jour
        $updatedAttendance[] = $newEntry;
        
        // Réécrire tout le fichier avec la nouvelle entrée
        $attFile = fopen($attendanceFile, "w");
        if (!$attFile) {
            throw new Exception("Impossible d'écrire dans le fichier des présences: " . $attendanceFile);
        }
        
        foreach ($updatedAttendance as $row) {
            fputcsv($attFile, $row);
        }
        fclose($attFile);
        
        logDebug("Données de présence mises à jour avec succès");
        
        // Récupérer l'ID du participant pour le lien QR
        $participantId = isset($userData[11]) ? $userData[11] : null;
        
        // Préparer la réponse avec lien vers le QR code du participant
        if ($alreadyPresent) {
            $message = "<div class='success-message'>Présence mise à jour pour " . $userData[1] . " " . $userData[0] . " à " . $now . " !</div>";
        } else {
            $message = "<div class='success-message'>Présence enregistrée pour " . $userData[1] . " " . $userData[0] . " à " . $now . " !</div>";
        }
        
        $message .= "<div class='event-info'>Événement : " . $eventName . "</div>";
        
        if ($participantId) {
            $message .= "<div class='qr-link-container'><a href='view_participant_qr.php?id=" . $participantId . "' target='_blank' class='qr-link'>Voir mon QR code</a></div>";
        }
        
        echo $message;
        
    } catch (Exception $e) {
        logDebug("ERREUR: " . $e->getMessage());
        echo "<div class='error-message'>Erreur lors de la vérification des données: " . $e->getMessage() . "</div>";
    }
} else {
    logDebug("Requête invalide. POST variables: " . print_r($_POST, true));
    echo "<div class='error-message'>Requête invalide. Assurez-vous que le code QR est correctement scanné.</div>";
}
?>