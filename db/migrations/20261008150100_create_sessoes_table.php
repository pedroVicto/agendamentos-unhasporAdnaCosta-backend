<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSessoesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sessoes')
            ->addColumn('usuario_id', 'integer', ['null' => false])
            // SHA-256 do token que vai no cookie; o token em si nunca é gravado.
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expira_em', 'timestamp', ['null' => false])
            ->addColumn('revogada_em', 'timestamp', ['null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_sessoes_token_hash'])
            ->addIndex(['usuario_id'])
            ->addForeignKey('usuario_id', 'usuarios', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_sessoes_usuario',
            ])
            ->create();
    }
}
