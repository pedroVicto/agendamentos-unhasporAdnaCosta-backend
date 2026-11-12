<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCategoriaToServicos extends AbstractMigration
{
    // Em que grupo da tabela de preços o serviço entra. É por ela que o
    // cliente filtra o catálogo, então o conjunto é fechado: texto livre
    // viraria "Alongamento" e "alongamento" como grupos diferentes.
    public function up(): void
    {
        $this->execute(<<<'SQL'
            ALTER TABLE servicos ADD COLUMN categoria TEXT;

            -- Os serviços que já existiam ficam em "outros" até a supervisora
            -- reclassificá-los pela tela de serviços.
            UPDATE servicos SET categoria = 'outros' WHERE categoria IS NULL;

            ALTER TABLE servicos
                ALTER COLUMN categoria SET NOT NULL,
                ADD CONSTRAINT ck_servicos_categoria CHECK (categoria IN (
                    'alongamento', 'manutencao', 'reposicao_remocao', 'postica', 'outros'
                ));
            SQL);
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE servicos DROP COLUMN categoria');
    }
}
