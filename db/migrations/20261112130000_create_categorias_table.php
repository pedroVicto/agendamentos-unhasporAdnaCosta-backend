<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCategoriasTable extends AbstractMigration
{
    // A categoria deixa de ser uma lista fixa no código e passa a ser
    // cadastrada pela Supervisora, como os serviços e a equipe. Nenhuma
    // categoria é criada por conta própria: o salão cria as suas.
    //
    // A migration anterior (AddCategoriaToServicos) pode ou não ter rodado
    // neste banco, então o bloco abaixo trata os dois casos. Onde a coluna de
    // texto existir, cada valor em uso vira uma categoria de verdade e os
    // serviços continuam apontando para a mesma coisa.
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE categorias (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                nome TEXT NOT NULL,
                ativo BOOLEAN NOT NULL DEFAULT true,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL
            );

            -- "Alongamento" e "alongamento" seriam a mesma categoria para quem
            -- lê a tela; o índice impede que virem duas.
            CREATE UNIQUE INDEX uq_categorias_nome ON categorias (lower(nome));

            ALTER TABLE servicos ADD COLUMN categoria_id UUID;

            DO $$
            DECLARE
                sem_categoria UUID;
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                     WHERE table_schema = current_schema()
                       AND table_name = 'servicos'
                       AND column_name = 'categoria'
                ) THEN
                    WITH nomes AS (
                        SELECT DISTINCT s.categoria AS valor,
                               CASE s.categoria
                                   WHEN 'alongamento'       THEN 'Alongamento'
                                   WHEN 'manutencao'        THEN 'Manutenção'
                                   WHEN 'reposicao_remocao' THEN 'Reposição e remoção'
                                   WHEN 'postica'           THEN 'Postiça'
                                   ELSE 'Sem categoria'
                               END AS nome
                          FROM servicos s
                    ), criadas AS (
                        INSERT INTO categorias (nome)
                        SELECT DISTINCT nome FROM nomes
                        RETURNING id, nome
                    )
                    UPDATE servicos s
                       SET categoria_id = criadas.id
                      FROM nomes JOIN criadas ON criadas.nome = nomes.nome
                     WHERE s.categoria = nomes.valor;

                    ALTER TABLE servicos DROP COLUMN categoria;
                ELSIF EXISTS (SELECT 1 FROM servicos) THEN
                    -- Serviços que vêm de antes da categoria existir: ficam
                    -- em "Sem categoria" até a Supervisora reclassificá-los.
                    INSERT INTO categorias (nome) VALUES ('Sem categoria')
                    RETURNING id INTO sem_categoria;

                    UPDATE servicos SET categoria_id = sem_categoria;
                END IF;
            END $$;

            ALTER TABLE servicos
                ALTER COLUMN categoria_id SET NOT NULL,
                ADD CONSTRAINT fk_servicos_categoria FOREIGN KEY (categoria_id)
                    REFERENCES categorias (id) ON DELETE RESTRICT ON UPDATE CASCADE;
            SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
            ALTER TABLE servicos DROP COLUMN categoria_id;
            DROP TABLE categorias;
            SQL);
    }
}
