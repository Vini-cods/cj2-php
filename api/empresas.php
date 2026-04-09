<?php
// ============================================================
// api/empresas.php — FASE 2: campos completos + admin automático
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
    requirePerfil($user, ['master_total','master_operacional']);

    $stmt = $db->query("
        SELECT e.*,
               c.titulo_login, c.subtitulo_login, c.tema_padrao,
               c.chip_1, c.chip_2, c.chip_3,
               c.favicon, c.imagem_capa,
               (SELECT COUNT(*) FROM usuarios u WHERE u.empresa_id = e.id AND u.status='ativo') as total_usuarios
        FROM empresas e
        LEFT JOIN configuracoes_empresa c ON c.empresa_id = e.id
        ORDER BY e.nome_empresa
    ");
    $empresas = array_map(fn($e) => filtrarFinanceiro($e, $user), $stmt->fetchAll());
    jsonOk(['empresas' => $empresas]);
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    requirePerfil($user, ['master_total','master_operacional']);

    $body  = getBody();
    $nome  = trim($body['nome_empresa'] ?? '');
    $slug  = trim($body['slug'] ?? '');
    $nomeS = trim($body['nome_sistema'] ?? 'CJ2Tech Sistema de Consulta');

    if (!$nome || !$slug) jsonError('nome_empresa e slug são obrigatórios.');
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) jsonError('Slug inválido. Use letras minúsculas, números e hífens.');

    $check = $db->prepare("SELECT id FROM empresas WHERE slug = ?");
    $check->execute([$slug]);
    if ($check->fetch()) jsonError('Slug já em uso.');

    // Admin automático obrigatório
    $adminLogin = trim($body['admin_login'] ?? '');
    $adminSenha = $body['admin_senha'] ?? '';
    $adminNome  = trim($body['admin_nome'] ?? 'Administrador');
    if (!$adminLogin || !$adminSenha) jsonError('admin_login e admin_senha são obrigatórios para criar empresa.');
    if (strlen($adminSenha) < 6) jsonError('Senha do admin deve ter ao menos 6 caracteres.');

    // Financeiro apenas master_total
    $fin = [];
    if ($user['perfil'] === 'master_total') {
        foreach (['valor_mensal','valor_por_licenca','valor_por_licenca_excedente','custo_por_consulta','vencimento_dia'] as $c) {
            if (isset($body[$c])) $fin[$c] = $body[$c];
        }
    }

    $db->prepare("
        INSERT INTO empresas (
            nome_empresa,nome_sistema,slug,status,limite_licencas,
            tipo_dominio,subdominio,dominio_personalizado,
            api_habilitada,api_usuario,api_senha,api_empresa,api_modo,
            valor_mensal,valor_por_licenca,valor_por_licenca_excedente,
            custo_por_consulta,vencimento_dia
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $nome, $nomeS, $slug,
        $body['status']               ?? 'ativo',
        (int)($body['limite_licencas'] ?? 5),
        $body['tipo_dominio']         ?? 'cj2',
        $body['subdominio']           ?? null,
        $body['dominio_personalizado'] ?? null,
        isset($body['api_habilitada']) ? (int)$body['api_habilitada'] : 1,
        $body['api_usuario']          ?? null,
        $body['api_senha']            ?? null,
        $body['api_empresa']          ?? null,
        $body['api_modo']             ?? 'offline',
        $fin['valor_mensal']          ?? null,
        $fin['valor_por_licenca']     ?? null,
        $fin['valor_por_licenca_excedente'] ?? null,
        $fin['custo_por_consulta']    ?? null,
        $fin['vencimento_dia']        ?? null,
    ]);

    $novoId = (int)$db->lastInsertId();

    // Configuração padrão
    $db->prepare("
        INSERT INTO configuracoes_empresa (empresa_id,titulo_login,subtitulo_login,chip_1,chip_2,chip_3)
        VALUES (?,?,?,?,?,?)
    ")->execute([
        $novoId,
        $body['titulo_login']    ?? null,
        $body['subtitulo_login'] ?? null,
        $body['chip_1']          ?? 'CPF e benefício atualizados',
        $body['chip_2']          ?? 'Quitação automática pelo Banco Central',
        $body['chip_3']          ?? 'Contratos detalhados',
    ]);

    // Cria administrador principal automaticamente (FASE 2)
    $checkAdmin = $db->prepare("SELECT id FROM usuarios WHERE login = ?");
    $checkAdmin->execute([$adminLogin]);
    if ($checkAdmin->fetch()) jsonError('Login do admin já está em uso.');

    $db->prepare("
        INSERT INTO usuarios (empresa_id,nome,login,senha_hash,perfil,status)
        VALUES (?,?,?,?,'administrador','ativo')
    ")->execute([$novoId, $adminNome, $adminLogin, hashSenha($adminSenha)]);

    jsonOk([
        'mensagem'   => 'Empresa criada com administrador principal.',
        'empresa_id' => $novoId,
        'admin_id'   => (int)$db->lastInsertId(),
    ], 201);
}

