<?php

class Auth
{
    public static function currentUser(array $config): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            Response::error('Não autenticado', 401);
        }

        $payload = Jwt::decode($matches[1], $config['jwt_secret']);
        if (!$payload || empty($payload['sub'])) {
            Response::error('Token inválido ou expirado', 401);
        }

        $stmt = Database::get()->prepare(
            'SELECT id, email, nome, moeda, notificacoes, plano, ativo, created_date FROM users WHERE id = ?'
        );
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();

        if (!$user || (int) $user['ativo'] === 0) {
            Response::error('Usuário não encontrado ou desativado', 401);
        }

        return $user;
    }
}
