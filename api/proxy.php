<?php
// ============================================================
// api/proxy.php
// Proxy para API Promosys — substitui o server.js Node
// POST /api/proxy.php
// Body: { "endpoint": "token|beneficios|cpf|beneficio", ... }
// ============================================================

require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método não permitido.', 405);
}

// Autenticação obrigatória para todos os endpoints (exceto token)
$body     = getBody();
$endpoint = $body['endpoint'] ?? '';

if (!$endpoint) {
    jsonError('Endpoint não informado.');
}

// Para endpoint "token", não precisa de sessão (é o primeiro passo)
// Para os demais, valida sessão
if ($endpoint !== 'token') {
    $user = requireAuth();
}

// Busca credenciais da empresa
$apiUsuario = $body['api_usuario'] ?? null;
$apiSenha   = $body['api_senha']   ?? null;

// Se não veio no body, busca do banco pela empresa do usuário
if (!$apiUsuario && $endpoint !== 'token') {
    $empresaId = $user['empresa_id'] ?? null;
    if ($empresaId) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT api_usuario, api_senha, api_modo FROM empresas WHERE id = ? AND status = 'ativo'");
        $stmt->execute([$empresaId]);
        $emp = $stmt->fetch();
        if ($emp) {
            $apiUsuario = $emp['api_usuario'];
            $apiSenha   = $emp['api_senha'];
        }
    }
}

// ── Roteamento de endpoints ──────────────────────────────────
switch ($endpoint) {

    case 'token':
        $u = $body['usuario'] ?? $apiUsuario ?? '';
        $s = $body['senha']   ?? $apiSenha   ?? '';
        if (!$u || !$s) jsonError('Usuário e senha da API são obrigatórios.');
        $result = promosysPost(PROMOSYS_BASE . '/token.php', [
            'usuario' => $u,
            'senha'   => $s,
        ]);
        break;

    case 'beneficios':
        if (!isset($body['token_promosys'], $body['cpf'])) jsonError('token_promosys e cpf são obrigatórios.');
        $result = promosysPost(PROMOSYS_BASE . '/beneficios.php', [
            'token' => $body['token_promosys'],
            'cpf'   => preg_replace('/\D/', '', $body['cpf']),
        ]);
        break;

    case 'cpf':
        if (!isset($body['token_promosys'], $body['cpf'])) jsonError('token_promosys e cpf são obrigatórios.');
        $result = promosysPost(PROMOSYS_BASE . '/consultaCpfOffline.php', [
            'token' => $body['token_promosys'],
            'cpf'   => preg_replace('/\D/', '', $body['cpf']),
        ]);
        break;

    case 'beneficio':
        if (!isset($body['token_promosys'], $body['beneficio'])) jsonError('token_promosys e beneficio são obrigatórios.');
        $modo = $body['modo'] ?? 'offline';
        $url  = $modo === 'online'
            ? PROMOSYS_BASE . '/consulta.php'
            : PROMOSYS_BASE . '/consultaOffline.php';
        $result = promosysPost($url, [
            'token'    => $body['token_promosys'],
            'beneficio' => $body['beneficio'],
        ]);
        break;

    case 'health':
        jsonOk(['status' => 'ok', 'timestamp' => date('c')]);
        break;

    default:
        jsonError('Endpoint desconhecido: ' . $endpoint);
}

// ── Retorna resultado ────────────────────────────────────────
if (isset($result['erro'])) {
    jsonError($result['erro'], 502);
}
jsonOk($result);

// ── Helper: faz POST para Promosys via cURL ──────────────────
function promosysPost(string $url, array $params): array {
    $ch = curl_init();

    // Monta form data
    $postFields = http_build_query($params);

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        // Ignora verificação SSL (necessário para Promosys)
        CURLOPT_SSL_VERIFYPEER => PROMOSYS_SSL_VERIFY,
        CURLOPT_SSL_VERIFYHOST => PROMOSYS_SSL_VERIFY ? 2 : 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['erro' => 'Erro cURL: ' . $error];
    }

    if ($httpCode >= 500) {
        return ['erro' => 'Servidor Promosys retornou erro ' . $httpCode];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['erro' => 'Resposta inválida da Promosys: ' . substr($response, 0, 200)];
    }

    return $decoded;
}
