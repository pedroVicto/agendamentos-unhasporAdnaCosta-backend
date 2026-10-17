<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateExpedienteTable extends AbstractMigration
{
    // Horário de funcionamento da semana. Um dia pode ter mais de uma faixa
    // (ex.: 09:00-12:00 e 13:00-18:00, deixando o almoço de fora); um dia sem
    // nenhuma faixa é um dia fechado.
    public function up(): void
    {
        $this->table('expediente')
            // 0 = domingo ... 6 = sábado (o mesmo do date('w') do PHP)
            ->addColumn('dia_semana', 'smallinteger', ['null' => false])
            ->addColumn('hora_inicio', 'time', ['null' => false])
            ->addColumn('hora_fim', 'time', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['dia_semana'])
            ->create();

        $this->execute(
            'ALTER TABLE expediente
                ADD CONSTRAINT ck_expediente_dia CHECK (dia_semana BETWEEN 0 AND 6),
                ADD CONSTRAINT ck_expediente_faixa CHECK (hora_fim > hora_inicio)'
        );
    }

    public function down(): void
    {
        $this->table('expediente')->drop()->save();
    }
}
