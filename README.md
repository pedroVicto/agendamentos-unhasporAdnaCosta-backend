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
├── src/
│   ├── controllers/
│   └── core/
├── docker-compose.yml          # Orquestra os serviços (php, nginx, postgres)
├── .env                        # Variáveis de ambiente (NÃO versionado)
├── .env.example                # Modelo das variáveis de ambiente
└── index.php                   # Ponto de entrada da aplicação
```

## Configuração inicial

1. Clone o repositório e entre na pasta do projeto.

2. Copie o arquivo de exemplo de variáveis de ambiente:

   ```bash
   cp .env.example .env
   ```

3. Abra o `.env` e ajuste os valores, se quiser (usuário, senha e nome do banco):

   ```env
   APP_ENV=local
   APP_PORT=8080

   DB_HOST=
   DB_PORT=
   DB_DATABASE=
   DB_USERNAME=
   DB_PASSWORD=
   ```

   > **Importante:** `DB_HOST` deve continuar como `postgres` (o nome do serviço no `docker-compose.yml`). É assim que o container do PHP encontra o do banco pela rede interna do Docker. Não troque para `localhost` aqui.

## Subindo a aplicação

Na raiz do projeto, rode:

```bash
docker compose up -d --build
```

Isso vai:
- Construir a imagem do PHP (com as extensões do Postgres já instaladas)
- Baixar as imagens do Nginx e do Postgres
- Subir os três containers em segundo plano

Para conferir se está tudo de pé:

```bash
docker compose ps
```

Você deve ver os três serviços (`php`, `nginx`, `postgres`) com status `Up`, e o `postgres` como `(healthy)`.

## Acessando a aplicação

Abra no navegador:

```
http://localhost:8080
```

Se tudo estiver certo, a página vai confirmar que o PHP está rodando, que a extensão `pdo_pgsql` foi carregada e que a conexão com o banco funcionou.

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
- **`getaddrinfo ENOTFOUND postgres`** ao conectar por um cliente externo: use `localhost` como host, não `postgres` — esse nome só existe dentro da rede do Docker.
