<?php

declare(strict_types=1);

namespace App\Controllers\Servicos;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;

final class ServicoController
{
    // O nome da categoria vem da junção: o serviço guarda só o id dela, então
    // renomear a categoria vale para todos os serviços de uma vez.
    private const COLUNAS = 's.id, s.nome, s.preco, s.descricao, s.duracao_minutos,
                             s.categoria_id, c.nome AS categoria, s.created_at, s.updated_at';

    private const DE = ' FROM servicos s JOIN categorias c ON c.id = s.categoria_id ';

    // GET /api/servicos?categoria_id=... (público)
    public function listar(Request $request): Response
    {
        $filtros = ['s.ativo = true'];
        $parametros = [];

        $categoriaId = $request->query['categoria_id'] ?? null;
        if (is_string($categoriaId) && $categoriaId !== '') {
            if (!Uuid::valido($categoriaId)) {
                return Response::erro(
                    'Informe "categoria_id" como o id (UUID) devolvido em /api/categorias.',
                    422
                );
            }
            $filtros[] = 's.categoria_id = :categoria_id';
            $parametros['categoria_id'] = $categoriaId;
        }

        $busca = Database::conexao()->prepare(
            'SELECT ' . self::COLUNAS . self::DE
            . ' WHERE ' . implode(' AND ', $filtros)
            . ' ORDER BY c.nome, s.nome'
        );
        $busca->execute($parametros);
        $servicos = $busca->fetchAll();

        return Response::json([
            'total'    => count($servicos),
            'servicos' => array_map($this->formatar(...), $servicos),
        ]);
    }

    // GET /api/servicos/{id} (público)
    public function buscar(Request $request): Response
    {
        $id = $request->params['id'];

        if (!Uuid::valido($id)) {
            return $this->idInvalido($id);
        }

        $servico = $this->encontrar($id);

        if (!$servico) {
            return $this->naoEncontrado($id);
        }

        return Response::json(['servico' => $this->formatar($servico)]);
    }

    // POST /api/servicos (admin)
    public function criar(Request $request): Response
    {
        $dados = $request->corpo;
        $erros = $this->validar($dados);

        if ($erros) {
            return $this->dadosInvalidos($erros);
        }

        // O id (UUID aleatório) é gerado pelo próprio banco.
        $stmt = Database::conexao()->prepare(
            'WITH novo AS (
                INSERT INTO servicos (nome, preco, descricao, duracao_minutos, categoria_id)
                VALUES (:nome, :preco, :descricao, COALESCE(:duracao_minutos, 60), :categoria_id)
                RETURNING *
             )
             SELECT ' . self::COLUNAS . ' FROM novo s JOIN categorias c ON c.id = s.categoria_id'
        );
        $stmt->execute($this->parametros($dados));
        $servico = $stmt->fetch();

