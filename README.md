# agendamentos-unhasporAdnaCosta-backend

# Agendamentos - Backend

Backend em PHP, rodando em containers Docker (Nginx + PHP-FPM) com banco de dados PostgreSQL.

## Pré-requisitos

- [Docker](https://www.docker.com/products/docker-desktop/) e Docker Compose (já vêm juntos no Docker Desktop)
- No Windows: recomenda-se usar o **WSL2** com a integração do Docker Desktop ativada, e manter o projeto dentro do sistema de arquivos do Linux (WSL) para melhor performance
- Um cliente de banco de dados (opcional, mas recomendado): [Beekeeper Studio](https://www.beekeeperstudio.io/), DBeaver ou pgAdmin

## Estrutura do projeto

```
.
├── docker/
│   ├── php/
│   │   └── Dockerfile          # Imagem do PHP-FPM com extensões do Postgres
│   └── nginx/
│       └── default.conf        # Configuração do servidor web
├── public/
│   └── index.php               # Ponto de entrada (única pasta servida pelo Nginx)
├── routes/                     # Um arquivo por área: publico, autenticado, cliente, supervisor, admin
├── src/
│   ├── Core/                   # Env, Database, Request, Response, Router
│   ├── Middleware/             # Auth (sessão), Papel (cliente/supervisor/admin), Cors
│   ├── Services/               # OtpService, SessionService, AuthService, Notificacao/
│   └── Controllers/
├── db/
│   ├── migrations/
│   └── seeds/                  # AdminSeeder: cria o primeiro admin
├── docker-compose.yml          # Orquestra os serviços (php, nginx, postgres, mailpit)
├── .env                        # Variáveis de ambiente (NÃO versionado)
└── .env.example                # Modelo das variáveis de ambiente
```

## Configuração inicial

1. Clone o repositório e entre na pasta do projeto.

2. Copie o arquivo de exemplo de variáveis de ambiente:

   ```bash
   cp .env.example .env
   ```

3. Abra o `.env` e preencha os valores. Cada variável está comentada no `.env.example`; as que não podem ficar vazias são as do banco (`DB_*`), `APP_KEY`, `ADMIN_EMAIL` e `ADMIN_SENHA`.

   > **Importante:** `DB_HOST` deve continuar como `postgres` (o nome do serviço no `docker-compose.yml`). É assim que o container do PHP encontra o do banco pela rede interna do Docker. Não troque para `localhost` aqui.

   > `APP_KEY` protege os códigos de verificação. Gere uma depois de subir os containers com `docker compose exec php php -r "echo bin2hex(random_bytes(32));"` e nunca a versione.

## Subindo a aplicação

Na raiz do projeto, rode:

```bash
docker compose up -d --build
```

Isso vai:
- Construir a imagem do PHP (com as extensões do Postgres já instaladas)
- Baixar as imagens do Nginx, do Postgres e do Mailpit
- Subir os quatro containers em segundo plano

Para conferir se está tudo de pé:

```bash
docker compose ps
```

Você deve ver os serviços `php`, `nginx`, `postgres` e `mailpit` com status `Up`, e o `postgres` como `(healthy)`.

Depois, instale as dependências, crie as tabelas e o primeiro admin:

```bash
docker compose exec php composer install
docker compose exec php vendor/bin/phinx migrate
docker compose exec php vendor/bin/phinx seed:run -s AdminSeeder
```

## Acessando a aplicação

A API responde em `http://localhost:8080`. Para um teste rápido, abra `http://localhost:8080/api/servicos`: deve vir um JSON com a lista de serviços.

Os e-mails enviados em desenvolvimento (incluindo os códigos de acesso) aparecem em `http://localhost:8025` (Mailpit).

## Autenticação e áreas

Há três papéis: `cliente`, `supervisor` e `admin`. Todos terminam com uma sessão guardada em cookie `HttpOnly`; o que muda é como cada um entra.

| Quem | Como entra |
|---|---|
| Cliente | Sem senha. Informa nome + e-mail ou telefone, recebe um código de 6 dígitos (vale 10 minutos, 5 tentativas) e o digita. A conta é criada no primeiro pedido de código. |
| Supervisor | E-mail + senha. É criado por um admin (`POST /api/admin/usuarios` com `{"nome", "email", "senha"}`); a senha definida pelo admin é temporária e vai para o e-mail do supervisor, junto com o login. No primeiro acesso ele precisa trocá-la: até lá, as rotas de `/api/supervisor` respondem `403`. |
| Admin | E-mail + senha. O primeiro é criado pelo `AdminSeeder`. |

Cada área é um arquivo em `routes/`, com a proteção aplicada ao grupo inteiro:

| Arquivo | Prefixo | Quem acessa |
|---|---|---|
| `publico.php` | `/api/servicos`, `/api/auth/...` | Qualquer visitante, sem login |
| `autenticado.php` | `/api/auth/me`, `/api/auth/logout`, `/api/auth/senha` | Qualquer usuário logado |
| `cliente.php` | `/api/cliente/...` | Só `cliente` |
| `supervisor.php` | `/api/supervisor/...` | `supervisor` e `admin` |
| `admin.php` | `/api/admin/...` | Só `admin` |

Sem sessão válida a resposta é `401`; com sessão mas papel errado, `403`.

Rotas de autenticação (corpo sempre em JSON):

| Rota | Corpo |
|---|---|
| `POST /api/auth/cliente/codigo` | `{"nome", "email" ou "telefone", "canal"}` — `canal` é `email`, `sms` ou `whatsapp` (opcional) |
| `POST /api/auth/cliente/verificar` | `{"email" ou "telefone", "codigo"}` |
| `POST /api/auth/staff/login` | `{"email", "senha"}` |
| `GET /api/auth/me` | — |
| `POST /api/auth/logout` | — |
| `POST /api/auth/senha` | `{"senha_atual", "nova_senha"}` — supervisor e admin; derruba as outras sessões |

O front-end precisa enviar as requisições com `credentials: 'include'` para o cookie ir junto, e o endereço dele tem que estar em `FRONTEND_URL`.

SMS e WhatsApp usam a Twilio (`TWILIO_*` no `.env`). Sem essas credenciais, com `APP_ENV=local`, o código é só gravado no log: `docker compose logs -f php`.

## Agendamentos

Fluxo: o visitante vê os horários livres de um serviço sem login → para pedir, precisa estar logado como cliente → o pedido nasce `pendente` → um supervisor (ou admin) confirma ou recusa.

```
pendente ──confirmar──▶ confirmado ──cancelar (supervisor)──▶ cancelado
   │                        │
   ├──recusar──▶ recusado   └──cancelar (cliente, com antecedência)──▶ cancelado
   └──cancelar (cliente)──▶ cancelado
```

Como os horários livres são calculados:

- **Expediente** (`PUT /api/supervisor/expediente`): faixas por dia da semana, ex.: terça 09:00–12:00 e 13:00–18:00. Sem expediente cadastrado, nenhum horário aparece.
- **Duração do serviço** (`duracao_minutos`, padrão 60): o atendimento tem que caber inteiro dentro de uma faixa.
- **Bloqueios** (`POST /api/supervisor/bloqueios`): períodos sem atendimento, com motivo (ex.: 20/10 das 14:00 às 15:00, "imprevisto"). Se já houver cliente marcado no período, a API recusa (409) e lista os agendamentos, para que sejam recusados/cancelados antes — assim o cliente sempre é avisado.
- **Agendamentos pendentes e confirmados** ocupam o horário; recusados e cancelados o liberam.
- Regras do `.env`: `AGENDA_INTERVALO_MINUTOS` (grade de inícios), `AGENDA_ANTECEDENCIA_MINUTOS`, `AGENDA_JANELA_DIAS`, `AGENDA_MAX_PENDENTES` e `AGENDA_CANCELAMENTO_HORAS`.

A mesma regra (`src/Services/AgendaService.php`) monta a lista de horários e valida o pedido. Contra dois pedidos simultâneos no mesmo horário há duas camadas: uma trava (`pg_advisory_xact_lock`) durante a criação, e uma constraint `EXCLUDE` no banco que impede dois agendamentos ativos sobrepostos.

| Rota | Quem | Corpo |
|---|---|---|
| `GET /api/servicos/{id}/horarios?data=AAAA-MM-DD` | Público | — |
| `POST /api/cliente/agendamentos` | Cliente | `{"servico_id", "data_hora": "2026-10-20T14:00", "observacoes"?}` |
| `PATCH /api/cliente/agendamentos/{id}/cancelar` | Cliente | — |
| `GET /api/supervisor/agendamentos?status=&de=&ate=` | Supervisor | — |
| `PATCH /api/supervisor/agendamentos/{id}/confirmar` | Supervisor | — |
| `PATCH /api/supervisor/agendamentos/{id}/recusar` | Supervisor | `{"motivo"}` |
| `PATCH /api/supervisor/agendamentos/{id}/cancelar` | Supervisor | `{"motivo"}` (só confirmados) |
| `GET` / `PUT /api/supervisor/expediente` | Supervisor | `{"faixas": [{"dia_semana": 0-6, "inicio": "09:00", "fim": "12:00"}]}` |
| `GET` / `POST /api/supervisor/bloqueios`, `DELETE /api/supervisor/bloqueios/{id}` | Supervisor | `{"inicio", "fim", "motivo"}` |

Avisos: a cada pedido novo (e a cada cancelamento feito pelo cliente), supervisores e admins recebem e-mail. Ao confirmar, recusar ou cancelar, o cliente recebe e-mail — ou SMS, se só tiver telefone. Falha no envio não desfaz a ação; o erro vai para o log.

Os horários (`data_hora`, expediente, bloqueios) estão no fuso do salão (`APP_TIMEZONE`, padrão `America/Sao_Paulo`).

## Conectando ao banco por um cliente externo (Beekeeper, DBeaver, etc.)

Como o `docker-compose.yml` expõe a porta do Postgres para a sua máquina, use estes dados na configuração da conexão:

| Campo    | Valor                          |
|----------|--------------------------------|
| Host     | `localhost`                    |
| Porta    | `5432`                         |
| Usuário  | valor de `DB_USERNAME` no `.env` |
| Senha    | valor de `DB_PASSWORD` no `.env` |
| Banco    | valor de `DB_DATABASE` no `.env` |

> Use `localhost` (não `postgres`) aqui — `postgres` só é resolvido *dentro* da rede do Docker, entre os containers. Um programa rodando fora do Docker (como o Beekeeper) precisa de `localhost`.

## Comandos úteis

| Comando | O que faz |
|---|---|
| `docker compose up -d --build` | Sobe (ou reconstrói) todos os containers em segundo plano |
| `docker compose ps` | Lista os containers e o status de cada um |
| `docker compose logs -f` | Acompanha os logs de todos os serviços em tempo real |
| `docker compose logs -f php` | Acompanha só os logs do PHP (troque `php` por `nginx` ou `postgres`) |
| `docker compose down` | Para e remove os containers (mantém os dados do banco) |
| `docker compose down -v` | Para os containers e **apaga também os dados do banco** (reseta tudo do zero) |
| `docker compose exec php bash` | Abre um terminal dentro do container do PHP |
| `docker compose exec postgres psql -U <usuário> -d <banco>` | Abre o `psql` dentro do container do banco |

## Problemas comuns

- **502 Bad Gateway**: o Nginx não conseguiu falar com o PHP-FPM. Confira se o container `php` está de pé (`docker compose ps`) e os logs (`docker compose logs php`).
- **`could not find driver` no PHP**: a extensão `pdo_pgsql` não foi instalada corretamente. Rode `docker compose up -d --build` para reconstruir a imagem do PHP.
- **`password authentication failed`**: as credenciais do `.env` foram alteradas *depois* que o volume do banco já tinha sido criado. As variáveis `POSTGRES_*` só valem na primeira inicialização do volume. Para resetar: `docker compose down -v` e depois `docker compose up -d --build`.
- **Cliente externo (Beekeeper etc.) não conecta / porta não mapeada**: confira no `docker compose ps` se a porta do `postgres` aparece como `127.0.0.1:5432->5432/tcp`. Se aparecer só `5432/tcp`, falta o bloco `ports` no serviço `postgres` do `docker-compose.yml`.
- **`Erro interno.` (500) em qualquer rota**: veja o motivo real em `docker compose logs php`. Os mais comuns: `vendor/` não instalado (rode o `composer install`), tabelas não criadas (rode o `phinx migrate`) ou `APP_KEY` vazia no `.env`.
- **`port is already allocated` ao subir**: outro programa já usa a porta 8080 ou 5432 (por exemplo, um Postgres instalado direto no Windows). Pare esse programa ou troque a porta da esquerda no `docker-compose.yml` (ex.: `"8081:80"`).
- **`getaddrinfo ENOTFOUND postgres`** ao conectar por um cliente externo: use `localhost` como host, não `postgres` — esse nome só existe dentro da rede do Docker.
