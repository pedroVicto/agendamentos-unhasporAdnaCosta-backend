<?php

declare(strict_types=1);

namespace App\Controllers\Agendamentos;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Services\AgendaService;
use App\Services\Notificacao\AvisoAgendamento;
use PDOException;

final class AgendamentoController
{
    private const STATUS = ['pendente', 'confirmado', 'recusado', 'cancelado'];

    // --- cliente ---

    // GET /api/cliente/agendamentos
    public function meus(Request $request): Response
    {
        // O filtro usa o id da sessão, nunca um id vindo da requisição:
        // é isso que impede um cliente de ver a agenda de outro.
        $busca = Database::conexao()->prepare(
            'SELECT a.id, a.data_hora, a.data_hora_fim, a.status, a.motivo, a.observacoes,
                    a.servico_id, s.nome AS servico, s.preco
               FROM agendamentos a
               JOIN servicos s ON s.id = a.servico_id
              WHERE a.usuario_id = :usuario_id
              ORDER BY a.data_hora DESC'
        );
        $busca->execute(['usuario_id' => $request->usuario['id']]);

        return Response::json(['agendamentos' => array_map($this->formatar(...), $busca->fetchAll())]);
    }

    // POST /api/cliente/agendamentos  {servico_id, data_hora, observacoes?}
    public function criar(Request $request): Response
    {
        $servicoId = $request->input('servico_id');
        $inicio = AgendaService::lerDataHora($request->corpo['data_hora'] ?? null);
        $observacoes = $request->input('observacoes');

        $erros = [];
        if ($servicoId === null || !Uuid::valido($servicoId)) {
            $erros['servico_id'] = 'Informe o id (UUID) do serviço.';
        }
        if ($inicio === null) {
            $erros['data_hora'] = 'Informe a data e a hora no formato AAAA-MM-DDTHH:MM. Ex.: 2026-10-20T14:00';
        }
        if ($observacoes !== null && mb_strlen($observacoes) > 500) {
            $erros['observacoes'] = 'As observações devem ter no máximo 500 caracteres.';
        }
        if ($erros) {
            return $this->dadosInvalidos($erros);
        }

        $pdo = Database::conexao();
        $busca = $pdo->prepare('SELECT id, nome, duracao_minutos FROM servicos WHERE id = :id AND ativo = true');
        $busca->execute(['id' => $servicoId]);
        $servico = $busca->fetch();

        if (!$servico) {
            return Response::erro('Serviço não encontrado ou indisponível.', 404);
        }

        $fim = $inicio->modify('+' . (int) $servico['duracao_minutos'] . ' minutes');
        $usuarioId = $request->usuario['id'];

        $pdo->beginTransaction();
        try {
            // Com a trava, ninguém cria agendamento ou bloqueio entre a
            // checagem abaixo e o INSERT.
            AgendaService::travar($pdo);

            $pendentes = $pdo->prepare(
                "SELECT COUNT(*) FROM agendamentos
                  WHERE usuario_id = :usuario_id AND status = 'pendente' AND data_hora > :agora"
            );
            $pendentes->execute(['usuario_id' => $usuarioId, 'agora' => AgendaService::agora()]);
            $maximo = AgendaService::config('AGENDA_MAX_PENDENTES', 3);

            if ((int) $pendentes->fetchColumn() >= $maximo) {
                $pdo->rollBack();

                return Response::erro(
                    "Você já tem {$maximo} pedido(s) aguardando resposta. Espere a confirmação ou cancele um deles.",
                    429
                );
            }

            if (!(new AgendaService())->disponivel($servico, $inicio)) {
                $pdo->rollBack();

                return Response::erro(
                    'Esse horário não está disponível. Consulte os horários livres em '
                        . "/api/servicos/{$servico['id']}/horarios?data=" . $inicio->format('Y-m-d'),
                    422
                );
            }

            // Nome e dono vêm da sessão, nunca do corpo da requisição.
            $insercao = $pdo->prepare(
                'INSERT INTO agendamentos (usuario_id, nome_cliente, servico_id, data_hora, data_hora_fim, observacoes)
                 VALUES (:usuario_id, :nome, :servico_id, :inicio, :fim, :observacoes)
                 RETURNING id'
            );
            $insercao->execute([
                'usuario_id'  => $usuarioId,
                'nome'        => $request->usuario['nome'],
                'servico_id'  => $servico['id'],
                'inicio'      => AgendaService::formatoBanco($inicio),
                'fim'         => AgendaService::formatoBanco($fim),
                'observacoes' => $observacoes,
            ]);
            $id = $insercao->fetchColumn();

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            // ex_agendamentos_sem_sobreposicao: a última linha de defesa.
            if ($e->getCode() === '23P01') {
                return Response::erro('Esse horário acabou de ser reservado. Escolha outro.', 409);
            }
            throw $e;
        }

        AvisoAgendamento::novoPedido($id);

        return Response::json([
            'mensagem'    => 'Pedido enviado! Você será avisado quando ele for confirmado.',
            'agendamento' => $this->formatar($this->buscar($id)),
        ], 201)->comHeader('Location', "/api/cliente/agendamentos/{$id}");
    }

