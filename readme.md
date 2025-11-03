# DB Peek

**DB Peek** is a single-file, lightweight PHP database viewer for MySQL — designed as a safe, self-hosted alternative to bulky admin tools.
It’s fast to deploy, easy to audit, and intentionally minimal: no frameworks, no dependencies, no JavaScript build steps.

## Features

* **MySQL-only (via PDO)** — simple and reliable
* **Single PHP file** — drop in and go
* **Login-protected** with session auth
* **Browse tables** with paging and sorting
* **View schema** (columns, types, keys, defaults)
* **Run SQL queries** (read-only by default)
* **Export to CSV**
* **Config via environment variables**
* Optional: IP allowlist, access token, read/write mode toggle

## Security First

DB Peek is meant for **private use only**.

* Read-only mode by default (`ALLOW_WRITE=0`)
* Supports IP and token restrictions
* No external libraries or obfuscation (transparent, auditable code)
* Ideal for dev/staging or emergency access inside secured environments

## Quick Start

```bash
# Set environment variables (example)
export MYSQL_HOST=127.0.0.1
export MYSQL_DB=test
export MYSQL_USER=root
export MYSQL_PASS=secret
export APP_USER=admin
export APP_PASS=strongpassword
php -S localhost:8080 db_peek.php
```

Then open [http://localhost:8080](http://localhost:8080).

---

**License:** MIT
**Version:** 0.3 — MySQL-only edition
