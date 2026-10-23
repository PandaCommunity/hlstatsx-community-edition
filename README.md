## HLstatsX Community Edition

### 📌 Requirements

- **PHP:** version 8.5 or higher required.
- **MySQL / MariaDB:** version 5.7 or higher required (recommended 8.0).
- The web database account needs **CREATE TEMPORARY TABLES** on the HLstatsX database for clan and country rankings. These request-local summaries are dropped after use; no persistent summary tables are created.
---
HLstatsX Community Edition is an open-source project licensed
under GNU General Public License v2 and is a real-time stats
and ranking for Source engine based games. HLstatsX Community
Edition uses a Perl daemon to parse the log streamed from the
game server. The data is stored in a MySQL Database and has
a PHP frontend.

### Maintenance and contributions

This is a **maintenance-only fork** of a very old codebase, focused on bug fixes and adapting it to newer PHP versions. The current target is **PHP 8.5+ only**; compatibility with older PHP versions is not maintained.

Legacy integrations included in the repository are not automatically maintenance targets. When maintenance work touches or requires changes to an old integration, such as AMX Mod X (AMXX) or Beetle's Mod (BEETLE), pause that portion of the work and ask the maintainer whether the integration is still relevant and whether to proceed. Explain why it is involved rather than assuming every inherited integration must be fixed, modernized, or kept supported. Removal also requires maintainer approval.

Prefer focused fixes and case-by-case manual verification of the affected behavior. Describe what you checked when submitting a change. Do not add permanent tests or expand `tests/` unless explicitly requested; temporary verification scripts are fine, but should be removed afterward.

See [AGENTS.md](AGENTS.md) for concise development guidance and Amp orb startup instructions.
