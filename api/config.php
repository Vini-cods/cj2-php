<?php
// ============================================================
// api/config.php
// GET  /api/config.php?slug=X   — por slug (mantido)
// GET  /api/config.php          — por HTTP_HOST (novo)
// PUT  /api/config.php          — salva config (requer auth)
//
// Prioridade de identificação:
//   1. ?slug=X ou ?empresa=X  → comportamento atual (prioridade máxima)
//   2. HTTP_HOST               → detecção automática por domínio
//   3. fallback padrão         → erro controlado
// ============================================================

// Captura QUALQUER saída indevida (erro PHP, warning, etc.) para garantir
// que a resposta seja sempre JSON puro — nunca HTML.
ob_start();

require_once __DIR__ . '/../includes/response.php';

// Handler global: converte erros fatais e exceções não tratadas em JSON
set_exception_handler(function (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'erro' => 'Erro interno do servidor: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['erro' => 'Erro fatal PHP: ' . $err['message']], JSON_UNESCAPED_UNICODE);
    }
});
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ════════════════════════════════════════════════════════════
// GET
// ════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // ── 1. ?slug ou ?empresa (comportamento atual — prioridade máxima)
    $slug = trim($_GET['slug'] ?? $_GET['empresa'] ?? '');

    if ($slug) {
        $config = buscarPorSlug($db, $slug);
        if (!$config) jsonError('Empresa nao encontrada ou inativa.', 404);
        jsonOk(['config' => $config, 'identificado_por' => 'slug']);
    }

    // ── 2. Detecta pelo HTTP_HOST (novo comportamento)
    $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host); // remove porta

    if ($host) {
        $config = buscarPorDominio($db, $host);
        if ($config) {
            jsonOk(['config' => $config, 'identificado_por' => 'dominio']);
        }
        // Domínio não mapeado — erro controlado
        jsonError(
            'Empresa nao identificada para o dominio: ' . htmlspecialchars($host) .
                '. Use ?empresa=slug para acessar diretamente.',
            404
        );
    }

    // ── 3. Sem host e sem slug — não deveria chegar aqui
    jsonError('Nao foi possivel identificar a empresa.', 400);
}

// ════════════════════════════════════════════════════════════
// PUT
// ════════════════════════════════════════════════════════════
if ($method === 'PUT') {
    try {
        $user = requireAuth();
        requirePerfil($user, ['master_total', 'master_operacional', 'administrador']);

        $body      = getBody();
        $empresaId = isset($body['empresa_id']) ? (int)$body['empresa_id'] : (int)$user['empresa_id'];

        if (!$empresaId) jsonError('empresa_id nao informado.');
        requireEmpresa($user, $empresaId);

        $campos = [];
        $vals   = [];

        $cfgEditaveis = ['titulo_login', 'subtitulo_login', 'chip_1', 'chip_2', 'chip_3', 'tema_padrao'];

        // Identidade: apenas administrador e master_total
        if (podeEditarIdentidade($user)) {
            $cfgEditaveis = array_merge($cfgEditaveis, ['logo_dark', 'logo_light', 'favicon', 'imagem_capa']);
            // imagem_fundo é alias de imagem_capa
            if (array_key_exists('imagem_fundo', $body)) {
                $body['imagem_capa'] = $body['imagem_fundo'];
            }
        }

        // Validar tamanho de imagens (base64: 2 MB limite = ~1.5 MB real)
        $camposImagem = ['logo_dark', 'logo_light', 'favicon', 'imagem_capa'];
        $maxBase64    = 2 * 1024 * 1024; // 2 MB em caracteres base64
        foreach ($camposImagem as $ci) {
            if (!empty($body[$ci]) && strlen($body[$ci]) > $maxBase64) {
                ob_end_clean();
                jsonError("Imagem '$ci' muito grande. Maximo 1.5 MB. Reduza a resolucao e tente novamente.", 413);
            }
        }

        foreach ($cfgEditaveis as $campo) {
            if (array_key_exists($campo, $body)) {
                $campos[] = "$campo = ?";
                $vals[]   = $body[$campo];
            }
        }

        if (array_key_exists('nome_sistema', $body)) {
            $db->prepare("UPDATE empresas SET nome_sistema = ? WHERE id = ?")
                ->execute([$body['nome_sistema'], $empresaId]);
        }

        if (!empty($campos)) {
            $vals[] = $empresaId;
            $exists = $db->prepare("SELECT id FROM configuracoes_empresa WHERE empresa_id = ?");
            $exists->execute([$empresaId]);
            if ($exists->fetch()) {
                $db->prepare("UPDATE configuracoes_empresa SET " . implode(', ', $campos) . " WHERE empresa_id = ?")
                    ->execute($vals);
            } else {
                $db->prepare("INSERT INTO configuracoes_empresa (empresa_id) VALUES (?)")->execute([$empresaId]);
                $db->prepare("UPDATE configuracoes_empresa SET " . implode(', ', $campos) . " WHERE empresa_id = ?")
                    ->execute($vals);
            }
        }

        ob_end_clean();
        jsonOk(['mensagem' => 'Configuracoes salvas com sucesso.']);
    } catch (PDOException $e) {
        ob_end_clean();
        $msg = $e->getMessage();
        if (stripos($msg, 'Data too long') !== false) {
            jsonError('Imagem muito grande para o banco de dados. Execute o SQL de migracao MEDIUMTEXT e tente novamente.', 413);
        }
        jsonError('Erro no banco de dados: ' . $msg, 500);
    } catch (Throwable $e) {
        ob_end_clean();
        jsonError('Erro interno: ' . $e->getMessage(), 500);
    }
}

