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
    sed -i "s/define( 'RESTART_REGISTRY_VERSION', '.*' );/define( 'RESTART_REGISTRY_VERSION', '${next}' );/" "$file"

    # Rebuild .min JS/CSS and run the JS suite + CSS structural-equivalence
    # check against them, so a release never ships a stale or broken
    # minified build. Aborts the bump (set -e) if either check fails.
    echo "Rebuilding and verifying minified plugin assets..."
    make -C plugin test-assets-min

    git add "$file" \
        plugin/public/js/*.min.js plugin/admin/js/*.min.js \
        plugin/public/css/*.min.css plugin/admin/css/*.min.css
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
    sed -i "s/^__version__ = .*/__version__ = \"${next}\"/" "lambda/app/_version.py"
    git add "$file" lambda/app/_version.py
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

    # Rebuild .min JS/CSS (style.min.css picks up the new version header)
    # and run the JS suite + CSS structural-equivalence check against them,
    # so a release never ships a stale or broken minified build. Aborts the
    # bump (set -e) if either check fails.
    echo "Rebuilding and verifying minified theme assets..."
    make -C theme test-assets-min

    git add "$file" theme/style.min.css theme/assets/js/*.min.js
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
