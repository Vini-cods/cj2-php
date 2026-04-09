<?php
// ============================================================
// api/financeiro.php — FASE 5: dados financeiros
// Visível apenas para master_total
// ============================================================
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

setCorsHeaders();

$user = requireAuth();
requirePerfil($user, ['master_total']);

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// GET — lista financeiro de todas as empresas
if ($method === 'GET') {
    $stmt = $db->query("
        SELECT e.id, e.nome_empresa, e.slug, e.status,
               e.limite_licencas, e.licencas_em_uso,
               e.valor_mensal, e.valor_por_licenca,
               e.valor_por_licenca_excedente,
               e.custo_por_consulta, e.saldo_creditos,
               e.vencimento_dia, e.status_cobranca,
               -- Calcular excedente
               GREATEST(0, e.licencas_em_uso - e.limite_licencas) as licencas_excedentes,
               -- Total a cobrar
               COALESCE(e.valor_mensal, 0) +
               (GREATEST(0, e.licencas_em_uso - e.limite_licencas) * COALESCE(e.valor_por_licenca_excedente, 0))
               as total_mes_calculado
        FROM empresas e
        ORDER BY e.nome_empresa
    ");
    jsonOk(['financeiro' => $stmt->fetchAll()]);
}

// PUT — atualiza dados financeiros de uma empresa
if ($method === 'PUT') {
    $id   = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$id) jsonError('ID não informado.');

    $body   = getBody();
    $campos = []; $vals = [];

    foreach (['valor_mensal','valor_por_licenca','valor_por_licenca_excedente',
              'custo_por_consulta','saldo_creditos','vencimento_dia','status_cobranca'] as $c) {
        if (array_key_exists($c, $body)) { $campos[] = "$c = ?"; $vals[] = $body[$c]; }
    }

    if (!empty($campos)) {
        $vals[] = $id;
        $db->prepare("UPDATE empresas SET " . implode(', ', $campos) . " WHERE id = ?")->execute($vals);
    }
    jsonOk(['mensagem' => 'Dados financeiros atualizados.']);
}

jsonError('Método não suportado.', 405);
