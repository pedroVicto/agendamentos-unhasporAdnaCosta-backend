<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\Contato;
use App\Services\Notificacao\Email;
use App\Services\SessionService;
use PDOException;

// Gestão de supervisores. Admin não é criado nem alterado por aqui (só pelo seeder).
final class UsuarioController
{
    // GET /api/admin/usuarios
    public function listar(Request $request): Response
    {
        $usuarios = Database::conexao()->query(
            "SELECT id, nome, email, papel, ativo, created_at
               FROM usuarios
              WHERE papel IN ('supervisor', 'admin')
              ORDER BY nome"
        )->fetchAll();

        return Response::json(['usuarios' => $usuarios]);
    }

    // POST /api/admin/usuarios  {nome, email, senha}
    // A senha definida pelo admin é temporária: vai para o e-mail do
    // supervisor e ele é obrigado a trocá-la no primeiro acesso.
    public function criarSupervisor(Request $request): Response
    {
        $nome = $request->input('nome');
        $email = Contato::email($request->input('email'));
        $senha = $request->corpo['senha'] ?? null;

        if ($nome === null || mb_strlen($nome) > 150 || $email === null) {
            return Response::erro('Informe nome e e-mail válidos.', 422);
        }
        if (!is_string($senha) || strlen($senha) < AuthService::SENHA_MINIMA) {
            return Response::erro('A senha precisa ter ao menos ' . AuthService::SENHA_MINIMA . ' caracteres.', 422);
        }

        $pdo = Database::conexao();

        // Se o e-mail não sair, o cadastro é desfeito: um supervisor sem a
        // senha não teria como entrar, e o admin pode tentar de novo.
        $pdo->beginTransaction();
        try {
            $insercao = $pdo->prepare(
                "INSERT INTO usuarios (nome, email, senha_hash, trocar_senha, papel, verificado_em)
                 VALUES (:nome, :email, :hash, TRUE, 'supervisor', NOW())
                 RETURNING id"
            );
            $insercao->execute([
                'nome' => $nome,
                'email' => $email,
                'hash' => AuthService::hashSenha($senha),
            ]);
            $id = $insercao->fetchColumn();

            Email::enviar($email, 'Seu acesso de supervisor', self::mensagemBoasVindas($nome, $email, $senha));

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23505') {
                return Response::erro('Já existe um usuário com este e-mail.', 409);
            }
            throw $e;
        } catch (\RuntimeException $e) {
            $pdo->rollBack();
            error_log($e->getMessage());

            return Response::erro('Não foi possível enviar o e-mail com a senha. O supervisor não foi criado.', 502);
        }

        return Response::json([
            'usuario' => ['id' => $id, 'nome' => $nome, 'papel' => 'supervisor'],
        ], 201);
    }

    // PATCH /api/admin/usuarios/{id}/ativo  {ativo: true|false}
    public function definirAtivo(Request $request): Response
    {
        $ativo = $request->corpo['ativo'] ?? null;
        if (!is_bool($ativo) || !ctype_digit($request->params['id'])) {
            return Response::erro('Informe "ativo" como true ou false.', 422);
        }
        $id = (int) $request->params['id'];

        $atualizacao = Database::conexao()->prepare(
            "UPDATE usuarios SET ativo = :ativo, updated_at = NOW() WHERE id = :id AND papel = 'supervisor'"
        );
        $atualizacao->bindValue('ativo', $ativo, \PDO::PARAM_BOOL);
        $atualizacao->bindValue('id', $id, \PDO::PARAM_INT);
        $atualizacao->execute();

        if ($atualizacao->rowCount() === 0) {
            return Response::erro('Supervisor não encontrado.', 404);
        }

        if (!$ativo) {
            (new SessionService())->revogarTodas($id);
        }

        return Response::vazia();
    }

    private static function mensagemBoasVindas(string $nome, string $email, string $senha): string
    {
        $acesso = Env::get('FRONTEND_URL');

        return "Olá, {$nome}!\n\n"
            . "Sua conta de supervisor foi criada.\n\n"
            . "Usuário (login): {$email}\n"
            . "Senha temporária: {$senha}\n\n"
            . ($acesso !== null ? "Acesse: {$acesso}\n\n" : '')
            . 'No primeiro acesso você vai precisar definir uma nova senha. '
            . 'Se você não esperava este e-mail, avise o administrador.';
    }
}
