<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddDuracaoToServicos extends AbstractMigration
{
    public function up(): void
    {
        // Quanto tempo o atendimento ocupa na agenda. É ela que define
        // quais horários cabem dentro do expediente.
        $this->execute(
            'ALTER TABLE servicos
                ADD COLUMN duracao_minutos INTEGER NOT NULL DEFAULT 60,
                ADD CONSTRAINT ck_servicos_duracao CHECK (duracao_minutos BETWEEN 15 AND 480)'
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE servicos DROP COLUMN duracao_minutos');
    }
}
