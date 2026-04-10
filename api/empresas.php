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
    // Master Total e Master Operacional podem ver empresas
    if (!in_array($user['perfil'], ['master_total', 'master_operacional'])) {
        jsonError('Acesso negado.', 403);
    }

    if ($id) {
        // Busca empresa específica
        $stmt = $db->prepare("
            SELECT e.*,
                   c.titulo_login, c.subtitulo_login, c.tema_padrao,
                   c.chip_1, c.chip_2, c.chip_3,
                   c.favicon, c.imagem_capa,
                   (SELECT COUNT(*) FROM usuarios u WHERE u.empresa_id = e.id AND u.status='ativo') as total_usuarios
            FROM empresas e
            LEFT JOIN configuracoes_empresa c ON c.empresa_id = e.id
            WHERE e.id = ?
        ");
        $stmt->execute([$id]);
        $empresa = $stmt->fetch();
        if (!$empresa) jsonError('Empresa não encontrada.', 404);
        jsonOk(['empresa' => filtrarFinanceiro($empresa, $user)]);
    } else {
        // Lista todas empresas
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
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    requirePerfil($user, ['master_total', 'master_operacional']);

    $body  = getBody();
    $nome  = trim($body['nome_empresa'] ?? '');
    $slug  = trim($body['slug'] ?? '');
    $nomeS = trim($body['nome_sistema'] ?? 'CJ2Tech Sistema de Consulta');

    if (!$nome || !$slug) jsonError('nome_empresa e slug são obrigatórios.');
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) jsonError('Slug inválido. Use letras minúsculas, números e hífens.');

    // Verifica se slug já existe
    $check = $db->prepare("SELECT id FROM empresas WHERE slug = ?");
    $check->execute([$slug]);
    if ($check->fetch()) jsonError('Slug já em uso.');

    // Admin automático obrigatório
    $adminLogin = trim($body['admin_login'] ?? '');
    $adminSenha = $body['admin_senha'] ?? '';
    $adminNome  = trim($body['admin_nome'] ?? 'Administrador');
    if (!$adminLogin || !$adminSenha) jsonError('admin_login e admin_senha são obrigatórios para criar empresa.');
    if (strlen($adminSenha) < 6) jsonError('Senha do admin deve ter ao menos 6 caracteres.');

    // Verifica se login do admin já existe
    $checkAdmin = $db->prepare("SELECT id FROM usuarios WHERE login = ?");
    $checkAdmin->execute([$adminLogin]);
    if ($checkAdmin->fetch()) jsonError('Login do admin já está em uso.');

    // Financeiro apenas master_total
    $fin = [];
    if ($user['perfil'] === 'master_total') {
        foreach (['valor_mensal','valor_por_licenca','valor_por_licenca_excedente','custo_por_consulta','vencimento_dia'] as $c) {
            if (isset($body[$c])) $fin[$c] = $body[$c];
        }
    }

    // Domínio
    $tipoDominio = $body['tipo_dominio'] ?? 'cj2';
    $subdominio = ($tipoDominio === 'cj2') ? ($body['subdominio'] ?? $slug) : null;
    $dominioPersonalizado = ($tipoDominio === 'proprio') ? ($body['dominio_personalizado'] ?? null) : null;
    
    if ($tipoDominio === 'cj2' && !$subdominio) {
        $subdominio = $slug;
    }

    // API Credenciais padrão (herdadas do master ou configuradas)
    $apiUsuario = $body['api_usuario'] ?? null;
    $apiSenha = $body['api_senha'] ?? null;
    
    // Se não veio nas configurações, tenta herdar do usuário master
    if ((!$apiUsuario || !$apiSenha) && $user['perfil'] === 'master_total') {
        // Busca credenciais da empresa do master (se existir)
        if ($user['empresa_id']) {
            $stmtMaster = $db->prepare("SELECT api_usuario, api_senha FROM empresas WHERE id = ?");
            $stmtMaster->execute([$user['empresa_id']]);
            $masterApi = $stmtMaster->fetch();
            if ($masterApi && $masterApi['api_usuario'] && $masterApi['api_senha']) {
                $apiUsuario = $masterApi['api_usuario'];
                $apiSenha = $masterApi['api_senha'];
            }
        }
    }

    // Insere empresa
    $db->prepare("
        INSERT INTO empresas (
            nome_empresa, nome_sistema, slug, status, limite_licencas,
            tipo_dominio, subdominio, dominio_personalizado,
            api_habilitada, api_usuario, api_senha, api_empresa, api_modo,
            valor_mensal, valor_por_licenca, valor_por_licenca_excedente,
            custo_por_consulta, vencimento_dia
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $nome, $nomeS, $slug,
        $body['status']               ?? 'ativo',
        (int)($body['limite_licencas'] ?? 5),
        $tipoDominio,
        $subdominio,
        $dominioPersonalizado,
        isset($body['api_habilitada']) ? (int)$body['api_habilitada'] : 1,
        $apiUsuario,
        $apiSenha,
        $body['api_empresa']          ?? $slug,
        $body['api_modo']             ?? 'offline',
        $fin['valor_mensal']          ?? null,
        $fin['valor_por_licenca']     ?? null,
        $fin['valor_por_licenca_excedente'] ?? null,
        $fin['custo_por_consulta']    ?? null,
        $fin['vencimento_dia']        ?? null,
    ]);

    $novoId = (int)$db->lastInsertId();

    // Configuração padrão da empresa
    $db->prepare("
        INSERT INTO configuracoes_empresa (empresa_id, titulo_login, subtitulo_login, chip_1, chip_2, chip_3)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $novoId,
        $body['titulo_login']    ?? null,
        $body['subtitulo_login'] ?? null,
        $body['chip_1']          ?? 'CPF e benefício atualizados',
        $body['chip_2']          ?? 'Quitação automática pelo Banco Central',
        $body['chip_3']          ?? 'Contratos detalhados',
    ]);

    // Cria administrador principal
    $db->prepare("
        INSERT INTO usuarios (empresa_id, nome, login, senha_hash, perfil, status)
        VALUES (?, ?, ?, ?, 'administrador', 'ativo')
    ")->execute([$novoId, $adminNome, $adminLogin, hashSenha($adminSenha)]);

    jsonOk([
        'mensagem'   => 'Empresa criada com sucesso!',
        'empresa_id' => $novoId,
        'admin_id'   => (int)$db->lastInsertId(),
    ], 201);
}

