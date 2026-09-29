<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateServicosTable extends AbstractMigration
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
        $table = $this->table('servicos', ['id' => true, 'primary_key' => 'id']);

        $table
            ->addColumn('nome', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('preco', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => false])
            ->addColumn('descricao', 'text', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->create();
    }
}
