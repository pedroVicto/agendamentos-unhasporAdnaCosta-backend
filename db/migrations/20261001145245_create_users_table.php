<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUsersTable extends AbstractMigration
{
    public function change(): void
    {
         $this->table('usuarios')
            ->addColumn('email', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('telefone', 'string', ['limit' => 20, 'null' => true]) 
            ->addColumn('senha_hash', 'string', ['limit' => 255, 'null' => true]) 
            ->addColumn('google_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('papel', 'enum', [
                'values'  => ['cliente', 'supervisor', 'admin'],
                'default' => 'cliente',
                'null'    => false,
            ])
            ->addColumn('ativo', 'boolean', ['default' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['email'], ['unique' => true, 'name' => 'uq_usuarios_email'])
            ->addIndex(['telefone'], ['unique' => true, 'name' => 'uq_usuarios_telefone'])
            ->addIndex(['google_id'], ['unique' => true, 'name' => 'uq_usuarios_google_id'])
            ->create();
    }
}
