<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * GET /api/saude — diz, em uma requisição, se a aplicação alcança o banco.
 *
 * Existe para o deploy. Quando toda rota que toca o banco responde 500, o
 * erro de verdade fica só no log da plataforma, e sem ele não há como saber
 * se faltou variável de ambiente, se a credencial está errada ou se as
 * migrações nunca rodaram. Esta rota responde isso sem expor segredo: o host,
 * o usuário e a senha ficam de fora — só sai o nome da variável que falta ou
 * o SQLSTATE, que é um código de cinco caracteres.
 */
final class SaudeController
{
    /**
     * Variáveis sem as quais alguma funcionalidade cai, e o que cai com cada
     * uma. Só o NOME sai na resposta, nunca o valor: a lista existe para o
     * deploy saber o que falta cadastrar, não para vazar segredo.
     */
    private const VARIAVEIS = [
        'APP_KEY' => 'código de 6 dígitos do login do cliente',
        'FRONTEND_URL' => 'origens liberadas no CORS',
        'MAIL_HOST' => 'envio de e-mail',
        'MAIL_FROM' => 'remetente do e-mail',
        'MAIL_USERNAME' => 'autenticação no SMTP',
        'MAIL_PASSWORD' => 'autenticação no SMTP',
    ];

    /** As tabelas que provam que as migrações rodaram. */
    private const TABELAS_ESPERADAS = ['phinx_migration_log', 'usuarios', 'servicos', 'agendamentos'];

    private const DICAS = [
        '08006' => 'Não abriu a conexão: confira host, porta e credenciais (no Render, use o Internal Database URL).',
        '08001' => 'Não abriu a conexão: confira host, porta e credenciais.',
        '28P01' => 'Usuário ou senha do banco recusados.',
        '28000' => 'Usuário do banco recusado.',
        '3D000' => 'O banco informado não existe nesse servidor.',
        '42P01' => 'O banco existe mas está sem as tabelas: rode as migrações (RUN_MIGRATIONS=1).',
    ];

    public function verificar(Request $request): Response
    {
        try {
            $conexao = Database::conexao();
        } catch (Throwable $e) {
            return self::falha('sem conexão', $e);
        }

        try {
            $tabelas = $conexao
                ->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return self::falha('conectado, consulta falhou', $e);
        }

        $faltando = array_values(array_diff(self::TABELAS_ESPERADAS, $tabelas));

        if ($faltando !== []) {
            return Response::json([
                'api' => 'ok',
                'banco' => 'conectado',
                'migracoes' => 'faltando',
                'tabelas_faltando' => $faltando,
                'dica' => 'Suba o serviço uma vez com RUN_MIGRATIONS=1 para criar as tabelas.',
            ], 503);
        }

        return Response::json([
            'api' => 'ok',
            'banco' => 'conectado',
            'migracoes' => 'aplicadas',
            'tabelas' => count($tabelas),
            'configuracao' => self::configuracao(),
        ]);
    }

    /**
     * O que mais costuma faltar depois de um deploy. Nenhum valor secreto sai
     * daqui: das variáveis sensíveis sai só o nome, e as três que aparecem
     * com valor (APP_ENV, COOKIE_SAMESITE, NOTIFICADOR) são modos de operação,
     * não credenciais.
     */
    private static function configuracao(): array
    {
        $faltando = [];
        foreach (self::VARIAVEIS as $nome => $paraQue) {
            if (Env::get($nome) === null) {
                $faltando[$nome] = $paraQue;
            }
        }

        return [
            'faltando' => $faltando === [] ? 'nada' : $faltando,
            'app_env' => Env::get('APP_ENV', 'production'),
            'cookie_samesite' => Env::get('COOKIE_SAMESITE', 'Lax'),
            'notificador' => Env::get('NOTIFICADOR', 'real'),
        ];
    }

    private static function falha(string $banco, Throwable $e): Response
    {
        // O detalhe completo (com host e usuário) vai só para o log.
        error_log((string) $e);

        $corpo = [
            'api' => 'ok',
            'banco' => $banco,
            'detalhe' => self::detalhe($e),
            'log' => 'a mensagem completa está no log do serviço',
        ];

        $dica = self::DICAS[self::sqlstate($e) ?? ''] ?? null;
        if ($dica !== null) {
            $corpo['dica'] = $dica;
        }

        return Response::json($corpo, 503);
    }

    private static function detalhe(Throwable $e): string
    {
        // Primeiro o PDO, e dele nunca a mensagem: ela traz host, usuário e
        // às vezes o nome do banco. Só o SQLSTATE sai daqui.
        if ($e instanceof PDOException) {
            $sqlstate = self::sqlstate($e);

            return $sqlstate === null ? 'falha de conexão sem SQLSTATE' : 'SQLSTATE ' . $sqlstate;
        }

        // RuntimeException do Env/Database: a mensagem nomeia a variável que
        // falta ou o formato errado da URL, e é exatamente o que falta saber.
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }

        return 'erro inesperado ao abrir a conexão';
    }

    /**
     * O SQLSTATE, cinco caracteres. Em erro de conexão o PDO às vezes põe o
     * código no getCode() (string) e às vezes um errno numérico, deixando o
     * SQLSTATE só no texto — por isso o segundo caminho, com regex.
     */
    private static function sqlstate(Throwable $e): ?string
    {
        if (!$e instanceof PDOException) {
            return null;
        }

        $codigo = (string) $e->getCode();
        if (preg_match('/^[0-9A-Z]{5}$/', $codigo) === 1) {
            return $codigo;
        }

        return preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $e->getMessage(), $achado) === 1
            ? $achado[1]
            : null;
    }
}
