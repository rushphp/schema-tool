[English](README.md) | [Português](README.pt.md)

![PHP](https://img.shields.io/badge/PHP-%20^8.3-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![License](https://img.shields.io/badge/License-MIT-777)

# RUSH Schema Tool

Versionamento da estrutura do banco com o [RUSH](https://github.com/rushphp/rush).

O schema tool é **state-based**: ele inspeciona o banco local, descobre o que
mudou e registra a alteração como um save sempre para frente. Você não escreve
classes de migration nem métodos `up`/`down`.

> **Pré-1.0:** releases minor podem incluir mudanças incompatíveis.

## 📦 Instalação

```bash
composer require --dev rushphp/schema-tool
```

Habilite no `rush.json`:

```json
{
    "tools": {
        "schema": true
    }
}
```

## 🚀 Fluxo

```bash
rush schema init -d app          # captura GENESIS + checkpoint
# altere o banco local
rush schema status -d app        # mostra dirty/clean e o resumo do diff
rush schema save -d app -l "add posts"
rush schema checkpoint -d app -l "v2"
rush schema push -T production -d app
```

- `save` registra o delta do HEAD até o banco local.
- `checkpoint` reaplica os saves pendentes (up e down) num banco scratch,
  valida os dois hashes e tagueia o HEAD.
- `push` reconcilia o alvo para frente até um checkpoint, com um único delta
  direto; não reaplica o histórico inteiro.
- `regress` cria um **novo** save que leva o banco local de volta a um save ou
  checkpoint anterior. O histórico é sempre forward-only.

## 🧾 Comandos

| Comando | Shorts | Papel |
|---|---|---|
| `schema init` | `-d` | Cria save GENESIS + checkpoint |
| `schema save` | `-d -l -c` | Salva o delta atual; `-c` também checkpointa |
| `schema checkpoint` | `-d -l` | Valida e tagueia o HEAD |
| `schema regress` | `-d -t -c` | Cria save de regressão e move o banco local |
| `schema status` | `-d -T -j` | Estado local ou alvo (`-j` JSON) |
| `schema diff` | `-d -f -t -T -m` | Diff entre saves/checkpoints/local/alvo |
| `schema checkpoints` | `-d -s` | Lista ou detalha checkpoints |
| `schema verify` | `-d -c` | Valida a árvore ou um checkpoint |
| `schema targets` | `-d` | Lista alvos e última verificação |
| `schema push` | `-d -T -c` | Reconcilia um alvo para frente |
| `schema agent:export` | `-p` | Gera o agente HTTP standalone |

`-t` sempre significa `--to`; `-T` sempre significa `--target`.

## ⚙️ Configuração

```text
.rush/schema/config.json      # local, não versionado
schema/<database>/            # checkpoints e saves versionados
.rush/schema/local/           # backups, target records, temporários
```

Veja [`schema.example.json`](schema.example.json). Múltiplos bancos são
suportados. Com um banco, `--database` é opcional; com mais de um, é
obrigatório.

Drivers: `sqlite`, `mysql`/`mariadb`.
Transportes:

- `local`: PDO direto.
- `ssh`: executa um agente PHP standalone via SSH; cobre SQLite remoto e MySQL
  que só escuta em localhost.
- `http`: envia para um agente exportado. HTTPS é exigido por padrão;
  `"secure": false` libera HTTP/self-signed com warning.

### Agente HTTP

```bash
rush schema agent:export -p public/rush-schema-agent.php
```

Crie `schema.remote.json` fora do webroot (veja
[`resources/remote/schema.remote.example.json`](resources/remote/schema.remote.example.json)) e aponte
`RUSH_SCHEMA_AGENT_CONFIG` para o caminho absoluto:

```env
RUSH_SCHEMA_AGENT_CONFIG=/srv/app/schema.remote.json
```

```json
{
    "format": 1,
    "token": "change-me",
    "databases": {
        "app": {
            "driver": "mysql",
            "host": "127.0.0.1",
            "database": "app",
            "user": "schema",
            "password": "secret"
        }
    }
}
```

- Permissão `0600`.
- O token do JSON precisa ser igual ao `.rush/schema/tokens/<target>`.
- Credenciais do banco nunca saem do servidor.
- `database` no target HTTP local é a chave no `schema.remote.json`.

## 🧬 Suporte de bancos

Baselines LTS suportados:

- MySQL 8.4 LTS
- MariaDB 10.11 LTS e 11.8 LTS

Estrutura suportada:

- tabelas, colunas, PK, índices (incluindo prefixo e DESC)
- unique constraints, foreign keys e views simples
- `CHECK` constraints (apenas enforçados, MySQL/MariaDB)
- defaults com `ON UPDATE`
- JSON same-flavor: `json` nativo no MySQL, `LONGTEXT` + check no MariaDB

Push cross-flavor é bloqueado. Comentários não são gerenciados.

Ainda não suportado (recusado com erro claro): CHECK constraints no SQLite,
colunas geradas, partições,
sequences, system versioning, índices funcionais/expression, colunas/índices
invisíveis, fulltext/spatial e `CHECK ... NOT ENFORCED`.

## 🔐 Segurança

- Backup obrigatório antes de todo `regress` e `push`.
- Diff destrutivo exige `--allow-destructive`; interativo pede confirmação e
  não-interativo exige também `--yes`.
- `down` restaura estrutura, não dados. O backup é a rede de segurança.
- Renames hoje são tratados como drop + add.
- Colunas geradas, partições, sequences, system versioning, índices de expressão/fulltext/spatial, colunas/índices invisíveis e CHECK `NOT ENFORCED` do MySQL são reportados como não suportados.

## 🧪 Testes

Dentro do devcontainer, o MariaDB roda localmente em `127.0.0.1` como banco efêmero:

```bash
composer test
composer test:cover
```

`test:cover` executa `pest --coverage --min=80`. O devcontainer exporta as
variáveis `SCHEMA_MYSQL_*` usadas pelo teste de integração, então o
`MysqlDriver` entra no relatório de cobertura. Fora do devcontainer, suba o
MariaDB manualmente e exporte essas variáveis, ou aceite a cobertura sem MySQL
(o driver aparecerá com 0%).

## 🤝 Contribuições

Contribuições são bem-vindas. Abra uma issue ou envie um pull request.

## 📄 Licença

MIT. Consulte [LICENSE](LICENSE).