ob_end_clean();
jsonError('Metodo nao suportado.', 405);


// ════════════════════════════════════════════════════════════
// FUNÇÕES DE BUSCA
// ════════════════════════════════════════════════════════════

/**
 * Busca empresa por slug (comportamento original)
 */
function buscarPorSlug(PDO $db, string $slug): ?array
{
    $stmt = $db->prepare("
        SELECT e.nome_sistema, e.slug, e.tipo_dominio,
               e.subdominio, e.dominio_personalizado, e.status,
               c.titulo_login, c.subtitulo_login,
               c.chip_1, c.chip_2, c.chip_3,
               c.logo_dark, c.logo_light, c.favicon,
               c.imagem_capa as imagem_fundo,
               c.tema_padrao
        FROM empresas e
        LEFT JOIN configuracoes_empresa c ON c.empresa_id = e.id
        WHERE e.slug = ? AND e.status = 'ativo'
        LIMIT 1
    ");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Busca empresa pelo HTTP_HOST
 *
 * Ordem de prioridade:
 *   1. dominio_personalizado exato     → sistema.cliente.com.br
 *   2. subdominio + dominio base CJ2   → empresa.cj2tech.com.br
 *   3. host começa com subdominio      → empresa.qualquerdominio.com
 */
function buscarPorDominio(PDO $db, string $host): ?array
{
    // Domínio base CJ2 — configure em includes/config.php se necessário
    $base = defined('CJ2_DOMINIO_BASE') ? CJ2_DOMINIO_BASE : 'cj2tech.com.br';

    $stmt = $db->prepare("
        SELECT e.nome_sistema, e.slug, e.tipo_dominio,
               e.subdominio, e.dominio_personalizado, e.status,
               c.titulo_login, c.subtitulo_login,
               c.chip_1, c.chip_2, c.chip_3,
               c.logo_dark, c.logo_light, c.favicon,
               c.imagem_capa as imagem_fundo,
               c.tema_padrao
        FROM empresas e
        LEFT JOIN configuracoes_empresa c ON c.empresa_id = e.id
        WHERE e.status = 'ativo'
          AND (
            (e.tipo_dominio = 'proprio'
             AND e.dominio_personalizado IS NOT NULL
             AND LOWER(e.dominio_personalizado) = ?)
            OR
            (e.tipo_dominio = 'cj2'
             AND e.subdominio IS NOT NULL
             AND LOWER(CONCAT(e.subdominio, '.', ?)) = ?)
            OR
            (e.subdominio IS NOT NULL
             AND ? LIKE CONCAT(LOWER(e.subdominio), '.%'))
          )
        LIMIT 1
    ");
    $stmt->execute([$host, $base, $host, $host]);
    $row = $stmt->fetch();
    return $row ?: null;
}
