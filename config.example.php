<?php

// Copie este arquivo para "config.php" (mesmo diretório) e preencha com os
// valores reais. config.php NUNCA deve ser commitado — já está no .gitignore.

return [
    // MySQL
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'SEU_BANCO',
    'db_user' => 'SEU_USUARIO',
    'db_pass' => 'COLOQUE_A_SENHA_AQUI',

    // Autenticação
    'jwt_secret' => 'GERE_UMA_STRING_ALEATORIA_LONGA_E_UNICA',
    'jwt_expires_seconds' => 60 * 60 * 24 * 7, // 7 dias

    // CORS — origens do frontend que podem chamar esta API
    'cors_allowed_origins' => [
        'http://localhost:5173',
        'https://nome.vercel.app',
    ],

    // Email transacional (Brevo — https://app.brevo.com/settings/keys/api).
    // Deixe em branco em dev: Mailer::send() só faz silenciosamente nada,
    // não quebra registro/login/alertas.
    'brevo_api_key' => '',
    'mail_from_email' => 'contato@seudominio.com.br',
    'mail_from_name' => 'Domus Finanças',

    // Usado para montar links de email (redefinir senha, confirmar email)
    'frontend_url' => 'https://nome.vercel.app',
];
