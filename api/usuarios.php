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
    // Busca usuário específico com detalhes (login/senha visível)
    if ($id) {
        // Consultores não podem ver detalhes de outros usuários
        if ($user['perfil'] === 'consultor') jsonError('Acesso negado.', 403);

        $stmt = $db->prepare("SELECT u.id, u.nome, u.login, u.senha_hash, u.senha_texto, u.perfil, u.status, u.empresa_id, u.created_at, e.nome_empresa FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id WHERE u.id = ?");
        $stmt->execute([$id]);
        $alvo = $stmt->fetch();
        if (!$alvo) jsonError('Usuário não encontrado.', 404);

        // Verifica permissão para ver este usuário
        $podeVer = false;
        switch ($user['perfil']) {
            case 'master_total':
                $podeVer = true;
                break;
            case 'master_operacional':
                $podeVer = ($alvo['perfil'] !== 'master_total');
                break;
            case 'administrador':
                $podeVer = ((int)$alvo['empresa_id'] === (int)$user['empresa_id'] &&
                    $alvo['perfil'] !== 'master_total' &&
                    $alvo['perfil'] !== 'master_operacional');
                break;
        }
        if (!$podeVer) jsonError('Acesso negado.', 403);

        // Retorna dados sem expor senha_hash diretamente
        // senha_texto é a senha em texto simples guardada para suporte admin
        $alvo['tem_senha'] = !empty($alvo['senha_hash']);
        $alvo['senha_texto'] = $alvo['senha_texto'] ?? null; // null se não foi salva
        unset($alvo['senha_hash']);
        jsonOk(['usuario' => $alvo]);
    }

    $sql  = "SELECT u.id, u.nome, u.login, u.perfil, u.status, u.empresa_id, u.created_at, e.nome_empresa
             FROM usuarios u 
             LEFT JOIN empresas e ON e.id = u.empresa_id";
    $vals = [];

    switch ($user['perfil']) {
        case 'master_total':
            if (isset($_GET['empresa_id'])) {
                $sql   .= " WHERE u.empresa_id = ?";
                $vals[] = (int)$_GET['empresa_id'];
            }
            break;

        case 'master_operacional':
            $sql   .= " WHERE u.perfil NOT IN ('master_total', 'master_operacional')";
            if (isset($_GET['empresa_id'])) {
                $sql   .= " AND u.empresa_id = ?";
                $vals[] = (int)$_GET['empresa_id'];
            }
            break;

        case 'administrador':
            $sql   .= " WHERE u.empresa_id = ? AND u.perfil NOT IN ('master_total', 'master_operacional')";
            $vals[] = (int)$user['empresa_id'];
            break;

        case 'consultor':
            jsonOk(['usuarios' => []]);
            break;
    }

    $sql .= " ORDER BY u.nome";
    $stmt = $db->prepare($sql);
    $stmt->execute($vals);
    jsonOk(['usuarios' => $stmt->fetchAll()]);
}

