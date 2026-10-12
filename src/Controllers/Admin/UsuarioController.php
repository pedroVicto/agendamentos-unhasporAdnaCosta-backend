<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\Contato;
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

        try {
            $insercao = Database::conexao()->prepare(
                "INSERT INTO usuarios (nome, email, senha_hash, papel, verificado_em)
                 VALUES (:nome, :email, :hash, 'supervisor', NOW())
                 RETURNING id"
            );
            $insercao->execute([
                'nome' => $nome,
                'email' => $email,
                'hash' => AuthService::hashSenha($senha),
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                return Response::erro('Já existe um usuário com este e-mail.', 409);
            }
            throw $e;
        }

        return Response::json([
            'usuario' => ['id' => $insercao->fetchColumn(), 'nome' => $nome, 'papel' => 'supervisor'],
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
}
