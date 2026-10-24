# Changelog

This changelog documents only the two most recent published GitHub releases. Each entry is based on the changes between its release tag and the immediately preceding published release. Changes made later on `master` are excluded.

## 1.0.4 - 2026-10-19

### Changed

- Added the plugin icon image at `pix/icon.png` and reorganized the SVG logo asset as `pix/monologo.svg`.
- Added concurrency configuration to the existing GitHub Actions release workflow, grouping release runs by Git reference without cancelling in-progress runs.
- Updated the plugin release identifier from `1.0.3` to `1.0.4` and its build number from `2026101104` to `2026101900`.

No functional changes to the AJAX Chat activity were identified between the published `1.0.3` and `1.0.4` tags.

[Compare 1.0.3...1.0.4](https://github.com/EduardoKrausME/moodle-mod_ajaxchat/compare/1.0.3...1.0.4)

## 1.0.3 - 2026-10-15 (UTC)

### Changed

- Updated the GitHub Actions CI database services from PostgreSQL 16 to PostgreSQL 17 and from MariaDB 10 to MariaDB 11.4, including the MariaDB initialization and health-check settings.
- Updated the PHP 8.3 CI matrix entry from `MOODLE_502_STABLE` (Moodle 5.2) to `MOODLE_503_STABLE` (Moodle 5.3), while retaining the Moodle 4.5 CI entry.
- Removed `.github/workflows/build-amd.yml`, which had automatically built AMD JavaScript using Moodle's Grunt tooling and committed the generated files to `master`.
- Updated the plugin release identifier from `1.0.2` to `1.0.3`; the plugin build number remained `2026101104`.

No functional changes to the AJAX Chat activity were identified between the published `1.0.2` and `1.0.3` tags.

[Compare 1.0.2...1.0.3](https://github.com/EduardoKrausME/moodle-mod_ajaxchat/compare/1.0.2...1.0.3)
