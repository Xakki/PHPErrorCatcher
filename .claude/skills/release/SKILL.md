---
name: release
description: Cut a release of the xakki/phperrorcatcher library — bump the version in composer.json AND package.json (they drift apart), run the full QA suite, commit, and tag.
when_to_use: User asks to release, cut a version, bump the version, or tag a new release.
argument-hint: "[version e.g. 0.8.2]"
disable-model-invocation: true
user-invocable: true
allowed-tools: Bash(make *) Bash(git *) Read Edit
---

# Release checklist

Target version comes from the argument (e.g. `/release 0.8.2`); if absent, ask.

> ⚠️ Two version strings exist and have drifted apart (`composer.json` and `package.json`). Both MUST be set to the SAME target version every release.

## Steps
1. **Clean tree** — `git status` must be clean on `master`. If not, stop and report.
2. **QA gate** — `make check` (cs-check + phpstan + test). Must be fully green; if anything fails, stop and report. Never release red.
3. **Bump versions** — set the `"version"` field to the target in BOTH `composer.json` and `package.json`.
4. **Commit** — `git commit -am "v<version> release"` (matches existing history style, e.g. "v 0.8.1 release").
5. **Tag** — `git tag <version>` with **no `v` prefix** (recent tags are `0.8.0` / `0.5.0` and composer's version field is unprefixed; older `vX.Y.Z` tags are legacy — do not follow them).
6. **Confirm before pushing** — show the user the new commit and tag, then on approval: `git push && git push origin <version>`.

## Notes
- CI (`.github/workflows/ci.yml`) runs PHPUnit (8.2/8.3) + phpcs + phpstan on push — the tag push triggers it.
- Never bump only one of the two version files.
