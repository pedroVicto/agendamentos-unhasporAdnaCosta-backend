<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Services\Notificacao\Notificadores;
use PDO;
use PDOException;

// Login do cliente por código de 6 dígitos (sem senha).
final class OtpService
{
    private const VALIDADE_MINUTOS = 10;
    private const MAX_TENTATIVAS = 5;
    private const INTERVALO_SEGUNDOS = 60;
    private const MAX_POR_HORA = 5;

    /**
     * Gera e envia um código. Não devolve nada de propósito: quem chama
     * responde sempre igual, tenha o código sido enviado ou não.
     *
     * @param string $campo 'email' ou 'telefone'
     * @param string $canal 'email', 'sms' ou 'whatsapp'
     */
    public function solicitar(string $nome, string $campo, string $destino, string $canal): void
    {
        $pdo = Database::conexao();
        $usuario = $this->buscarOuCriarCliente($pdo, $nome, $campo, $destino);

        // Contato de supervisor/admin ou de conta desativada: não envia nada.
        if ($usuario === null) {
            return;
        }

        $limite = $pdo->prepare(
            "SELECT COUNT(*) FILTER (WHERE created_at > NOW() - (:intervalo * INTERVAL '1 second')) AS recentes,
                    COUNT(*) AS na_hora
               FROM codigos_verificacao
              WHERE usuario_id = :id
                AND created_at > NOW() - INTERVAL '1 hour'"
        );
        $limite->execute(['intervalo' => self::INTERVALO_SEGUNDOS, 'id' => $usuario['id']]);
        $contagem = $limite->fetch();

        if ($contagem['recentes'] > 0 || $contagem['na_hora'] >= self::MAX_POR_HORA) {
            return;
        }

        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $insercao = $pdo->prepare(
            "INSERT INTO codigos_verificacao (usuario_id, canal, destino, codigo_hash, expira_em)
             VALUES (:usuario_id, :canal, :destino, :hash, NOW() + (:minutos * INTERVAL '1 minute'))
             RETURNING id"
        );
        $insercao->execute([
            'usuario_id' => $usuario['id'],
            'canal' => $canal,
            'destino' => $destino,
            'hash' => self::hash($codigo),
            'minutos' => self::VALIDADE_MINUTOS,
        ]);
        $codigoId = $insercao->fetchColumn();

        try {
            Notificadores::para($canal)->enviarCodigo($canal, $destino, $codigo, self::VALIDADE_MINUTOS);
        } catch (\Throwable $e) {
            // Código que não chegou não pode contar no limite de reenvio.
            $pdo->prepare('DELETE FROM codigos_verificacao WHERE id = :id')->execute(['id' => $codigoId]);

            throw $e;
        }
    }

    /**
     * Confere o código. Devolve o usuário se hash, validade e tentativas
     * estiverem OK; caso contrário null, sem dizer qual das três falhou.
     */
    public function verificar(string $campo, string $destino, string $codigo): ?array
    {
        $pdo = Database::conexao();

        $busca = $pdo->prepare(
            "SELECT id, nome, papel FROM usuarios WHERE {$campo} = :destino AND papel = 'cliente' AND ativo"
        );
        $busca->execute(['destino' => $destino]);
        $usuario = $busca->fetch();
        if (!$usuario) {
            return null;
        }

        // Só o código mais recente vale: pedir um novo invalida os anteriores.
        // O id é UUID aleatório, então a ordem vem do created_at.
        $busca = $pdo->prepare(
            'SELECT id, codigo_hash
               FROM codigos_verificacao
              WHERE usuario_id = :id
              ORDER BY created_at DESC
              LIMIT 1'
        );
        $busca->execute(['id' => $usuario['id']]);
        $registro = $busca->fetch();
        if (!$registro) {
            return null;
        }

        // A tentativa é contada ANTES de comparar e num único UPDATE: várias
        // requisições simultâneas não conseguem passar do limite.
        $tentativa = $pdo->prepare(
            'UPDATE codigos_verificacao
                SET tentativas = tentativas + 1
              WHERE id = :id
                AND usado_em IS NULL
                AND expira_em > NOW()
                AND tentativas < :max'
        );
        $tentativa->execute(['id' => $registro['id'], 'max' => self::MAX_TENTATIVAS]);
        if ($tentativa->rowCount() === 0) {
            return null;
        }

        // hash_equals compara em tempo constante.
        if (!hash_equals($registro['codigo_hash'], self::hash($codigo))) {
            return null;
        }

        // Uso único: se duas requisições acertarem juntas, só uma marca como usado.
        $uso = $pdo->prepare(
            'UPDATE codigos_verificacao SET usado_em = NOW() WHERE id = :id AND usado_em IS NULL'
        );
        $uso->execute(['id' => $registro['id']]);
        if ($uso->rowCount() === 0) {
            return null;
        }

        $pdo->prepare(
            'UPDATE usuarios SET verificado_em = COALESCE(verificado_em, NOW()), updated_at = NOW() WHERE id = :id'
        )->execute(['id' => $usuario['id']]);

        return $usuario;
    }

    private function buscarOuCriarCliente(PDO $pdo, string $nome, string $campo, string $destino): ?array
    {
        // $campo nunca vem do usuário: é 'email' ou 'telefone', escolhido pelo controller.
        $busca = $pdo->prepare("SELECT id, papel, ativo, verificado_em FROM usuarios WHERE {$campo} = :destino");
        $busca->execute(['destino' => $destino]);
        $usuario = $busca->fetch();

        if (!$usuario) {
            try {
                $insercao = $pdo->prepare(
                    "INSERT INTO usuarios (nome, {$campo}, papel) VALUES (:nome, :destino, 'cliente') RETURNING id"
                );
                $insercao->execute(['nome' => $nome, 'destino' => $destino]);

                return ['id' => $insercao->fetchColumn()];
            } catch (PDOException $e) {
                // 23505 = violação de unicidade: outra requisição criou o mesmo contato agora.
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                $busca->execute(['destino' => $destino]);
                $usuario = $busca->fetch();
            }
        }

        if (!$usuario || $usuario['papel'] !== 'cliente' || !$usuario['ativo']) {
            return null;
        }

        // Enquanto ninguém provou ser dono do contato, o nome pode ser corrigido.
        // Depois de verificado, um pedido anônimo não altera mais o cadastro.
        if ($usuario['verificado_em'] === null) {
            $pdo->prepare('UPDATE usuarios SET nome = :nome, updated_at = NOW() WHERE id = :id')
                ->execute(['nome' => $nome, 'id' => $usuario['id']]);
        }

        return $usuario;
    }

    /**
     * Um código de 6 dígitos tem só 1 milhão de combinações: um hash comum
     * seria revertido em segundos por quem tivesse uma cópia do banco. Com
     * HMAC, sem a APP_KEY (que fica só no .env) o hash não serve para nada.
     */
    private static function hash(string $codigo): string
    {
        return hash_hmac('sha256', $codigo, Env::obrigatoria('APP_KEY'));
    }
}
