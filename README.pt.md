[English](README.md) | [Português](README.pt.md)

![PHP](https://img.shields.io/badge/PHP-%20^8.3-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![License](https://img.shields.io/badge/License-MIT-777)

# RUSH Schema Tool

Versionamento da estrutura do banco de dados com o
[RUSH](https://github.com/rushphp/rush).

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

## 🚀 Comandos

```bash
rush schema init
rush schema status
rush schema status --target=local
```

`init` captura a estrutura local do banco como estado Genesis. `status` mostra o
estado local ou compara um banco de um target.

## ⚙️ Configuração

```text
.rush/schema/config.json
```

Suporta SQLite, MySQL/MariaDB e PostgreSQL. O driver SQLite está disponível
imediatamente; MySQL e PostgreSQL precisam das extensões PDO correspondentes.

```text
.rush/schema/
├── config.json
├── ssh/
└── local/{indexes,targets,tmp}
```

Todo o diretório `.rush/` é estado local e não deve ser versionado.

## 🤝 Contribuições

Contribuições são bem-vindas. Abra uma issue ou envie um pull request.

## 📄 Licença

MIT. Consulte [LICENSE](LICENSE).
