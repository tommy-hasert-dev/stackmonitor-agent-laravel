#!/usr/bin/env bash
# Publishes agents/laravel to the public split repository that Packagist reads
# (stackmonitor/agent-laravel). Run on the maintainer's machine, from main, after
# the release commit is merged:
#   agents/laravel/bin/release.sh 1.1.0
# The version must already be in ReportBuilder::AGENT_VERSION and have its section
# in CHANGELOG.md. The script pushes `main` and the tag `v<version>` of the split
# repo and creates a GitHub release there with that section as notes (needs `gh`);
# it asks before pushing.
set -euo pipefail

VERSION="${1:-}"
SPLIT_REMOTE="${SPLIT_REMOTE:-git@github.com:tommy-hasert-dev/stackmonitor-agent-laravel.git}"
SPLIT_REPO="${SPLIT_REPO:-tommy-hasert-dev/stackmonitor-agent-laravel}"
PREFIX=agents/laravel

if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Usage: $0 <x.y.z>" >&2
  exit 2
fi

cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

if [ "$(git branch --show-current)" != "main" ]; then
  echo "Release from main only (after the merge)." >&2
  exit 1
fi

if [ -n "$(git status --porcelain -- "${PREFIX}")" ]; then
  echo "${PREFIX} has uncommitted changes." >&2
  exit 1
fi

AGENT_VERSION="$(sed -n "s/.*AGENT_VERSION = '\([^']*\)'.*/\1/p" "${PREFIX}/src/ReportBuilder.php")"
if [ "${AGENT_VERSION}" != "${VERSION}" ]; then
  echo "ReportBuilder::AGENT_VERSION is ${AGENT_VERSION}, not ${VERSION}." >&2
  exit 1
fi

# The section of this version in CHANGELOG.md, without its heading and the blank
# lines around it ($(...) drops the trailing ones).
NOTES="$(awk -v heading="## [${VERSION}]" '
  index($0, heading) == 1 { found = 1; next }
  found && /^## \[/ { exit }
  found { print }
' "${PREFIX}/CHANGELOG.md" | sed -e '/./,$!d')"
if [ -z "${NOTES}" ]; then
  echo "${PREFIX}/CHANGELOG.md has no section for ${VERSION} (agents/changelog-draft.sh laravel helps)." >&2
  exit 1
fi

if ! command -v gh >/dev/null || ! gh auth status >/dev/null 2>&1; then
  echo "gh is missing or not logged in; it creates the GitHub release." >&2
  exit 1
fi

if [ -n "$(git ls-remote --tags "${SPLIT_REMOTE}" "refs/tags/v${VERSION}")" ]; then
  echo "v${VERSION} already exists in ${SPLIT_REMOTE}." >&2
  exit 1
fi

echo "==> Running the agent's checks (tools container)"
# Without -T and </dev/null the container eats stdin, and an answer piped in for
# the prompt below (echo y | release.sh ...) never reaches `read`.
docker compose run --rm -T -w /app/${PREFIX} tools composer test </dev/null

echo "==> Splitting ${PREFIX}"
SPLIT="$(git subtree split --prefix="${PREFIX}" 2>/dev/null)"

echo
echo "Release notes for v${VERSION}:"
echo "${NOTES}"
echo
echo "About to push ${SPLIT} to ${SPLIT_REMOTE} as main and tag v${VERSION},"
echo "then create the GitHub release v${VERSION} in ${SPLIT_REPO}."
read -r -p "Publish? [y/N] " answer
if [ "${answer}" != "y" ]; then
  echo "Aborted, nothing pushed."
  exit 1
fi

git push "${SPLIT_REMOTE}" "${SPLIT}:refs/heads/main"
git push "${SPLIT_REMOTE}" "${SPLIT}:refs/tags/v${VERSION}"
gh release create "v${VERSION}" --repo "${SPLIT_REPO}" --verify-tag \
  --title "v${VERSION}" --notes "${NOTES}"

echo "==> Done. Packagist picks up v${VERSION} via its GitHub hook (or 'Update' on packagist.org);"
echo "    Dependabot shows the release notes in its pull requests."
