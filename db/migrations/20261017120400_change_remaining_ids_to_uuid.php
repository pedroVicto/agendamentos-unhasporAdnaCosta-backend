<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class ChangeRemainingIdsToUuid extends AbstractMigration
{
    // Leva para as tabelas restantes o que já foi feito em servicos e
    // agendamentos: o id sequencial vira UUID aleatório. Em usuarios isso
    // importa mais ainda, porque o id aparece na API (/auth/me, admin).
    // Os registros existentes ganham um UUID e todas as referências a
    // usuarios continuam apontando para a mesma pessoa.
    public function up(): void
    {
        $this->execute(<<<'SQL'
            -- 1) Solta tudo que aponta para usuarios.id.
            ALTER TABLE codigos_verificacao DROP CONSTRAINT fk_codigos_verificacao_usuario;
            ALTER TABLE sessoes DROP CONSTRAINT fk_sessoes_usuario;
            ALTER TABLE agendamentos DROP CONSTRAINT fk_agendamentos_usuario;
            ALTER TABLE agendamentos DROP CONSTRAINT fk_agendamentos_respondido_por;
            ALTER TABLE bloqueios_agenda DROP CONSTRAINT fk_bloqueios_usuario;

            ALTER TABLE usuarios ADD COLUMN uuid UUID NOT NULL DEFAULT gen_random_uuid();

            -- 2) Cada coluna que referencia usuarios ganha o UUID correspondente.
            ALTER TABLE codigos_verificacao ADD COLUMN usuario_uuid UUID;
            UPDATE codigos_verificacao c SET usuario_uuid = u.uuid FROM usuarios u WHERE u.id = c.usuario_id;
            ALTER TABLE codigos_verificacao DROP COLUMN usuario_id;
            ALTER TABLE codigos_verificacao RENAME COLUMN usuario_uuid TO usuario_id;
            ALTER TABLE codigos_verificacao ALTER COLUMN usuario_id SET NOT NULL;
            CREATE INDEX idx_codigos_verificacao_usuario ON codigos_verificacao (usuario_id, created_at);

            ALTER TABLE sessoes ADD COLUMN usuario_uuid UUID;
            UPDATE sessoes s SET usuario_uuid = u.uuid FROM usuarios u WHERE u.id = s.usuario_id;
            ALTER TABLE sessoes DROP COLUMN usuario_id;
            ALTER TABLE sessoes RENAME COLUMN usuario_uuid TO usuario_id;
            ALTER TABLE sessoes ALTER COLUMN usuario_id SET NOT NULL;
            CREATE INDEX idx_sessoes_usuario ON sessoes (usuario_id);

            ALTER TABLE agendamentos ADD COLUMN usuario_uuid UUID;
            UPDATE agendamentos a SET usuario_uuid = u.uuid FROM usuarios u WHERE u.id = a.usuario_id;
            ALTER TABLE agendamentos DROP COLUMN usuario_id;
            ALTER TABLE agendamentos RENAME COLUMN usuario_uuid TO usuario_id;
            CREATE INDEX idx_agendamentos_usuario ON agendamentos (usuario_id);

            ALTER TABLE agendamentos ADD COLUMN respondido_por_uuid UUID;
            UPDATE agendamentos a SET respondido_por_uuid = u.uuid FROM usuarios u WHERE u.id = a.respondido_por;
            ALTER TABLE agendamentos DROP COLUMN respondido_por;
            ALTER TABLE agendamentos RENAME COLUMN respondido_por_uuid TO respondido_por;

            ALTER TABLE bloqueios_agenda ADD COLUMN criado_por_uuid UUID;
            UPDATE bloqueios_agenda b SET criado_por_uuid = u.uuid FROM usuarios u WHERE u.id = b.criado_por;
            ALTER TABLE bloqueios_agenda DROP COLUMN criado_por;
            ALTER TABLE bloqueios_agenda RENAME COLUMN criado_por_uuid TO criado_por;

            -- 3) Troca a chave de usuarios e refaz as chaves estrangeiras.
            ALTER TABLE usuarios DROP COLUMN id;
            ALTER TABLE usuarios RENAME COLUMN uuid TO id;
            ALTER TABLE usuarios ADD PRIMARY KEY (id);

            ALTER TABLE codigos_verificacao
                ADD CONSTRAINT fk_codigos_verificacao_usuario FOREIGN KEY (usuario_id)
                REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE;
            ALTER TABLE sessoes
                ADD CONSTRAINT fk_sessoes_usuario FOREIGN KEY (usuario_id)
                REFERENCES usuarios (id) ON DELETE CASCADE ON UPDATE CASCADE;
            ALTER TABLE agendamentos
                ADD CONSTRAINT fk_agendamentos_usuario FOREIGN KEY (usuario_id)
                REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE,
                ADD CONSTRAINT fk_agendamentos_respondido_por FOREIGN KEY (respondido_por)
                REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE;
            ALTER TABLE bloqueios_agenda
                ADD CONSTRAINT fk_bloqueios_usuario FOREIGN KEY (criado_por)
                REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE;

            -- 4) Tabelas que ninguém referencia: só troca o id.
            ALTER TABLE codigos_verificacao ADD COLUMN uuid UUID NOT NULL DEFAULT gen_random_uuid();
            ALTER TABLE codigos_verificacao DROP COLUMN id;
            ALTER TABLE codigos_verificacao RENAME COLUMN uuid TO id;
            ALTER TABLE codigos_verificacao ADD PRIMARY KEY (id);

            ALTER TABLE sessoes ADD COLUMN uuid UUID NOT NULL DEFAULT gen_random_uuid();
            ALTER TABLE sessoes DROP COLUMN id;
            ALTER TABLE sessoes RENAME COLUMN uuid TO id;
            ALTER TABLE sessoes ADD PRIMARY KEY (id);

            ALTER TABLE expediente ADD COLUMN uuid UUID NOT NULL DEFAULT gen_random_uuid();
            ALTER TABLE expediente DROP COLUMN id;
            ALTER TABLE expediente RENAME COLUMN uuid TO id;
            ALTER TABLE expediente ADD PRIMARY KEY (id);
            SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Os ids sequenciais antigos não podem ser recuperados.');
    }
}
