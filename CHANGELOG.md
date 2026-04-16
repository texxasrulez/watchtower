# Changelog

All notable changes to `watchtower` should be documented in this file.

## [Unreleased]

- Ongoing development builds use `watchtower::PLUGIN_VERSION` with a `+dev` suffix until the next release is cut.

## [1.0.0] - 2026-04-11

- Formalized the plugin's self-metadata through `watchtower::PLUGIN_VERSION` and `watchtower::info()`.
- Aligned self-versioning with a cleaner release workflow while keeping existing plugin behavior intact.
