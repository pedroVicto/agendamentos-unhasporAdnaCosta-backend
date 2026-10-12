<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class AdminSeeder extends AbstractSeed
{
    // Cria o primeiro admin a partir do .env. É o único jeito de um admin
    // nascer: nenhuma rota da API cria ou promove alguém a admin.
    public function run(): void
    {
        $email = mb_strtolower(trim((string) getenv('ADMIN_EMAIL')));
        $senha = (string) getenv('ADMIN_SENHA');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 10) {
            throw new RuntimeException('Defina ADMIN_EMAIL e ADMIN_SENHA (mínimo 10 caracteres) no .env.');
        }

        $pdo = $this->getAdapter()->getConnection();

        $busca = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email');
        $busca->execute(['email' => $email]);
        if ($busca->fetchColumn() !== false) {
            $this->output->writeln("Usuário {$email} já existe; nada a fazer.");

            return;
        }

        $pdo->prepare(
            "INSERT INTO usuarios (nome, email, senha_hash, papel, verificado_em)
             VALUES ('Administrador', :email, :hash, 'admin', NOW())"
        )->execute([
            'email' => $email,
            'hash' => password_hash($senha, PASSWORD_ARGON2ID),
        ]);

        $this->output->writeln("Admin {$email} criado.");
    }
}
