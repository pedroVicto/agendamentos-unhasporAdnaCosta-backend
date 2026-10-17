<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class SessionService
{
    public const COOKIE = 'sessao';

    private const DURACAO_CLIENTE = 60 * 60 * 24 * 30; // 30 dias
    private const DURACAO_STAFF = 60 * 60 * 8;         // 8 horas

    /**
     * Cria a sessão e já devolve a resposta com o cookie httpOnly.
     */
    public function iniciar(array $usuario, Request $request): Response
    {
        $duracao = $usuario['papel'] === 'cliente' ? self::DURACAO_CLIENTE : self::DURACAO_STAFF;

        // O navegador recebe o token; o banco guarda só o hash dele.
        // Se a tabela vazar, ninguém consegue montar um cookie válido.
        $token = bin2hex(random_bytes(32));

        Database::conexao()->prepare(
            "INSERT INTO sessoes (usuario_id, token_hash, expira_em, ip, user_agent)
             VALUES (:usuario_id, :token_hash, NOW() + (:duracao * INTERVAL '1 second'), :ip, :user_agent)"
        )->execute([
            'usuario_id' => $usuario['id'],
            'token_hash' => self::hash($token),
            'duracao' => $duracao,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return Response::json(['usuario' => self::publico($usuario)])
            ->comCookie(self::COOKIE, $token, time() + $duracao);
    }

    /**
     * Devolve o usuário dono do token, ou null se a sessão não vale mais
     * (expirada, revogada ou usuário desativado).
     */
    public function resolver(string $token): ?array
    {
        $busca = Database::conexao()->prepare(
            'SELECT u.id, u.nome, u.email, u.telefone, u.papel, u.trocar_senha
               FROM sessoes s
               JOIN usuarios u ON u.id = s.usuario_id
              WHERE s.token_hash = :token_hash
                AND s.revogada_em IS NULL
                AND s.expira_em > NOW()
                AND u.ativo'
        );
        $busca->execute(['token_hash' => self::hash($token)]);

        return $busca->fetch() ?: null;
    }

    public function revogar(string $token): void
    {
        Database::conexao()->prepare(
            'UPDATE sessoes SET revogada_em = NOW() WHERE token_hash = :token_hash AND revogada_em IS NULL'
        )->execute(['token_hash' => self::hash($token)]);
    }

    public function revogarTodas(string $usuarioId): void
    {
        Database::conexao()->prepare(
            'UPDATE sessoes SET revogada_em = NOW() WHERE usuario_id = :id AND revogada_em IS NULL'
        )->execute(['id' => $usuarioId]);
    }

    // Depois de trocar a senha, só a sessão atual continua valendo.
    public function revogarOutras(string $usuarioId, string $tokenAtual): void
    {
        Database::conexao()->prepare(
            'UPDATE sessoes SET revogada_em = NOW()
              WHERE usuario_id = :id AND revogada_em IS NULL AND token_hash <> :token_hash'
        )->execute(['id' => $usuarioId, 'token_hash' => self::hash($tokenAtual)]);
    }

    public static function publico(array $usuario): array
    {
        return [
            'id' => $usuario['id'],
            'nome' => $usuario['nome'],
            'papel' => $usuario['papel'],
            // O front manda para a tela de troca de senha enquanto for true.
            'trocar_senha' => (bool) ($usuario['trocar_senha'] ?? false),
        ];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