// ── PUT ───────────────────────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) jsonError('ID não informado.');
    requirePerfil($user, ['master_total','master_operacional','administrador']);
    requireEmpresa($user, $id);

    $body   = getBody();
    $campos = []; $vals = [];

    $editaveis = ['nome_empresa','nome_sistema','slug','status','limite_licencas',
                  'tipo_dominio','subdominio','dominio_personalizado',
                  'api_habilitada','api_usuario','api_senha','api_empresa','api_modo'];

    // Administrador não pode editar campos de API
    if ($user['perfil'] === 'administrador') {
        $editaveis = array_diff($editaveis, ['api_habilitada','api_usuario','api_senha','api_empresa','api_modo']);
    }

    foreach ($editaveis as $c) {
        if (array_key_exists($c, $body)) { $campos[] = "$c = ?"; $vals[] = $body[$c]; }
    }

    // Financeiro: apenas master_total
    if ($user['perfil'] === 'master_total') {
        foreach (['valor_mensal','valor_por_licenca','valor_por_licenca_excedente',
                  'custo_por_consulta','saldo_creditos','vencimento_dia','status_cobranca'] as $c) {
            if (array_key_exists($c, $body)) { $campos[] = "$c = ?"; $vals[] = $body[$c]; }
        }
    }

    if (!empty($campos)) {
        $vals[] = $id;
        $db->prepare("UPDATE empresas SET " . implode(', ', $campos) . " WHERE id = ?")->execute($vals);
    }

    // Configurações visuais
    $cfgCampos = []; $cfgVals = [];
    $cfgEditaveis = ['titulo_login','subtitulo_login','chip_1','chip_2','chip_3','tema_padrao'];

    // Identidade (FASE 3): apenas administrador e master_total
    if (podeEditarIdentidade($user)) {
        $cfgEditaveis = array_merge($cfgEditaveis, ['logo_dark','logo_light','favicon','imagem_capa']);
    }

    foreach ($cfgEditaveis as $c) {
        if (array_key_exists($c, $body)) { $cfgCampos[] = "$c = ?"; $cfgVals[] = $body[$c]; }
    }

    if (!empty($cfgCampos)) {
        $cfgVals[] = $id;
        $ex = $db->prepare("SELECT id FROM configuracoes_empresa WHERE empresa_id = ?");
        $ex->execute([$id]);
        if ($ex->fetch()) {
            $db->prepare("UPDATE configuracoes_empresa SET " . implode(', ', $cfgCampos) . " WHERE empresa_id = ?")->execute($cfgVals);
        } else {
            $db->prepare("INSERT INTO configuracoes_empresa (empresa_id) VALUES (?)")->execute([$id]);
            $db->prepare("UPDATE configuracoes_empresa SET " . implode(', ', $cfgCampos) . " WHERE empresa_id = ?")->execute($cfgVals);
        }
    }

    jsonOk(['mensagem' => 'Empresa atualizada.']);
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) jsonError('ID não informado.');
    requirePerfil($user, ['master_total']);
    $db->prepare("UPDATE empresas SET status = 'inativo' WHERE id = ?")->execute([$id]);
    jsonOk(['mensagem' => 'Empresa desativada.']);
}

jsonError('Método não suportado.', 405);
