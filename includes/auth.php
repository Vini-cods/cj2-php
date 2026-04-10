<?php
// ============================================================
// includes/auth.php — FASE 1: 4 perfis padronizados
// Perfis: master_total, master_operacional, administrador, consultor
// ============================================================

require_once __DIR__ . '/db.php';

function requireAuth(): array {
    $token = getBearerToken();
    if (!$token) jsonError('Token não informado.', 401);

    $db  = getDB();
    $now = date('Y-m-d H:i:s');

    $stmt = $db->prepare("
        SELECT s.usuario_id, s.expira_em,
               u.id, u.nome, u.login, u.perfil, u.status, u.empresa_id
        FROM sessoes s
        INNER JOIN usuarios u ON u.id = s.usuario_id
        WHERE s.token = ? AND s.expira_em > ?
    ");
    $stmt->execute([$token, $now]);
    $row = $stmt->fetch();

    if (!$row)              jsonError('Sessão inválida ou expirada.', 401);
    if ($row['status'] !== 'ativo') jsonError('Usuário inativo.', 403);

    // Adiciona usuario_id ao array retornado
    $row['usuario_id'] = $row['id'];
    
    return $row;
}

// ── Verifica perfis permitidos ───────────────────────────────
function requirePerfil(array $user, array $perfisPermitidos): void {
    if (!in_array($user['perfil'], $perfisPermitidos)) {
        jsonError('Acesso negado para este perfil: ' . $user['perfil'], 403);
    }
}

// ── Garante que admin/consultor só acessa sua empresa ────────
function requireEmpresa(array $user, int $empresaId): void {
    if (in_array($user['perfil'], ['master_total', 'master_operacional'])) return;
    if ((int)$user['empresa_id'] !== $empresaId) {
        jsonError('Acesso negado: empresa não pertence a este usuário.', 403);
    }
}

// ── Regras de criação de perfis (HIERARQUIA COMPLETA) ────────
// master_total        → pode criar qualquer perfil
// master_operacional  → pode criar: administrador, consultor (e empresas)
// administrador       → pode criar: administrador (interno), consultor
// consultor           → não pode criar nenhum
function getPerfisPermitidosCriacao(string $perfilCriador): array {
    return match($perfilCriador) {
        'master_total'       => ['master_total', 'master_operacional', 'administrador', 'consultor'],
        'master_operacional' => ['administrador', 'consultor'],
        'administrador'      => ['administrador', 'consultor'],
        default              => [],
    };
}

// ── Regras de criação de empresas ────────────────────────────
// master_total        → pode criar empresas
// master_operacional  → pode criar empresas
// administrador       → NÃO pode criar empresas
// consultor           → NÃO pode criar empresas
function podeCriarEmpresa(string $perfilCriador): bool {
    return in_array($perfilCriador, ['master_total', 'master_operacional']);
}

// ── Financeiro: apenas master_total ─────────────────────────
function filtrarFinanceiro(array $empresa, array $user): array {
    if ($user['perfil'] !== 'master_total') {
        foreach (['valor_mensal','valor_por_licenca','valor_por_licenca_excedente',
                  'custo_por_consulta','saldo_creditos','vencimento_dia','status_cobranca'] as $c) {
            unset($empresa[$c]);
        }
    }
    return $empresa;
}

// ── API visível apenas para master_total e master_operacional ─
function podeVerApi(array $user): bool {
    return in_array($user['perfil'], ['master_total', 'master_operacional']);
}

// ── Identidade: administrador e master_total ────────────────
function podeEditarIdentidade(array $user): bool {
    return in_array($user['perfil'], ['master_total', 'administrador']);
}

// ── Helpers ───────────────────────────────────────────────────
function getBearerToken(): ?string {
    $headers = getallheaders();
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) return trim($m[1]);
    return null;
}

function generateToken(): string { return bin2hex(random_bytes(32)); }

function hashSenha(string $senha): string {
    return password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verificarSenha(string $senha, string $hash): bool {
    return password_verify($senha, $hash);
}