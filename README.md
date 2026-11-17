[English](README.md) | [Português](README.pt.md)

![PHP](https://img.shields.io/badge/PHP-%20^8.3-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![License](https://img.shields.io/badge/License-MIT-777)

# RUSH Schema Tool

Structural database schema versioning for [RUSH](https://github.com/rushphp/rush).

> **Pre-1.0:** minor releases may include breaking changes.

## 📦 Installation

```bash
composer require --dev rushphp/schema-tool
```

Enable it in `rush.json`:

```json
{
    "tools": {
        "schema": true
    }
}
```

## 🚀 Commands

```bash
rush schema init
rush schema status
rush schema status --target=local
```

`init` captures the local database structure as the Genesis state. `status`
shows the local state or compares a target database.

## ⚙️ Configuration

```text
.rush/schema/config.json
```

Supports SQLite, MySQL/MariaDB and PostgreSQL. The SQLite driver is available
out of the box; MySQL and PostgreSQL need their PDO extensions.

```text
.rush/schema/
├── config.json
├── ssh/
└── local/{indexes,targets,tmp}
```

The whole `.rush/` directory is local state and must not be committed.

## 🤝 Contributions

Contributions are welcome. Open an issue or submit a pull request.

## 📄 License

MIT. See [LICENSE](LICENSE).
