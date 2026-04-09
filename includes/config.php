<?php
// ============================================================
// includes/config.php — HostGator PRODUÇÃO
// ============================================================

// ── Banco de dados ───────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'cj2com88_inss');
define('DB_USER', 'cj2com88_inss');
define('DB_PASS', 'admin@cj2');
define('DB_CHARSET', 'utf8mb4');

// ── Segurança ────────────────────────────────────────────────
define('JWT_SECRET', 'cj2tech_consultas_2024_K9mXpL3vQrN8wZ5jY7hA');
define('SESSION_HOURS', 8);

// ── URL base do sistema ──────────────────────────────────────
define('BASE_URL', 'https://consultas.cj2company.com.br');

// ── Ambiente ─────────────────────────────────────────────────
define('AMBIENTE', 'producao');

if (AMBIENTE === 'desenvolvimento') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// ── Promosys ─────────────────────────────────────────────────
define('PROMOSYS_BASE', 'https://jcf.promosysweb.com/services');
define('PROMOSYS_SSL_VERIFY', false);

// ── Domínio base CJ2 ─────────────────────────────────────────
define('CJ2_DOMINIO_BASE', 'cj2company.com.br');