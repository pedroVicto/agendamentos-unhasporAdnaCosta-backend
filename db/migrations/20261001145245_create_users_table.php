<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUsersTable extends AbstractMigration
{
    public function up(): void
    {
         $this->table('usuarios')
            ->addColumn('nome', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('telefone', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('senha_hash', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('google_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('papel', 'string', ['limit' => 20, 'default' => 'cliente', 'null' => false])
            ->addColumn('ativo', 'boolean', ['default' => true])
            // Preenchido quando o cliente confirma o código pela primeira vez.
            ->addColumn('verificado_em', 'timestamp', ['null' => true])
            // Controle de força bruta no login por senha (supervisor/admin).
            ->addColumn('tentativas_login', 'integer', ['default' => 0, 'null' => false])
            ->addColumn('bloqueado_ate', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['email'], ['unique' => true, 'name' => 'uq_usuarios_email'])
            ->addIndex(['telefone'], ['unique' => true, 'name' => 'uq_usuarios_telefone'])
            ->addIndex(['google_id'], ['unique' => true, 'name' => 'uq_usuarios_google_id'])
            ->create();

        // O Phinx não tem tipo enum no Postgres; a restrição CHECK faz o mesmo papel.
        $this->execute(
            "ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_papel
             CHECK (papel IN ('cliente', 'supervisor', 'admin'))"
        );
    }

    public function down(): void
    {
        $this->table('usuarios')->drop()->save();
    }
}
