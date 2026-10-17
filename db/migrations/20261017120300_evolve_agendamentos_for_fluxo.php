<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class EvolveAgendamentosForFluxo extends AbstractMigration
{
    // Prepara a tabela para o fluxo "cliente pede, supervisor responde":
    // - id vira UUID (pelo mesmo motivo dos serviços);
    // - o fim do atendimento é gravado, congelando a duração do serviço no
    //   momento do pedido;
    // - quem respondeu, quando e por quê;
    // - o próprio banco impede dois agendamentos ativos no mesmo horário.
    public function up(): void
    {
        $this->execute(<<<'SQL'
            ALTER TABLE agendamentos ADD COLUMN uuid UUID NOT NULL DEFAULT gen_random_uuid();
            ALTER TABLE agendamentos DROP COLUMN id;
            ALTER TABLE agendamentos RENAME COLUMN uuid TO id;
            ALTER TABLE agendamentos ADD PRIMARY KEY (id);

            ALTER TABLE agendamentos ADD COLUMN data_hora_fim TIMESTAMP;
            UPDATE agendamentos a
               SET data_hora_fim = a.data_hora + s.duracao_minutos * INTERVAL '1 minute'
              FROM servicos s
             WHERE s.id = a.servico_id;
            ALTER TABLE agendamentos ALTER COLUMN data_hora_fim SET NOT NULL;

            ALTER TABLE agendamentos
                ADD COLUMN respondido_por INTEGER NULL,
                ADD COLUMN respondido_em TIMESTAMP NULL,
                -- Motivo da recusa ou do cancelamento, mostrado ao cliente.
                ADD COLUMN motivo VARCHAR(255) NULL,
                ADD COLUMN cancelado_por VARCHAR(20) NULL,
                ADD CONSTRAINT fk_agendamentos_respondido_por FOREIGN KEY (respondido_por)
                    REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE,
                ADD CONSTRAINT ck_agendamentos_status
                    CHECK (status IN ('pendente', 'confirmado', 'recusado', 'cancelado')),
                ADD CONSTRAINT ck_agendamentos_cancelado_por
                    CHECK (cancelado_por IS NULL OR cancelado_por IN ('cliente', 'supervisor')),
                ADD CONSTRAINT ck_agendamentos_periodo CHECK (data_hora_fim > data_hora);

            -- Dois agendamentos ativos (pendente ou confirmado) não podem se
            -- sobrepor. Mesmo com duas requisições chegando juntas, o banco
            -- recusa a segunda (SQLSTATE 23P01).
            ALTER TABLE agendamentos
                ADD CONSTRAINT ex_agendamentos_sem_sobreposicao
                EXCLUDE USING gist (tsrange(data_hora, data_hora_fim) WITH &&)
                WHERE (status IN ('pendente', 'confirmado'));

            CREATE INDEX idx_agendamentos_status ON agendamentos (status);
            SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Os ids sequenciais antigos não podem ser recuperados.');
    }
}
