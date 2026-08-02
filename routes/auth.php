<?php

// Inclui em escopo: $path, $method, $body, $config (definidos em index.php)

switch (true) {
    case $path === '/auth/login' && $method === 'POST':
        $email = trim(strtolower($body['email'] ?? ''));
        $password = $body['password'] ?? '';

        // Chave por IP + email: barra tentativa de força bruta contra uma
        // conta específica vinda de uma mesma origem, sem arriscar travar
        // uma rede/escritório inteiro por causa de outro usuário errando a
        // senha numa conta diferente.
        $rateLimitKey = RateLimiter::clientIp() . '|' . $email;
        [$blocked, $retryAfter] = RateLimiter::check($rateLimitKey);
        if ($blocked) {
            header('Retry-After: ' . $retryAfter);
            Response::error('Muitas tentativas de login. Tente novamente em alguns minutos.', 429);
        }

        $stmt = Database::get()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            RateLimiter::registerFailure($rateLimitKey);
            Response::error('Credenciais inválidas', 401);
        }
        if ((int) $user['ativo'] === 0) {
            Response::error('Usuário desativado', 403);
        }

        RateLimiter::clear($rateLimitKey);
        $token = Jwt::encode(['sub' => $user['id']], $config['jwt_secret'], $config['jwt_expires_seconds']);
        unset($user['password_hash'], $user['reset_token'], $user['reset_token_expires'], $user['email_verify_token']);
        Response::json(['token' => $token, 'user' => $user]);
        break;

    case $path === '/auth/register' && $method === 'POST':
        $email = trim(strtolower($body['email'] ?? ''));
        $password = $body['password'] ?? '';
        $nome = trim($body['nome'] ?? '') ?: explode('@', $email)[0];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            Response::error('Email válido e senha com no mínimo 6 caracteres são obrigatórios', 400);
        }

        $stmt = Database::get()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Response::error('Já existe uma conta com este email', 409);
        }

        $id = Uuid::v4();
        $verifyToken = bin2hex(random_bytes(32));

        $stmt = Database::get()->prepare(
            'INSERT INTO users (id, email, password_hash, nome, email_verify_token) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $email, password_hash($password, PASSWORD_BCRYPT), $nome, $verifyToken]);

        // Não bloqueia o cadastro se o email falhar — verificação é informativa,
        // o usuário já está logado e utilizável a partir daqui.
        try {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $backendUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $verifyUrl = $backendUrl . '/auth/verify-email?token=' . $verifyToken;
            Mailer::send(
                $config,
                $email,
                'Confirme seu email — ' . ($config['mail_from_name'] ?? 'Domus Finanças'),
                "<p>Bem-vindo(a)! Confirme seu email clicando no link abaixo:</p>"
                . "<p><a href=\"$verifyUrl\">Confirmar meu email</a></p>"
            );
        } catch (Throwable $e) {
            // ignora — email de confirmação é best-effort
        }

        $token = Jwt::encode(['sub' => $id], $config['jwt_secret'], $config['jwt_expires_seconds']);
        Response::json(['token' => $token, 'user' => [
            'id' => $id,
            'email' => $email,
            'nome' => $nome,
            'moeda' => 'BRL',
            'notificacoes' => 1,
            'plano' => 'Plano Doméstico',
            'ativo' => 1,
        ]], 201);
        break;

    case $path === '/auth/verify-email' && $method === 'GET':
        $token = $_GET['token'] ?? '';
        if ($token) {
            $stmt = Database::get()->prepare('SELECT id FROM users WHERE email_verify_token = ?');
            $stmt->execute([$token]);
            $user = $stmt->fetch();
            if ($user) {
                Database::get()->prepare(
                    'UPDATE users SET email_verified_at = NOW(), email_verify_token = NULL WHERE id = ?'
                )->execute([$user['id']]);
            }
        }
        header('Location: ' . rtrim($config['frontend_url'], '/') . '/?email_verificado=1');
        http_response_code(302);
        exit;

    case $path === '/auth/me' && $method === 'GET':
        Response::json(Auth::currentUser($config));
        break;

    case $path === '/auth/me' && $method === 'PUT':
        $user = Auth::currentUser($config);
        $updates = [];
        $params = [':id' => $user['id']];

        if (array_key_exists('nome', $body)) {
            $updates[] = 'nome = :nome';
            $params[':nome'] = $body['nome'];
        }
        if (array_key_exists('moeda', $body)) {
            $updates[] = 'moeda = :moeda';
            $params[':moeda'] = $body['moeda'];
        }
        if (array_key_exists('notificacoes', $body)) {
            $updates[] = 'notificacoes = :notificacoes';
            $params[':notificacoes'] = $body['notificacoes'] ? 1 : 0;
        }

        if ($updates) {
            $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id';
            Database::get()->prepare($sql)->execute($params);
        }

        Response::json(Auth::currentUser($config));
        break;

    case $path === '/auth/forgot-password' && $method === 'POST':
        $email = trim(strtolower($body['email'] ?? ''));
        $stmt = Database::get()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            Database::get()->prepare('UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?')
                ->execute([$token, $expires, $user['id']]);

            $resetUrl = rtrim($config['frontend_url'], '/') . '/reset-password?token=' . $token;
            Mailer::send(
                $config,
                $email,
                'Redefinição de senha — ' . ($config['mail_from_name'] ?? 'Domus Finanças'),
                "<p>Recebemos uma solicitação para redefinir sua senha.</p>"
                . "<p><a href=\"$resetUrl\">Clique aqui para criar uma nova senha</a></p>"
                . "<p>Este link expira em 1 hora. Se você não solicitou isso, ignore este email.</p>"
            );
        }

        // Sempre responde com sucesso para não revelar quais emails existem.
        Response::json(['success' => true]);
        break;

    case $path === '/auth/reset-password' && $method === 'POST':
        $token = $body['resetToken'] ?? '';
        $newPassword = $body['newPassword'] ?? '';

        if (!$token || strlen($newPassword) < 6) {
            Response::error('Dados inválidos', 400);
        }

        $stmt = Database::get()->prepare(
            'SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('Link de redefinição inválido ou expirado', 400);
        }

        Database::get()->prepare(
            'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL, ativo = 1 WHERE id = ?'
        )->execute([password_hash($newPassword, PASSWORD_BCRYPT), $user['id']]);

        Response::json(['success' => true]);
        break;

    default:
        Response::error('Rota de autenticação não encontrada', 404);
}
