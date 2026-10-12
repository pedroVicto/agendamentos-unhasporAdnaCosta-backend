<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddUsuarioIdToAgendamentos extends AbstractMigration
{
    public function change(): void
    {
        $this->table('agendamentos')
            // Dono do agendamento. Nulo quando o supervisor/admin agenda
            // para alguém que não tem login (aí vale só o nome_cliente).
            ->addColumn('usuario_id', 'integer', ['null' => true])
            ->addIndex(['usuario_id'])
            ->addForeignKey('usuario_id', 'usuarios', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_agendamentos_usuario',
            ])
            ->update();
    }
}
