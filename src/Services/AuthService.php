<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

// Login por e-mail + senha, exclusivo de supervisor e admin.
final class AuthService
{
    public const SENHA_MINIMA = 10;

    private const MAX_TENTATIVAS = 5;
    private const BLOQUEIO_MINUTOS = 15;

    public static function hashSenha(string $senha): string
    {
        return password_hash($senha, PASSWORD_ARGON2ID);
    }

    /**
     * Troca a senha de quem está logado. Devolve false se a senha atual
     * não confere.
     */
    public function trocarSenha(int $usuarioId, string $senhaAtual, string $novaSenha): bool
    {
        $pdo = Database::conexao();

        $busca = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = :id AND senha_hash IS NOT NULL');
        $busca->execute(['id' => $usuarioId]);
        $hash = $busca->fetchColumn();

        if ($hash === false || !password_verify($senhaAtual, $hash)) {
            return false;
        }

        $pdo->prepare(
            'UPDATE usuarios SET senha_hash = :hash, trocar_senha = FALSE, updated_at = NOW() WHERE id = :id'
        )->execute(['hash' => self::hashSenha($novaSenha), 'id' => $usuarioId]);

        return true;
    }

    /**
     * Devolve o usuário em caso de sucesso. Qualquer falha (e-mail
     * inexistente, cliente tentando entrar aqui, senha errada, conta
     * bloqueada) devolve null, para a resposta ser sempre a mesma.
     */
    public function loginStaff(string $email, string $senha): ?array
    {
        $pdo = Database::conexao();

        $busca = $pdo->prepare(
            "SELECT id, nome, papel, senha_hash, trocar_senha, bloqueado_ate,
                    (bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()) AS bloqueado
               FROM usuarios
              WHERE email = :email
                AND papel IN ('supervisor', 'admin')
                AND ativo
                AND senha_hash IS NOT NULL"
        );
        $busca->execute(['email' => $email]);
        $usuario = $busca->fetch();

        if (!$usuario) {
            // Gasta o mesmo tempo de uma verificação real, para o tempo de
            // resposta não revelar se o e-mail existe.
            self::hashSenha($senha);

            return null;
        }

        if ($usuario['bloqueado']) {
            self::hashSenha($senha);

            return null;
        }

        // Bloqueio já venceu: a contagem recomeça do zero.
        if ($usuario['bloqueado_ate'] !== null) {
            $pdo->prepare('UPDATE usuarios SET tentativas_login = 0, bloqueado_ate = NULL WHERE id = :id')
                ->execute(['id' => $usuario['id']]);
        }

        if (!password_verify($senha, $usuario['senha_hash'])) {
            $pdo->prepare(
                "UPDATE usuarios
                    SET tentativas_login = tentativas_login + 1,
                        bloqueado_ate = CASE
                            WHEN tentativas_login + 1 >= :max
                            THEN NOW() + (:minutos * INTERVAL '1 minute')
                        END
                  WHERE id = :id"
            )->execute([
                'max' => self::MAX_TENTATIVAS,
                'minutos' => self::BLOQUEIO_MINUTOS,
                'id' => $usuario['id'],
            ]);

            return null;
        }

        $pdo->prepare('UPDATE usuarios SET tentativas_login = 0, bloqueado_ate = NULL WHERE id = :id')
            ->execute(['id' => $usuario['id']]);

        // Se os parâmetros do Argon2 mudarem no futuro, o hash é atualizado no login.
        if (password_needs_rehash($usuario['senha_hash'], PASSWORD_ARGON2ID)) {
            $pdo->prepare('UPDATE usuarios SET senha_hash = :hash, updated_at = NOW() WHERE id = :id')
                ->execute(['hash' => self::hashSenha($senha), 'id' => $usuario['id']]);
        }

        return $usuario;
    }
}
