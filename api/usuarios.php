<?php
// ============================================================
// api/usuarios.php — FASE 1: controle de perfis e licenças
// ============================================================
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

setCorsHeaders();

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$db     = getDB();

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    requirePerfil($user, ['master_total','master_operacional','administrador']);

    $sql  = "SELECT u.id, u.nome, u.login, u.perfil, u.status, u.empresa_id, u.created_at, e.nome_empresa
             FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id";
    $vals = [];

    // Escopo de empresa
    if (in_array($user['perfil'], ['administrador'])) {
        $sql   .= " WHERE u.empresa_id = ?";
        $vals[] = $user['empresa_id'];
    } elseif (isset($_GET['empresa_id'])) {
        $sql   .= " WHERE u.empresa_id = ?";
        $vals[] = (int)$_GET['empresa_id'];
    }

    // master_operacional não vê masters
    if ($user['perfil'] === 'master_operacional') {
        $sql .= (empty($vals) ? ' WHERE' : ' AND') . " u.perfil NOT IN ('master_total','master_operacional')";
    }

    $sql .= " ORDER BY u.nome";
    $stmt = $db->prepare($sql);
    $stmt->execute($vals);
    jsonOk(['usuarios' => $stmt->fetchAll()]);
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    requirePerfil($user, ['master_total','master_operacional','administrador']);

    $body      = getBody();
    $nome      = trim($body['nome']  ?? '');
    $login     = trim($body['login'] ?? '');
    $senha     = $body['senha']      ?? '';
    $perfil    = $body['perfil']     ?? 'consultor';
    $empresaId = isset($body['empresa_id']) ? (int)$body['empresa_id'] : null;

    if (!$nome || !$login || !$senha) jsonError('nome, login e senha são obrigatórios.');
    if (strlen($senha) < 6) jsonError('Senha deve ter ao menos 6 caracteres.');

    // Valida perfil que pode ser criado
    $permitidos = getPerfisPermitidosCriacao($user['perfil']);
    if (!in_array($perfil, $permitidos)) {
        jsonError("Perfil '$perfil' não pode ser criado por '{$user['perfil']}'.");
    }

    // administrador só cria na própria empresa
    if ($user['perfil'] === 'administrador') {
        $empresaId = (int)$user['empresa_id'];
    }

    // Verifica limite de licenças
    if ($empresaId && $perfil === 'consultor') {
        $lim = $db->prepare("SELECT limite_licencas, licencas_em_uso FROM empresas WHERE id = ?");
        $lim->execute([$empresaId]);
        $l = $lim->fetch();
        if ($l && $l['licencas_em_uso'] >= $l['limite_licencas']) {
            jsonError('Limite de licenças atingido para esta empresa.');
        }
    }

    $check = $db->prepare("SELECT id FROM usuarios WHERE login = ?");
    $check->execute([$login]);
    if ($check->fetch()) jsonError('Login já em uso.');

    $db->prepare("INSERT INTO usuarios (empresa_id,nome,login,senha_hash,perfil,status) VALUES (?,?,?,?,'$perfil','ativo')")
       ->execute([$empresaId, $nome, $login, hashSenha($senha)]);

    $novoId = (int)$db->lastInsertId();
    if ($empresaId && $perfil === 'consultor') {
        $db->prepare("UPDATE empresas SET licencas_em_uso = licencas_em_uso + 1 WHERE id = ?")->execute([$empresaId]);
    }

    jsonOk(['mensagem' => 'Usuário criado.', 'id' => $novoId], 201);
}

// ── PUT ───────────────────────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) jsonError('ID não informado.');
    requirePerfil($user, ['master_total','master_operacional','administrador']);

    $alvoStmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $alvoStmt->execute([$id]);
    $alvo = $alvoStmt->fetch();
    if (!$alvo) jsonError('Usuário não encontrado.', 404);

    if ($user['perfil'] === 'administrador' && (int)$alvo['empresa_id'] !== (int)$user['empresa_id']) {
        jsonError('Acesso negado.', 403);
    }

    $body   = getBody();
    $campos = []; $vals = [];

    foreach (['nome','login','status','perfil'] as $c) {
        if (isset($body[$c])) { $campos[] = "$c = ?"; $vals[] = $body[$c]; }
    }
    if (!empty($body['senha']) && strlen($body['senha']) >= 6) {
        $campos[] = 'senha_hash = ?'; $vals[] = hashSenha($body['senha']);
    }

    if (!empty($campos)) {
        $vals[] = $id;
        $db->prepare("UPDATE usuarios SET " . implode(', ', $campos) . " WHERE id = ?")->execute($vals);
    }
    jsonOk(['mensagem' => 'Usuário atualizado.']);
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) jsonError('ID não informado.');
    requirePerfil($user, ['master_total','master_operacional','administrador']);
    if ($id === (int)$user['usuario_id']) jsonError('Você não pode desativar sua própria conta.');
    $db->prepare("UPDATE usuarios SET status = 'inativo' WHERE id = ?")->execute([$id]);
    jsonOk(['mensagem' => 'Usuário desativado.']);
}

jsonError('Método não suportado.', 405);
