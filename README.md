[English](README.md) | [Português](README.pt.md)

![PHP](https://img.shields.io/badge/PHP-%20^8.3-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![License](https://img.shields.io/badge/License-MIT-777)

# RUSH Schema Tool

Structural database schema versioning for [RUSH](https://github.com/rushphp/rush).

The schema tool is **state-based**: it inspects the local database, discovers what
changed, and records the change as a forward-only save. You do not write migration
classes or `up`/`down` methods.

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

## 🚀 Workflow

```bash
rush schema init -d app          # capture GENESIS + checkpoint
# edit the local database
rush schema status -d app        # shows dirty/clean and the diff summary
rush schema save -d app -l "add posts"
rush schema checkpoint -d app -l "v2"
rush schema push -T production -d app
```

- `save` records the delta from HEAD to the local database.
- `checkpoint` replays every pending save up and down in a scratch database,
  verifies both hashes, and tags HEAD.
- `push` reconciles a target forward to a checkpoint. It computes one direct
  delta; it does not replay the whole history.
- `regress` creates a **new** save that moves the local database back to an
  earlier save or checkpoint. History is always forward-only.

## 🧾 Commands

| Command | Short flags | Purpose |
|---|---|---|
| `schema init` | `-d` | Create GENESIS save + checkpoint |
| `schema save` | `-d -l -c` | Save the current delta; `-c` also checkpoints |
| `schema checkpoint` | `-d -l` | Verify and tag HEAD |
| `schema regress` | `-d -t -c` | Create a forward save that regresses the local DB |
| `schema status` | `-d -T -j` | Local state or target comparison (`-j` JSON) |
| `schema diff` | `-d -f -t -T -m` | Diff saves/checkpoints/local/target |
| `schema checkpoints` | `-d -s` | List or show checkpoints |
| `schema verify` | `-d -c` | Verify the trace or a checkpoint |
| `schema targets` | `-d` | List targets and last verification |
| `schema push` | `-d -T -c` | Reconcile a target forward |
| `schema agent:export` | `-p` | Write the standalone HTTP agent |

`-t` always means `--to`; `-T` always means `--target`.

## ⚙️ Configuration

```text
.rush/schema/config.json      # local, not versioned
schema/<database>/            # versioned checkpoints and saves
.rush/schema/local/           # backups, target records, temp
```

See [`schema.example.json`](schema.example.json). Multiple databases are
supported. With one database `--database` is optional; with more than one it is
required.

Drivers: `sqlite`, `mysql`/`mariadb`.
Transports:

- `local`: PDO directly.
- `ssh`: executes a standalone PHP agent over SSH; works for remote SQLite and
  MySQL that only listens on localhost.
- `http`: posts to an exported agent. HTTPS is required by default;
  `"secure": false` allows HTTP/self-signed certificates with a warning.

### HTTP agent

```bash
rush schema agent:export -p public/rush-schema-agent.php
```

Create `agent.config.json` next to the agent (or point
`RUSH_SCHEMA_AGENT_CONFIG` to it):

```json
{
    "token": "change-me"
}
```

Use the same token in `.rush/schema/tokens/<target>` and reference it with
`token_file`.

## 🔐 Safety

- Backups are mandatory before every `regress` and `push`.
- Destructive diffs require `--allow-destructive`; interactive runs ask for
  confirmation, non-interactive runs also require `--yes`.
- `down` restores structure, not data. Backups are the data safety net.
- Renames are currently treated as drop + add.
- MySQL CHECK constraints, generated columns, expression/fulltext indexes,
  triggers, routines and events are reported as unsupported.

## 🤝 Contributions

Contributions are welcome. Open an issue or submit a pull request.

## 📄 License

MIT. See [LICENSE](LICENSE).
