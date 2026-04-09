<?php
// ============================================================
// api/login.php — retorna perfil, config e api_config
// ============================================================
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

setCorsHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método não permitido.', 405);

$body  = getBody();
$login = trim($body['login'] ?? '');
$senha = $body['senha'] ?? '';
if (!$login || !$senha) jsonError('Login e senha são obrigatórios.');

$db   = getDB();
$stmt = $db->prepare("
    SELECT u.*, e.nome_sistema, e.slug, e.status as empresa_status, e.api_habilitada
    FROM usuarios u
    LEFT JOIN empresas e ON e.id = u.empresa_id
    WHERE u.login = ? AND u.status = 'ativo'
    LIMIT 1
");
$stmt->execute([$login]);
$user = $stmt->fetch();

if (!$user || !verificarSenha($senha, $user['senha_hash'])) {
    jsonError('Usuário ou senha inválidos.', 401);
}
if ($user['empresa_id'] && $user['empresa_status'] !== 'ativo') {
    jsonError('Empresa inativa ou suspensa.', 403);
}

$token    = generateToken();
$expiraEm = date('Y-m-d H:i:s', time() + (SESSION_HOURS * 3600));
$ip       = $_SERVER['REMOTE_ADDR'] ?? null;

$db->prepare("DELETE FROM sessoes WHERE usuario_id = ?")->execute([$user['id']]);
$db->prepare("INSERT INTO sessoes (usuario_id, token, ip, expira_em) VALUES (?,?,?,?)")
   ->execute([$user['id'], $token, $ip, $expiraEm]);

// Configuração da empresa
$config    = null;
$apiConfig = null;
if ($user['empresa_id']) {
    $s = $db->prepare("SELECT * FROM configuracoes_empresa WHERE empresa_id = ?");
    $s->execute([$user['empresa_id']]);
    $config = $s->fetch();

    // Todos os perfis com empresa_id recebem api_config
    // master_total/master_operacional: recebem usuario+senha para configurar
    // administrador/consultor: recebem apenas modo (proxy busca credenciais do banco automaticamente)
    $s2 = $db->prepare("SELECT api_usuario, api_senha, api_empresa, api_modo FROM empresas WHERE id = ?");
    $s2->execute([$user['empresa_id']]);
    $apiRaw = $s2->fetch();
    if ($apiRaw) {
        if (podeVerApi(['perfil' => $user['perfil']])) {
            $apiConfig = $apiRaw; // credenciais completas
        } else {
            $apiConfig = [
                'api_usuario' => $apiRaw['api_usuario'], // necessário para buscar token
                'api_senha'   => $apiRaw['api_senha'],   // necessário para buscar token
                'api_empresa' => $apiRaw['api_empresa'],
                'api_modo'    => $apiRaw['api_modo'],
            ];
        }
    }
}

// Para masters sem empresa, busca config da empresa padrão (slug cj2tech)
if (!$user['empresa_id'] && podeVerApi(['perfil' => $user['perfil']])) {
    // Masters podem configurar via admin
    $apiConfig = ['api_usuario' => null, 'api_senha' => null, 'api_empresa' => null, 'api_modo' => 'offline'];
}

jsonOk([
    'token'      => $token,
    'expira_em'  => $expiraEm,
    'usuario'    => [
        'id'           => (int)$user['id'],
        'nome'         => $user['nome'],
        'login'        => $user['login'],
        'perfil'       => $user['perfil'],
        'empresa_id'   => $user['empresa_id'] ? (int)$user['empresa_id'] : null,
        'nome_sistema' => $user['nome_sistema'] ?? 'CJ2Tech Sistema de Consulta',
        'slug'         => $user['slug'] ?? null,
        'pode_ver_api' => podeVerApi(['perfil' => $user['perfil']]),
        'pode_identidade' => podeEditarIdentidade(['perfil' => $user['perfil']]),
    ],
    'config'     => $config,
    'api_config' => $apiConfig,
]);