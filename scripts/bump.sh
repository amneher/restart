#!/usr/bin/env bash
# Usage: ./scripts/bump.sh <component> <part>
#   component: plugin | lambda | theme | all
#   part:      patch | minor | major
set -euo pipefail

COMPONENT="${1:-}"
PART="${2:-patch}"

bump_semver() {
    local version="$1" part="$2"
    local major minor patch
    IFS='.' read -r major minor patch <<< "$version"
    case "$part" in
        major) echo "$((major + 1)).0.0" ;;
        minor) echo "${major}.$((minor + 1)).0" ;;
        patch) echo "${major}.${minor}.$((patch + 1))" ;;
        *) echo "Unknown part: $part" >&2; exit 1 ;;
    esac
}

bump_plugin() {
    local file="plugin/restart-registry.php"
    local current next
    current=$(grep -m1 '^ \* Version:' "$file" | sed 's/.*Version: //' | tr -d '[:space:]')
    next=$(bump_semver "$current" "$PART")
    sed -i "s/^ \* Version: .*/ * Version: ${next}/" "$file"
    git add "$file"
    git commit -m "chore(plugin): bump version to ${next}"
    git tag "plugin/v${next}"
    echo "plugin: ${current} → ${next}  (tag: plugin/v${next})"
}

bump_lambda() {
    local file="lambda/pyproject.toml"
    local current next
    current=$(grep '^version = ' "$file" | sed 's/version = "\(.*\)"/\1/')
    next=$(bump_semver "$current" "$PART")
    sed -i "s/^version = .*/version = \"${next}\"/" "$file"
    git add "$file"
    git commit -m "chore(lambda): bump version to ${next}"
    git tag "lambda/v${next}"
    echo "lambda: ${current} → ${next}  (tag: lambda/v${next})"
}

bump_theme() {
    local file="theme/style.css"
    local current next
    current=$(grep '^Version:' "$file" | sed 's/Version: //' | tr -d '[:space:]')
    next=$(bump_semver "$current" "$PART")
    sed -i "s/^Version: .*/Version: ${next}/" "$file"
    git add "$file"
    git commit -m "chore(theme): bump version to ${next}"
    git tag "theme/v${next}"
    echo "theme: ${current} → ${next}  (tag: theme/v${next})"
}

case "$COMPONENT" in
    plugin) bump_plugin ;;
    lambda) bump_lambda ;;
    theme)  bump_theme ;;
    all)
        bump_plugin
        bump_lambda
        bump_theme
        ;;
    *)
        echo "Usage: $0 <plugin|lambda|theme|all> [patch|minor|major]"
        exit 1
        ;;
esac