    // PATCH /api/cliente/agendamentos/{id}/cancelar
    public function cancelarPeloCliente(Request $request): Response
    {
        $id = $request->params['id'];
        if (!Uuid::valido($id)) {
            return $this->naoEncontrado();
        }

        $horas = AgendaService::config('AGENDA_CANCELAMENTO_HORAS', 12);
        $pdo = Database::conexao();

        // Pendente pode ser cancelado a qualquer momento antes do horário;
        // confirmado só com a antecedência mínima.
        $atualizacao = $pdo->prepare(
            "UPDATE agendamentos
                SET status = 'cancelado', cancelado_por = 'cliente', updated_at = NOW()
              WHERE id = :id AND usuario_id = :usuario_id AND data_hora > :agora
                AND (status = 'pendente' OR (status = 'confirmado' AND data_hora >= :limite))
              RETURNING id"
        );
        $atualizacao->execute([
            'id'         => $id,
            'usuario_id' => $request->usuario['id'],
            'agora'      => AgendaService::agora(),
            'limite'     => AgendaService::formatoBanco(new \DateTimeImmutable("+{$horas} hours")),
        ]);

        if ($atualizacao->fetchColumn() === false) {
            // Agendamento de outro cliente cai aqui como 404: não revela que existe.
            $atual = $pdo->prepare(
                'SELECT status, data_hora > :agora AS futuro FROM agendamentos WHERE id = :id AND usuario_id = :usuario_id'
            );
            $atual->execute(['agora' => AgendaService::agora(), 'id' => $id, 'usuario_id' => $request->usuario['id']]);
            $atual = $atual->fetch();

            return match (true) {
                $atual === false => $this->naoEncontrado(),
                !in_array($atual['status'], ['pendente', 'confirmado'], true) => Response::erro("Este agendamento já está {$atual['status']}.", 409),
                !$atual['futuro'] => Response::erro('Este agendamento já passou.', 422),
                default => Response::erro(
                    "Agendamentos confirmados só podem ser cancelados com {$horas} horas de antecedência. Entre em contato com o salão.",
                    422
                ),
            };
        }

        AvisoAgendamento::canceladoPeloCliente($id);

        return Response::json(['agendamento' => $this->formatar($this->buscar($id))]);
    }

    // --- supervisor / admin ---

    // GET /api/supervisor/agendamentos?status=pendente&de=2026-10-01&ate=2026-10-31
    public function todos(Request $request): Response
    {
        $filtros = [];
        $parametros = [];

        $status = $request->query['status'] ?? null;
        if ($status !== null) {
            if (!in_array($status, self::STATUS, true)) {
                return Response::erro('Status inválido. Use: ' . implode(', ', self::STATUS) . '.', 422);
            }
            $filtros[] = 'a.status = :status';
            $parametros['status'] = $status;
        }

        foreach (['de' => '>=', 'ate' => '<'] as $campo => $operador) {
            if (!isset($request->query[$campo])) {
                continue;
            }
            $data = AgendaService::lerData($request->query[$campo]);
            if ($data === null) {
                return Response::erro("Informe \"{$campo}\" no formato AAAA-MM-DD.", 422);
            }
            // "ate" inclui o dia inteiro.
            $filtros[] = "a.data_hora {$operador} :{$campo}";
            $parametros[$campo] = AgendaService::formatoBanco($campo === 'ate' ? $data->modify('+1 day') : $data);
        }

        $busca = Database::conexao()->prepare(
            'SELECT a.id, a.nome_cliente, a.usuario_id, u.email AS email_cliente, u.telefone AS telefone_cliente,
                    a.data_hora, a.data_hora_fim, a.status, a.motivo, a.cancelado_por, a.observacoes,
                    a.servico_id, s.nome AS servico, s.preco,
                    r.nome AS respondido_por, a.respondido_em, a.created_at
               FROM agendamentos a
               JOIN servicos s ON s.id = a.servico_id
               LEFT JOIN usuarios u ON u.id = a.usuario_id
               LEFT JOIN usuarios r ON r.id = a.respondido_por'
            . ($filtros ? ' WHERE ' . implode(' AND ', $filtros) : '')
            . ' ORDER BY a.data_hora DESC'
        );
        $busca->execute($parametros);

        return Response::json(['agendamentos' => array_map($this->formatar(...), $busca->fetchAll())]);
    }

