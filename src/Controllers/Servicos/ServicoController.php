<?php

declare(strict_types=1);

namespace App\Controllers\Servicos;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class ServicoController
{
    // GET /api/servicos (público)
    public function listar(Request $request): Response
    {
        $servicos = Database::conexao()
            ->query('SELECT id, nome, preco, descricao FROM servicos ORDER BY nome')
            ->fetchAll();

        return Response::json(['servicos' => $servicos]);
    }

    // GET /api/servicos/{id} (público)
    public function buscar(Request $request): Response
    {
        $servico = $this->encontrar((int) $request->params['id']);

        if (!$servico) {
            return Response::json(['erro' => 'Serviço não encontrado'], 404);
        }

        return Response::json(['servico' => $servico]);
    }

    // POST /api/servicos (admin)
    public function criar(Request $request): Response
    {
        $dados = $request->corpo;
        $erros = $this->validar($dados);

        if ($erros) {
            return Response::json(['erros' => $erros], 422);
        }

        $pdo = Database::conexao();
        $stmt = $pdo->prepare(
            'INSERT INTO servicos (nome, preco, descricao)
             VALUES (:nome, :preco, :descricao)'
        );
        $stmt->execute([
            'nome'      => trim($dados['nome']),
            'preco'     => $dados['preco'],
            'descricao' => $dados['descricao'] ?? null,
        ]);

        return Response::json(['id' => (int) $pdo->lastInsertId()], 201);
    }

    // PUT /api/servicos/{id} (admin)
    public function atualizar(Request $request): Response
    {
        $id = (int) ($request->params['id'] ?? 0);

        if (!$this->encontrar($id)) {
            return Response::json(['erro' => 'Serviço não encontrado'], 404);
        }

        $dados = $request->corpo;
        $erros = $this->validar($dados);

        if ($erros) {
            return Response::json(['erros' => $erros], 422);
        }

        $stmt = Database::conexao()->prepare(
            'UPDATE servicos
             SET nome = :nome, preco = :preco, descricao = :descricao, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'nome'      => trim($dados['nome']),
            'preco'     => $dados['preco'],
            'descricao' => $dados['descricao'] ?? null,
            'id'        => $id,
        ]);

        return Response::json(['mensagem' => 'Serviço atualizado']);
    }

    // DELETE /api/servicos/{id} (admin) — soft delete
    public function remover(Request $request): Response
    {
        $id = (int) $request->params['id'];

        if (!$this->encontrar($id)) {
            return Response::json(['erro' => 'Serviço não encontrado'], 404);
        }

        $stmt = Database::conexao()->prepare(
            'UPDATE servicos SET ativo = false, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return Response::json(['mensagem' => 'Serviço removido']);
    }

    // --- auxiliares ---

    private function encontrar(int $id): array|false
    {
        $stmt = Database::conexao()->prepare(
            'SELECT id, nome, preco, descricao FROM servicos WHERE id = :id AND ativo = true'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    private function validar(array $dados): array
    {
        $erros = [];

        if (trim($dados['nome'] ?? '') === '') {
            $erros['nome'] = 'Nome é obrigatório';
        } elseif (mb_strlen(trim($dados['nome'])) > 100) {
            $erros['nome'] = 'Nome deve ter no máximo 100 caracteres';
        }

        if (!isset($dados['preco']) || !is_numeric($dados['preco']) || $dados['preco'] < 0) {
            $erros['preco'] = 'Preço deve ser um número maior ou igual a 0';
        }

        return $erros;
    }
}