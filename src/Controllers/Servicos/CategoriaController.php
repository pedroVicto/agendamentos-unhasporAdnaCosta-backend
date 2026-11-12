<?php

declare(strict_types=1);

namespace App\Controllers\Servicos;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use PDOException;

// Os grupos do catálogo ("Alongamento", "Manutenção"...). Quem cria e edita é
// a Supervisora; a leitura é pública, porque é com ela que o cliente filtra os
// serviços.
final class CategoriaController
{
    private const LIMITE_NOME = 60;

    // GET /api/categorias (público)
    public function listar(Request $request): Response
    {
        // A contagem de serviços é o que explica, na tela, por que uma
        // categoria pode não sair do catálogo.
        $categorias = Database::conexao()->query(
            'SELECT c.id, c.nome, c.created_at, c.updated_at,
                    COUNT(s.id) FILTER (WHERE s.ativo) AS servicos
               FROM categorias c
               LEFT JOIN servicos s ON s.categoria_id = c.id
              WHERE c.ativo = true
              GROUP BY c.id
              ORDER BY c.nome'
        )->fetchAll();

        return Response::json([
            'total'      => count($categorias),
            'categorias' => array_map($this->formatar(...), $categorias),
        ]);
    }

    // POST /api/categorias (admin)  {nome}
    public function criar(Request $request): Response
    {
        $nome = $this->lerNome($request);

        if (is_string($nome) === false) {
            return $nome;
        }

        try {
            $stmt = Database::conexao()->prepare(
                'INSERT INTO categorias (nome) VALUES (:nome)
                 RETURNING id, nome, created_at, updated_at'
            );
            $stmt->execute(['nome' => $nome]);
        } catch (PDOException $e) {
            return $this->jaExiste($e, $nome);
        }

        $categoria = $stmt->fetch();

        return Response::json([
            'mensagem'  => "Categoria \"{$categoria['nome']}\" criada com sucesso.",
            'categoria' => $this->formatar([...$categoria, 'servicos' => 0]),
        ], 201)->comHeader('Location', "/api/categorias/{$categoria['id']}");
    }

    // PUT /api/categorias/{id} (admin)  {nome}
    public function atualizar(Request $request): Response
    {
        $id = $request->params['id'];

        if (!Uuid::valido($id)) {
            return $this->idInvalido($id);
        }

        if (!$this->encontrar($id)) {
            return $this->naoEncontrada($id);
        }

        $nome = $this->lerNome($request);

        if (is_string($nome) === false) {
            return $nome;
        }

        try {
            $stmt = Database::conexao()->prepare(
                'UPDATE categorias SET nome = :nome, updated_at = NOW()
                  WHERE id = :id
                 RETURNING id, nome, created_at, updated_at'
            );
            $stmt->execute(['nome' => $nome, 'id' => $id]);
        } catch (PDOException $e) {
            return $this->jaExiste($e, $nome);
        }

        $categoria = $stmt->fetch();

        // O nome novo vale para todos os serviços do grupo de uma vez: eles
        // apontam para a categoria, não guardam uma cópia do nome.
        return Response::json([
            'mensagem'  => "Categoria \"{$categoria['nome']}\" atualizada com sucesso.",
            'categoria' => $this->formatar([...$categoria, 'servicos' => $this->quantosServicos($id)]),
        ]);
    }

    // DELETE /api/categorias/{id} (admin) — soft delete
    public function remover(Request $request): Response
    {
        $id = $request->params['id'];

        if (!Uuid::valido($id)) {
            return $this->idInvalido($id);
        }

        $categoria = $this->encontrar($id);

        if (!$categoria) {
            return $this->naoEncontrada($id);
        }

        // Com serviço dentro, remover deixaria o catálogo com um grupo que
        // ninguém mais consegue ver nem escolher. Primeiro move os serviços.
        $emUso = $this->quantosServicos($id);

        if ($emUso > 0) {
            return Response::json([
                'erro' => $emUso === 1
                    ? "A categoria \"{$categoria['nome']}\" tem 1 serviço. Mude a categoria dele antes de remover."
                    : "A categoria \"{$categoria['nome']}\" tem {$emUso} serviços. Mude a categoria deles antes de remover.",
                'servicos' => $emUso,
            ], 409);
        }

        $stmt = Database::conexao()->prepare(
            'UPDATE categorias SET ativo = false, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return Response::json([
            'mensagem' => "Categoria \"{$categoria['nome']}\" removida.",
            'id'       => $id,
        ]);
    }

    // --- auxiliares ---

    private function encontrar(string $id): array|false
    {
        $stmt = Database::conexao()->prepare(
            'SELECT id, nome FROM categorias WHERE id = :id AND ativo = true'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    private function quantosServicos(string $id): int
    {
        $stmt = Database::conexao()->prepare(
            'SELECT COUNT(*) FROM servicos WHERE categoria_id = :id AND ativo = true'
        );
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    // O Postgres devolve COUNT como string; a API devolve número.
    private function formatar(array $categoria): array
    {
        return [...$categoria, 'servicos' => (int) $categoria['servicos']];
    }

    /** Devolve o nome validado ou a resposta de erro pronta. */
    private function lerNome(Request $request): string|Response
    {
        $nome = $request->corpo['nome'] ?? null;

        if (!is_string($nome) || trim($nome) === '') {
            return $this->dadosInvalidos([
                'nome' => 'O nome da categoria é obrigatório. Ex.: "Alongamento".',
            ]);
        }

        $nome = trim($nome);

        if (mb_strlen($nome) > self::LIMITE_NOME) {
            return $this->dadosInvalidos([
                'nome' => 'O nome deve ter no máximo ' . self::LIMITE_NOME
                    . ' caracteres (enviado: ' . mb_strlen($nome) . ').',
            ]);
        }

        return $nome;
    }

    private function jaExiste(PDOException $e, string $nome): Response
    {
        if ($e->getCode() !== '23505') {
            throw $e;
        }

        return $this->dadosInvalidos([
            'nome' => "Já existe uma categoria chamada \"{$nome}\".",
        ]);
    }

    private function idInvalido(string $id): Response
    {
        return Response::erro(
            "O id \"{$id}\" não é válido. Use o id (UUID) retornado na listagem de categorias.",
            400
        );
    }

    private function naoEncontrada(string $id): Response
    {
        return Response::erro("Nenhuma categoria ativa encontrada com o id \"{$id}\".", 404);
    }

    private function dadosInvalidos(array $erros): Response
    {
        return Response::json([
            'erro'   => 'Não foi possível salvar a categoria. Corrija os campos indicados.',
            'campos' => $erros,
        ], 422);
    }
}