        return Response::json([
            'mensagem' => "Serviço \"{$servico['nome']}\" criado com sucesso.",
            'servico'  => $this->formatar($servico),
        ], 201)->comHeader('Location', "/api/servicos/{$servico['id']}");
    }

    // PUT /api/servicos/{id} (admin)
    public function atualizar(Request $request): Response
    {
        $id = $request->params['id'];

        if (!Uuid::valido($id)) {
            return $this->idInvalido($id);
        }

        if (!$this->encontrar($id)) {
            return $this->naoEncontrado($id);
        }

        $dados = $request->corpo;
        $erros = $this->validar($dados);

        if ($erros) {
            return $this->dadosInvalidos($erros);
        }

        $stmt = Database::conexao()->prepare(
            'WITH alterado AS (
                UPDATE servicos
                   SET nome = :nome, preco = :preco, descricao = :descricao, categoria_id = :categoria_id,
                       duracao_minutos = COALESCE(:duracao_minutos, duracao_minutos), updated_at = NOW()
                 WHERE id = :id
                RETURNING *
             )
             SELECT ' . self::COLUNAS . ' FROM alterado s JOIN categorias c ON c.id = s.categoria_id'
        );
        $stmt->execute([...$this->parametros($dados), 'id' => $id]);
        $servico = $stmt->fetch();

        return Response::json([
            'mensagem' => "Serviço \"{$servico['nome']}\" atualizado com sucesso.",
            'servico'  => $this->formatar($servico),
        ]);
    }

    // DELETE /api/servicos/{id} (admin) — soft delete
    public function remover(Request $request): Response
    {
        $id = $request->params['id'];

        if (!Uuid::valido($id)) {
            return $this->idInvalido($id);
        }

        $servico = $this->encontrar($id);

        if (!$servico) {
            return $this->naoEncontrado($id);
        }

        // Só desativa: os agendamentos antigos continuam apontando para ele.
        $stmt = Database::conexao()->prepare(
            'UPDATE servicos SET ativo = false, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return Response::json([
            'mensagem' => "Serviço \"{$servico['nome']}\" removido. Ele não aparece mais na listagem, "
                . 'mas os agendamentos já feitos continuam registrados.',
            'id'       => $id,
        ]);
    }

    // --- auxiliares ---

    private function encontrar(string $id): array|false
    {
        $stmt = Database::conexao()->prepare(
            'SELECT ' . self::COLUNAS . self::DE . ' WHERE s.id = :id AND s.ativo = true'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    private function categoriaExiste(string $id): bool
    {
        $stmt = Database::conexao()->prepare(
            'SELECT 1 FROM categorias WHERE id = :id AND ativo = true'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    private function parametros(array $dados): array
    {
        $descricao = trim((string) ($dados['descricao'] ?? ''));

        return [
            'nome'      => trim($dados['nome']),
            'preco'     => $dados['preco'],
            'descricao' => $descricao === '' ? null : $descricao,
            'categoria_id' => $dados['categoria_id'],
            // Opcional: sem ela, o serviço novo fica com 60 e o existente mantém a atual.
            'duracao_minutos' => $dados['duracao_minutos'] ?? null,
        ];
    }

    // O Postgres devolve DECIMAL como string ("35.00"); a API devolve número.
    private function formatar(array $servico): array
    {
        return [
            ...$servico,
            'preco'           => (float) $servico['preco'],
            'duracao_minutos' => (int) $servico['duracao_minutos'],
        ];
    }

    private function idInvalido(string $id): Response
    {
        return Response::erro("O id \"{$id}\" não é válido. Use o id (UUID) retornado na listagem de serviços.", 400);
    }

    private function naoEncontrado(string $id): Response
    {
        return Response::erro("Nenhum serviço ativo encontrado com o id \"{$id}\".", 404);
    }

    private function dadosInvalidos(array $erros): Response
    {
        return Response::json([
            'erro'   => 'Não foi possível salvar o serviço. Corrija os campos indicados.',
            'campos' => $erros,
        ], 422);
    }

    private function validar(array $dados): array
    {
        $erros = [];

        if (!is_string($dados['nome'] ?? null) || trim($dados['nome']) === '') {
            $erros['nome'] = 'O nome é obrigatório. Ex.: "Manicure simples".';
        } elseif (mb_strlen(trim($dados['nome'])) > 100) {
            $erros['nome'] = 'O nome deve ter no máximo 100 caracteres (enviado: ' . mb_strlen(trim($dados['nome'])) . ').';
        }

        if (!isset($dados['preco'])) {
            $erros['preco'] = 'O preço é obrigatório. Ex.: 35.90';
        } elseif (!is_numeric($dados['preco'])) {
            $erros['preco'] = 'O preço deve ser um número, usando ponto para os centavos. Ex.: 35.90';
        } elseif ($dados['preco'] < 0) {
            $erros['preco'] = 'O preço não pode ser negativo.';
        } elseif ($dados['preco'] > 99999999.99) {
            $erros['preco'] = 'O preço máximo permitido é 99999999.99.';
        }

        // Obrigatória: é ela que agrupa o serviço no catálogo e no filtro do
        // cliente, então um serviço sem categoria não teria onde aparecer.
        $categoriaId = $dados['categoria_id'] ?? null;
        if (!is_string($categoriaId) || trim($categoriaId) === '') {
            $erros['categoria_id'] = 'A categoria é obrigatória. Escolha uma das cadastradas.';
        } elseif (!Uuid::valido($categoriaId)) {
            $erros['categoria_id'] = 'Informe o id (UUID) de uma categoria cadastrada.';
        } elseif (!$this->categoriaExiste($categoriaId)) {
            $erros['categoria_id'] = 'Essa categoria não existe mais. Recarregue a lista de categorias.';
        }

        if (isset($dados['duracao_minutos'])) {
            $duracao = $dados['duracao_minutos'];
            if (!is_int($duracao) || $duracao < 15 || $duracao > 480 || $duracao % 5 !== 0) {
                $erros['duracao_minutos'] = 'A duração deve ser um número inteiro de minutos, entre 15 e 480, '
                    . 'em múltiplos de 5. Ex.: 60';
            }
        }

        if (isset($dados['descricao']) && !is_string($dados['descricao'])) {
            $erros['descricao'] = 'A descrição deve ser um texto.';
        }

        return $erros;
    }
}
