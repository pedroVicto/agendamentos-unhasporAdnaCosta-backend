<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTrocarSenhaToUsuarios extends AbstractMigration
{
    public function change(): void
    {
        $this->table('usuarios')
            // Verdadeiro enquanto o supervisor estiver com a senha temporária
            // enviada por e-mail; só sai de true quando ele define a própria.
            ->addColumn('trocar_senha', 'boolean', ['default' => false, 'null' => false])
            ->update();
    }
}
