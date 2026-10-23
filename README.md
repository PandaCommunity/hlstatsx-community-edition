## HLstatsX Community Edition

### 📌 Requirements

- **PHP:** version 8.5 or higher required.
- **MySQL / MariaDB:** version 5.7 or higher required (recommended 8.0).
---
HLstatsX Community Edition is an open-source project licensed
under GNU General Public License v2 and is a real-time stats
and ranking for Source engine based games. HLstatsX Community
Edition uses a Perl daemon to parse the log streamed from the
game server. The data is stored in a MySQL Database and has
a PHP frontend.

### Maintenance and contributions

Work on this codebase primarily focuses on bug fixes and adapting it to newer PHP versions. The current target is **PHP 8.5+ only**; compatibility with older PHP versions is not maintained.

Prefer focused fixes and case-by-case manual verification of the affected behavior. Describe what you checked when submitting a change. Do not add permanent tests or expand `tests/` unless explicitly requested; temporary verification scripts are fine, but should be removed afterward.

See [AGENTS.md](AGENTS.md) for concise development guidance and Amp orb startup instructions.