// ── POST ───────────────────────────────────────────────────────
if ($method === 'POST') {
    $body      = getBody();
    $nome      = trim($body['nome']  ?? '');
    $login     = trim($body['login'] ?? '');
    $senha     = $body['senha']      ?? '';
    $perfil    = $body['perfil']     ?? 'consultor';
    $empresaId = isset($body['empresa_id']) ? (int)$body['empresa_id'] : null;

    if (!$nome || !$login || !$senha) jsonError('nome, login e senha são obrigatórios.');
    if (strlen($senha) < 6) jsonError('Senha deve ter ao menos 6 caracteres.');

    $permitidos = getPerfisPermitidosCriacao($user['perfil']);
    if (!in_array($perfil, $permitidos)) {
        jsonError("Perfil '$perfil' não pode ser criado por '{$user['perfil']}'.");
    }

    if ($user['perfil'] === 'administrador') {
        $empresaId = (int)$user['empresa_id'];
    }

    if ($user['perfil'] === 'master_operacional' && $perfil === 'master_total') {
        jsonError('Master Operacional não pode criar Master Total.');
    }

    if ($user['perfil'] === 'master_operacional' && $perfil === 'master_operacional') {
        jsonError('Master Operacional não pode criar outro Master Operacional.');
    }

    if ($empresaId) {
        $checkEmp = $db->prepare("SELECT id, limite_licencas, licencas_em_uso FROM empresas WHERE id = ? AND status = 'ativo'");
        $checkEmp->execute([$empresaId]);
        $empresa = $checkEmp->fetch();
        if (!$empresa) jsonError('Empresa não encontrada ou inativa.');

        if ($perfil === 'consultor' && $empresa['licencas_em_uso'] >= $empresa['limite_licencas']) {
            jsonError('Limite de licenças atingido para esta empresa.');
        }
    }

    $check = $db->prepare("SELECT id FROM usuarios WHERE login = ?");
    $check->execute([$login]);
    if ($check->fetch()) jsonError('Login já em uso.');

    $db->prepare("INSERT INTO usuarios (empresa_id, nome, login, senha_hash, senha_texto, perfil, status) 
                  VALUES (?, ?, ?, ?, ?, ?, 'ativo')")
        ->execute([$empresaId, $nome, $login, hashSenha($senha), $senha, $perfil]);

    $novoId = (int)$db->lastInsertId();

    if ($empresaId && $perfil === 'consultor') {
        $db->prepare("UPDATE empresas SET licencas_em_uso = licencas_em_uso + 1 WHERE id = ?")
            ->execute([$empresaId]);
    }

    jsonOk(['mensagem' => 'Usuário criado com sucesso.', 'id' => $novoId], 201);
}

// ── PUT ───────────────────────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) jsonError('ID não informado.');

    $alvoStmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $alvoStmt->execute([$id]);
    $alvo = $alvoStmt->fetch();
    if (!$alvo) jsonError('Usuário não encontrado.', 404);

    $podeEditar = false;

    switch ($user['perfil']) {
        case 'master_total':
            $podeEditar = true;
            break;
        case 'master_operacional':
            if ($alvo['perfil'] !== 'master_total') $podeEditar = true;
            break;
        case 'administrador':
            if (
                (int)$alvo['empresa_id'] === (int)$user['empresa_id'] &&
                $alvo['perfil'] !== 'master_total' &&
                $alvo['perfil'] !== 'master_operacional'
            ) {
                $podeEditar = true;
            }
            break;
        default:
            $podeEditar = false;
    }

    if (!$podeEditar) jsonError('Acesso negado para editar este usuário.', 403);

    $body   = getBody();
    $campos = [];
    $vals   = [];

    $editaveis = ['nome', 'login', 'status'];

    if ($user['perfil'] === 'master_total' && isset($body['perfil'])) {
        $permitidos = getPerfisPermitidosCriacao($user['perfil']);
        if (in_array($body['perfil'], $permitidos)) {
            $editaveis[] = 'perfil';
        }
    }

    foreach ($editaveis as $c) {
        if (isset($body[$c])) {
            $campos[] = "$c = ?";
            $vals[] = $body[$c];
        }
    }

    if (!empty($body['senha']) && strlen($body['senha']) >= 6) {
        $campos[] = 'senha_hash = ?';
        $vals[] = hashSenha($body['senha']);
        $campos[] = 'senha_texto = ?';
        $vals[] = $body['senha'];
    }

    if (!empty($campos)) {
        $vals[] = $id;
        $db->prepare("UPDATE usuarios SET " . implode(', ', $campos) . " WHERE id = ?")
            ->execute($vals);

        if (isset($body['status']) && $body['status'] === 'inativo' && $alvo['perfil'] === 'consultor' && $alvo['empresa_id']) {
            $db->prepare("UPDATE empresas SET licencas_em_uso = GREATEST(0, licencas_em_uso - 1) WHERE id = ?")
                ->execute([$alvo['empresa_id']]);
        } elseif (isset($body['status']) && $body['status'] === 'ativo' && $alvo['perfil'] === 'consultor' && $alvo['empresa_id']) {
            $checkLimite = $db->prepare("SELECT limite_licencas, licencas_em_uso FROM empresas WHERE id = ?");
            $checkLimite->execute([$alvo['empresa_id']]);
            $emp = $checkLimite->fetch();
            if ($emp && $emp['licencas_em_uso'] < $emp['limite_licencas']) {
                $db->prepare("UPDATE empresas SET licencas_em_uso = licencas_em_uso + 1 WHERE id = ?")
                    ->execute([$alvo['empresa_id']]);
            }
        }
    }

    jsonOk(['mensagem' => 'Usuário atualizado com sucesso.']);
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) jsonError('ID não informado.');

    $alvoStmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $alvoStmt->execute([$id]);
    $alvo = $alvoStmt->fetch();
    if (!$alvo) jsonError('Usuário não encontrado.', 404);

    if ($id === (int)$user['usuario_id']) jsonError('Você não pode desativar sua própria conta.');

    $podeDesativar = false;

    switch ($user['perfil']) {
        case 'master_total':
            $podeDesativar = true;
            break;
        case 'master_operacional':
            if ($alvo['perfil'] !== 'master_total') $podeDesativar = true;
            break;
        case 'administrador':
            if (
                (int)$alvo['empresa_id'] === (int)$user['empresa_id'] &&
                $alvo['perfil'] !== 'master_total' &&
                $alvo['perfil'] !== 'master_operacional'
            ) {
                $podeDesativar = true;
            }
            break;
        default:
            $podeDesativar = false;
    }

    if (!$podeDesativar) jsonError('Acesso negado para desativar este usuário.', 403);

    // Master Total pode excluir permanentemente, outros apenas desativam
    if ($user['perfil'] === 'master_total') {
        // Exclui permanentemente
        $db->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
        $db->prepare("DELETE FROM sessoes WHERE usuario_id = ?")->execute([$id]);
    } else {
        // Apenas desativa
        $db->prepare("UPDATE usuarios SET status = 'inativo' WHERE id = ?")->execute([$id]);
    }

    if ($alvo['perfil'] === 'consultor' && $alvo['empresa_id']) {
        $db->prepare("UPDATE empresas SET licencas_em_uso = GREATEST(0, licencas_em_uso - 1) WHERE id = ?")
            ->execute([$alvo['empresa_id']]);
    }

    jsonOk(['mensagem' => $user['perfil'] === 'master_total' ? 'Usuário excluído permanentemente.' : 'Usuário desativado com sucesso.']);
}

jsonError('Método não suportado.', 405);
