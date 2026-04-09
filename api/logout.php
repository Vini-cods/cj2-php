<?php
// ============================================================
// api/logout.php
// POST /api/logout.php — invalida sessão
// ============================================================

require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método não permitido.', 405);
}

$token = getBearerToken();
if ($token) {
    $db = getDB();
    $db->prepare("DELETE FROM sessoes WHERE token = ?")->execute([$token]);
}

jsonOk(['mensagem' => 'Logout realizado com sucesso.']);
