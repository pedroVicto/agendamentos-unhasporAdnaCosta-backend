<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCodigosVerificacaoTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('codigos_verificacao')
            ->addColumn('usuario_id', 'integer', ['null' => false])
            // email | sms | whatsapp
            ->addColumn('canal', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('destino', 'string', ['limit' => 150, 'null' => false])
            // HMAC-SHA256 do código; o código em si nunca é gravado.
            ->addColumn('codigo_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expira_em', 'timestamp', ['null' => false])
            ->addColumn('tentativas', 'integer', ['default' => 0, 'null' => false])
            ->addColumn('usado_em', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['usuario_id', 'created_at'])
            ->addForeignKey('usuario_id', 'usuarios', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_codigos_verificacao_usuario',
            ])
            ->create();
    }
}
