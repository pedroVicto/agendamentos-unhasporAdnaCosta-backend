<?php

declare(strict_types=1);

namespace App\Controllers\Servicos;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;

final class ServicoController
{
    private const COLUNAS = 'id, nome, preco, descricao, duracao_minutos, created_at, updated_at';

    // GET /api/servicos (público)
    public function listar(Request $request): Response
    {
        $servicos = Database::conexao()
            ->query('SELECT ' . self::COLUNAS . ' FROM servicos WHERE ativo = true ORDER BY nome')
            ->fetchAll();

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
            'INSERT INTO servicos (nome, preco, descricao, duracao_minutos)
             VALUES (:nome, :preco, :descricao, COALESCE(:duracao_minutos, 60))
             RETURNING ' . self::COLUNAS
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
            'UPDATE servicos
             SET nome = :nome, preco = :preco, descricao = :descricao,
                 duracao_minutos = COALESCE(:duracao_minutos, duracao_minutos), updated_at = NOW()
             WHERE id = :id
             RETURNING ' . self::COLUNAS
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
            'SELECT ' . self::COLUNAS . ' FROM servicos WHERE id = :id AND ativo = true'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    private function parametros(array $dados): array
    {
        $descricao = trim((string) ($dados['descricao'] ?? ''));

        return [
            'nome'      => trim($dados['nome']),
            'preco'     => $dados['preco'],
            'descricao' => $descricao === '' ? null : $descricao,
            // Opcional: sem ela, o serviço novo fica com 60 e o existente mantém a atual.
            'duracao_minutos' => $dados['duracao_minutos'] ?? null,
        ];
    }

    // O Postgres devolve DECIMAL como string ("35.00"); a API devolve número.
    private function formatar(array $servico): array
    {
        return [...$servico, 'preco' => (float) $servico['preco'], 'duracao_minutos' => (int) $servico['duracao_minutos']];
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
