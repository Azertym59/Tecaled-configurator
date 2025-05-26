<?php
// Configuration
error_reporting(E_ALL);
ini_set('display_errors', 0); // Désactiver pour la production

// Headers CORS et JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

// Gérer les requêtes OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Méthode non autorisée. Utilisez POST.'
    ]);
    exit();
}

try {
    // Lire les données JSON du formulaire
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception('Données JSON invalides');
    }

    // Générer un ID de projet unique
    $project_id = 'TECALED_' . date('Y') . '_' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

    // Enrichir les données
    $enriched_data = [
        'project_id' => $project_id,
        'received_at' => date('Y-m-d H:i:s'),
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'form_data' => $data['formData'] ?? $data,
        'timestamp' => $data['timestamp'] ?? date('c')
    ];

    // Créer le dossier logs s'il n'existe pas
    if (!file_exists('logs')) {
        mkdir('logs', 0755, true);
        // Créer .htaccess pour protéger les logs
        file_put_contents('logs/.htaccess', "Deny from all\n");
    }

    // Logger la demande (pour debug et backup)
    $log_entry = date('Y-m-d H:i:s') . " - " . $project_id . " - " . json_encode($enriched_data, JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents('logs/form_submissions.log', $log_entry, FILE_APPEND | LOCK_EX);

    // URL de ton webhook N8N - REMPLACE CETTE URL PAR LA VRAIE !
    $n8n_webhook_url = 'https://YOUR_N8N_DOMAIN.com/webhook/led-configurator';
    
    // Pour tester sans N8N, décommente les lignes suivantes :
    /*
    echo json_encode([
        'success' => true,
        'message' => 'Demande reçue et enregistrée (mode test)',
        'project_id' => $project_id,
        'debug' => 'N8N non configuré - données sauvées en local'
    ]);
    exit();
    */

    // Envoyer les données vers N8N
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $n8n_webhook_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($enriched_data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: TECALED-Configurator/1.0',
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Gérer la réponse de N8N
    if ($curl_error) {
        throw new Exception('Erreur de connexion N8N: ' . $curl_error);
    }

    if ($http_code >= 200 && $http_code < 300) {
        // Succès - Envoyer confirmation au client
        echo json_encode([
            'success' => true,
            'message' => 'Votre demande a été envoyée avec succès !',
            'project_id' => $project_id,
            'next_steps' => 'Nous vous contacterons sous 24h avec une proposition détaillée.'
        ]);
    } else {
        throw new Exception('Erreur N8N (HTTP ' . $http_code . '): ' . $response);
    }

} catch (Exception $e) {
    // En cas d'erreur, on sauvegarde quand même localement
    error_log('TECALED Webhook Error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur lors du traitement de votre demande',
        'message' => 'Nous avons sauvegardé votre demande. Veuillez nous contacter directement.',
        'debug' => $e->getMessage() // Retirer en production
    ]);
}
?>