    // PATCH /api/supervisor/agendamentos/{id}/confirmar
    public function confirmar(Request $request): Response
    {
        return $this->responder($request, 'pendente', 'confirmado', false);
    }

    // PATCH /api/supervisor/agendamentos/{id}/recusar  {motivo}
    public function recusar(Request $request): Response
    {
        return $this->responder($request, 'pendente', 'recusado', true);
    }

    // PATCH /api/supervisor/agendamentos/{id}/cancelar  {motivo}
    // Para desfazer um confirmado; pendente se recusa.
    public function cancelarPeloSupervisor(Request $request): Response
    {
        return $this->responder($request, 'confirmado', 'cancelado', true);
    }

    // --- auxiliares ---

    /**
     * Transição de status numa única instrução: o WHERE com o status de
     * origem garante que, se dois supervisores clicarem juntos, só um vence.
     */
    private function responder(Request $request, string $de, string $para, bool $exigeMotivo): Response
    {
        $id = $request->params['id'];
        if (!Uuid::valido($id)) {
            return $this->naoEncontrado();
        }

        $motivo = $request->input('motivo');
        if ($exigeMotivo && $motivo === null) {
            return $this->dadosInvalidos(['motivo' => 'Informe o motivo; ele será enviado ao cliente.']);
        }
        if ($motivo !== null && mb_strlen($motivo) > 255) {
            return $this->dadosInvalidos(['motivo' => 'O motivo deve ter no máximo 255 caracteres.']);
        }

        $pdo = Database::conexao();
        $atualizacao = $pdo->prepare(
            'UPDATE agendamentos
                SET status = :para, motivo = :motivo, respondido_por = :usuario_id, respondido_em = NOW(),
                    cancelado_por = :cancelado_por, updated_at = NOW()
              WHERE id = :id AND status = :de AND data_hora > :agora
              RETURNING id'
        );
        $atualizacao->execute([
            'para'          => $para,
            'motivo'        => $motivo,
            'usuario_id'    => $request->usuario['id'],
            'cancelado_por' => $para === 'cancelado' ? 'supervisor' : null,
            'id'            => $id,
            'de'            => $de,
            'agora'         => AgendaService::agora(),
        ]);

        if ($atualizacao->fetchColumn() === false) {
            $atual = $pdo->prepare('SELECT status, data_hora > :agora AS futuro FROM agendamentos WHERE id = :id');
            $atual->execute(['agora' => AgendaService::agora(), 'id' => $id]);
            $atual = $atual->fetch();

            return match (true) {
                $atual === false => $this->naoEncontrado(),
                $atual['status'] !== $de => Response::erro(
                    "Este agendamento está {$atual['status']}; só é possível deixá-lo {$para} quando está {$de}.",
                    409
                ),
                default => Response::erro('O horário deste agendamento já passou.', 422),
            };
        }

        AvisoAgendamento::respostaAoCliente($id);

        return Response::json(['agendamento' => $this->formatar($this->buscar($id))]);
    }

    private function buscar(string $id): array
    {
        $busca = Database::conexao()->prepare(
            'SELECT a.id, a.data_hora, a.data_hora_fim, a.status, a.motivo, a.observacoes,
                    a.servico_id, s.nome AS servico, s.preco
               FROM agendamentos a
               JOIN servicos s ON s.id = a.servico_id
              WHERE a.id = :id'
        );
        $busca->execute(['id' => $id]);

        return $busca->fetch();
    }

    // O Postgres devolve DECIMAL como string ("35.00"); a API devolve número.
    private function formatar(array $agendamento): array
    {
        return [...$agendamento, 'preco' => (float) $agendamento['preco']];
    }

    private function naoEncontrado(): Response
    {
        return Response::erro('Agendamento não encontrado.', 404);
    }

    private function dadosInvalidos(array $erros): Response
    {
        return Response::json([
            'erro'   => 'Não foi possível concluir. Corrija os campos indicados.',
            'campos' => $erros,
        ], 422);
    }
}
