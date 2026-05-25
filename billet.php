<?php
// ════════════════════════════════════════════
// HAL — Billet du Jour · Endpoint partagé
// GET  → retourne le billet du jour (JSON)
// POST → sauvegarde un nouveau billet (protégé par mot de passe)
// ════════════════════════════════════════════

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$file = __DIR__ . '/billet.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data || !isset($data['password']) || $data['password'] !== 'hdr2012') {
        http_response_code(403);
        echo json_encode(['error' => 'Code incorrect']);
        exit;
    }

    unset($data['password']);
    $data['savedAt'] = date('c');

    if (file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false) {
        echo json_encode(['ok' => true, 'date' => $data['date'] ?? '']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Écriture impossible']);
    }

} else {
    // GET — cache désactivé
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    if (file_exists($file)) {
        echo file_get_contents($file);
    } else {
        echo json_encode(['empty' => true]);
    }
}
?>
