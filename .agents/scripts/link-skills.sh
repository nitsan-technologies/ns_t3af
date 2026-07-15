#!/usr/bin/env bash
set -euo pipefail

AGENTS_DIR="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="$(cd "$AGENTS_DIR/.." && pwd)"
TARGETS=(".claude/skills" ".cursor/skills")

for target_dir in "${TARGETS[@]}"; do
    mkdir -p "$REPO_ROOT/$target_dir"

    for skill_dir in "$AGENTS_DIR"/skills/*/; do
        [ -d "$skill_dir" ] || continue
        name="$(basename "$skill_dir")"
        link_target="../../.agents/skills/$name"
        link_path="$REPO_ROOT/$target_dir/$name"

        if [ -L "$link_path" ]; then
            [ "$(readlink "$link_path")" = "$link_target" ] && continue
            rm "$link_path"
        elif [ -e "$link_path" ]; then
            echo "ERROR: $link_path exists but is not a symlink." >&2
            exit 1
        fi

        ln -s "$link_target" "$link_path"
        echo "Linked: $link_path -> $link_target"
    done

    for link in "$REPO_ROOT/$target_dir"/*; do
        [ -L "$link" ] || continue
        target="$(readlink "$link")"
        case "$target" in
            */.agents/*)
                name="$(basename "$link")"
                if [ ! -d "$AGENTS_DIR/skills/$name" ]; then
                    rm "$link"
                    echo "Pruned: $link"
                fi
                ;;
        esac
    done
done
