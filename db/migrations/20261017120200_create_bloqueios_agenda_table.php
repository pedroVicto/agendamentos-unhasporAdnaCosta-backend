<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateBloqueiosAgendaTable extends AbstractMigration
{
    // Períodos pontuais em que não há atendimento, mesmo dentro do
    // expediente (imprevisto, folga, feriado...). O motivo é interno:
    // a rota pública de horários nunca o mostra.
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE bloqueios_agenda (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                inicio TIMESTAMP NOT NULL,
                fim TIMESTAMP NOT NULL,
                motivo VARCHAR(150) NOT NULL,
                criado_por INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT ck_bloqueios_periodo CHECK (fim > inicio),
                CONSTRAINT fk_bloqueios_usuario FOREIGN KEY (criado_por)
                    REFERENCES usuarios (id) ON DELETE SET NULL ON UPDATE CASCADE
            );

            CREATE INDEX idx_bloqueios_periodo ON bloqueios_agenda (inicio, fim);
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE bloqueios_agenda');
    }
}