// ── PUT ───────────────────────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) jsonError('ID não informado.');
    
    if (!in_array($user['perfil'], ['master_total', 'master_operacional'])) {
        jsonError('Acesso negado para editar empresa.', 403);
    }
    
    if ($user['perfil'] === 'master_operacional') {
        $bodyCheck = getBody();
        $camposFinanceiro = ['valor_mensal', 'valor_por_licenca', 'valor_por_licenca_excedente', 
                             'custo_por_consulta', 'saldo_creditos', 'vencimento_dia', 'status_cobranca'];
        foreach ($camposFinanceiro as $campo) {
            if (array_key_exists($campo, $bodyCheck)) {
                jsonError('Master Operacional não pode editar dados financeiros.', 403);
            }
        }
    }

    $body   = getBody();
    $campos = [];
    $vals   = [];

    $editaveis = ['nome_empresa', 'nome_sistema', 'slug', 'status', 'limite_licencas'];
    
    if (in_array($user['perfil'], ['master_total', 'master_operacional'])) {
        $editaveis = array_merge($editaveis, ['tipo_dominio', 'subdominio', 'dominio_personalizado']);
    }
    
    if (in_array($user['perfil'], ['master_total', 'master_operacional'])) {
        $editaveis = array_merge($editaveis, ['api_habilitada', 'api_usuario', 'api_senha', 'api_empresa', 'api_modo']);
    }

    foreach ($editaveis as $c) {
        if (array_key_exists($c, $body)) {
            $campos[] = "$c = ?";
            $vals[] = $body[$c];
        }
    }

    if ($user['perfil'] === 'master_total') {
        foreach (['valor_mensal', 'valor_por_licenca', 'valor_por_licenca_excedente',
                  'custo_por_consulta', 'saldo_creditos', 'vencimento_dia', 'status_cobranca'] as $c) {
            if (array_key_exists($c, $body)) {
                $campos[] = "$c = ?";
                $vals[] = $body[$c];
            }
        }
    }

    if (!empty($campos)) {
        $vals[] = $id;
        $db->prepare("UPDATE empresas SET " . implode(', ', $campos) . " WHERE id = ?")->execute($vals);
    }

    jsonOk(['mensagem' => 'Empresa atualizada com sucesso.']);
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) jsonError('ID não informado.');
    requirePerfil($user, ['master_total']);
    
    // Verifica se existem usuários ativos na empresa
    $checkUsers = $db->prepare("SELECT COUNT(*) as total FROM usuarios WHERE empresa_id = ? AND status = 'ativo'");
    $checkUsers->execute([$id]);
    $usersCount = $checkUsers->fetch();
    
    if ($usersCount && $usersCount['total'] > 0) {
        $db->prepare("UPDATE usuarios SET status = 'inativo' WHERE empresa_id = ?")->execute([$id]);
    }
    
    $db->prepare("UPDATE empresas SET status = 'inativo' WHERE id = ?")->execute([$id]);
    jsonOk(['mensagem' => 'Empresa desativada com sucesso.']);
}

jsonError('Método não suportado.', 405);