<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class ChangeServicosIdToUuid extends AbstractMigration
{
    // Troca o id sequencial (1, 2, 3...) por um UUID aleatório, para que o id
    // não revele quantos serviços existem nem permita "chutar" o próximo.
    // Os serviços já cadastrados ganham um UUID e os agendamentos continuam
    // apontando para o mesmo serviço.
    public function up(): void
    {
        $this->execute(<<<'SQL'
            -- "ativo" foi adicionado à CreateServicosTable depois que ela já tinha
            -- rodado em alguns bancos; IF NOT EXISTS cobre os dois casos.
            ALTER TABLE servicos ADD COLUMN IF NOT EXISTS ativo BOOLEAN NOT NULL DEFAULT true;

            ALTER TABLE agendamentos DROP CONSTRAINT fk_agendamentos_servico;

            ALTER TABLE servicos ADD COLUMN uuid UUID NOT NULL DEFAULT gen_random_uuid();

            ALTER TABLE agendamentos ADD COLUMN servico_uuid UUID;
            UPDATE agendamentos a SET servico_uuid = s.uuid FROM servicos s WHERE s.id = a.servico_id;
            ALTER TABLE agendamentos DROP COLUMN servico_id;
            ALTER TABLE agendamentos RENAME COLUMN servico_uuid TO servico_id;
            ALTER TABLE agendamentos ALTER COLUMN servico_id SET NOT NULL;

            ALTER TABLE servicos DROP COLUMN id;
            ALTER TABLE servicos RENAME COLUMN uuid TO id;
            ALTER TABLE servicos ADD PRIMARY KEY (id);

            ALTER TABLE agendamentos
                ADD CONSTRAINT fk_agendamentos_servico FOREIGN KEY (servico_id)
                REFERENCES servicos (id) ON DELETE RESTRICT ON UPDATE CASCADE;
            SQL);
    }

    public function down(): void
    {
        // Não dá para voltar de UUID para os números originais.
        throw new IrreversibleMigrationException('Os ids sequenciais antigos não podem ser recuperados.');
    }
}
