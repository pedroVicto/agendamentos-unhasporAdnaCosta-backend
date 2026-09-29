<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAgendamentosTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        $table = $this->table('agendamentos', ['id' => true, 'primary_key' => 'id']);

        $table
            // Nome do cliente guardado direto na tabela (não é FK para
            // "usuarios"): útil quando quem agenda não precisa ter
            // login no sistema, só o funcionário/admin tem.
            ->addColumn('nome_cliente', 'string', ['limit' => 150, 'null' => false])

            // Chave estrangeira para servicos.id
            ->addColumn('servico_id', 'integer', ['null' => false])

            ->addColumn('data_hora', 'timestamp', ['null' => false])

            // Estado do agendamento; enum simples via string com limite.
            ->addColumn('status', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'pendente', // pendente | confirmado | cancelado
            ])

            ->addColumn('observacoes', 'text', ['null' => true])

            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])

            // Índice para consultas por data (agenda do dia, etc.)
            ->addIndex(['data_hora'])

            // A chave estrangeira em si: aponta servico_id -> servicos.id.
            // onDelete RESTRICT: impede apagar um serviço que já tem
            // agendamentos vinculados a ele.
            ->addForeignKey('servico_id', 'servicos', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_agendamentos_servico',
            ])

            ->create();
    }
}
