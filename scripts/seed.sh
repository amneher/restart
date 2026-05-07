#!/usr/bin/env bash
# Seed WordPress and the Lambda API with demo content.
# Requires the full stack to be running: make up
set -euo pipefail

LAMBDA_URL="${LAMBDA_URL:-http://localhost:5000}"
WP_URL="${WP_URL:-http://localhost:8083}"
WP="docker compose exec -T wordpress wp --allow-root"

# ── Helpers ───────────────────────────────────────────────────────────────────

wait_for_wp() {
    echo "→ Waiting for WordPress..."
    local i=0
    until $WP core is-installed 2>/dev/null; do
        sleep 3
        i=$((i + 1))
        [ $i -gt 20 ] && echo "WordPress did not become ready in time." && exit 1
    done
    echo "  WordPress ready."
}

wait_for_lambda() {
    echo "→ Waiting for Lambda API..."
    local i=0
    until curl -sf "${LAMBDA_URL}/health" >/dev/null 2>&1; do
        sleep 3
        i=$((i + 1))
        [ $i -gt 20 ] && echo "Lambda API did not become ready in time." && exit 1
    done
    echo "  Lambda API ready."
}

lambda_post() {
    local path="$1" data="$2" auth="$3"
    curl -sf -X POST \
        -H "Content-Type: application/json" \
        -H "Authorization: Basic ${auth}" \
        -d "$data" \
        "${LAMBDA_URL}${path}"
}

lambda_put() {
    local path="$1" data="$2" auth="$3"
    curl -sf -X PUT \
        -H "Content-Type: application/json" \
        -H "Authorization: Basic ${auth}" \
        -d "$data" \
        "${LAMBDA_URL}${path}"
}

# ── Wait for services ─────────────────────────────────────────────────────────

wait_for_wp
wait_for_lambda

# ── Theme & plugin ────────────────────────────────────────────────────────────

echo "→ Activating theme and plugin..."
$WP theme activate theRestart 2>/dev/null || true
$WP plugin activate restart-registry 2>/dev/null || true

# ── Demo user ─────────────────────────────────────────────────────────────────

echo "→ Creating demo user..."
if ! $WP user get demo 2>/dev/null | grep -q demo; then
    $WP user create demo demo@example.com \
        --role=subscriber \
        --user_pass=demo \
        --display_name="Alex Demo"
fi
DEMO_ID=$($WP user get demo --field=ID)

echo "→ Generating application password for demo user..."
APP_PWD_JSON=$($WP user application-password create "$DEMO_ID" "Seed Script" --porcelain 2>/dev/null || echo "")
if [ -z "$APP_PWD_JSON" ]; then
    # Already exists — list and use the first one (can't retrieve existing passwords)
    echo "  (application password already exists — delete and re-run seed to regenerate)"
    APP_PWD=""
else
    APP_PWD="$APP_PWD_JSON"
fi

# Base64-encode credentials for Basic auth (demo:<app-password>)
if [ -n "$APP_PWD" ]; then
    AUTH=$(printf '%s:%s' "demo" "$APP_PWD" | base64 -w0)
else
    echo "  WARNING: no app password available; skipping Lambda item seeding."
    AUTH=""
fi

# ── Admin user ────────────────────────────────────────────────────────────────

echo "→ Ensuring admin user exists..."
if ! $WP user get admin 2>/dev/null | grep -q admin; then
    $WP user create admin admin@example.com \
        --role=administrator \
        --user_pass=admin \
        --display_name="Admin"
fi

# ── Pages ─────────────────────────────────────────────────────────────────────

echo "→ Creating pages..."
create_page() {
    local title="$1" slug="$2" template="${3:-}"
    if ! $WP post list --post_type=page --post_status=publish --name="$slug" --format=count | grep -q '^[1-9]'; then
        local args=(--post_type=page --post_status=publish --post_title="$title" --post_name="$slug" --porcelain)
        [ -n "$template" ] && args+=(--page_template="$template")
        $WP post create "${args[@]}"
    else
        $WP post list --post_type=page --post_status=publish --name="$slug" --field=ID
    fi
}

HOME_ID=$(create_page "Home" "home")
create_page "Login"                          "login"               "page-login"
create_page "Register"                       "register"            "page-register"
create_page "My Account"                     "my-account"          "page-my-account"
create_page "My Registries"                  "my-registries"       "page-my-registries"
create_page "Start a Registry"               "start-a-registry"    "page-start-a-registry"
create_page "FAQ"                            "faq"                 "page-faq"
create_page "About Us"                       "about-us"            "page-about-us"

$WP option update show_on_front page
$WP option update page_on_front "$HOME_ID"

# ── Blog categories ───────────────────────────────────────────────────────────

echo "→ Creating categories..."
for cat in articles gifts favorites; do
    $WP term create category "$cat" --slug="$cat" 2>/dev/null || true
done
ARTICLES_ID=$($WP term get category articles --field=term_id 2>/dev/null || echo "")

# ── Blog posts ────────────────────────────────────────────────────────────────

echo "→ Creating blog posts..."
if [ -n "$ARTICLES_ID" ]; then
    for i in 1 2 3 4 5; do
        if ! $WP post list --post_type=post --post_status=publish --post_title="Demo Article ${i}" --format=count | grep -q '^[1-9]'; then
            $WP post create \
                --post_type=post \
                --post_status=publish \
                --post_title="Demo Article ${i}: Registry Tips and Ideas" \
                --post_content="<p>Settling into a new chapter of life is exciting. Here are some thoughtful registry ideas to help you get started with your new beginning.</p><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Phasellus congue eros id ligula sodales, a tincidunt mi fermentum. Sed euismod, nisl vel ultricies lacinia, nisl nisl aliquam nisl, nec aliquam nisl nisl sit amet nisl.</p>" \
                --post_category="$ARTICLES_ID" \
                --porcelain
        fi
    done
fi

# ── Registries ────────────────────────────────────────────────────────────────

echo "→ Creating demo registries..."

create_registry() {
    local title="$1" event_type="$2" event_date="$3"
    local post_id
    if ! $WP post list --post_type=restart-registry --post_status=publish --post_title="$title" --format=count | grep -q '^[1-9]'; then
        post_id=$($WP post create \
            --post_type=restart-registry \
            --post_status=publish \
            --post_title="$title" \
            --post_author="$DEMO_ID" \
            --porcelain)
        $WP post meta update "$post_id" restart_event_type "$event_type"
        $WP post meta update "$post_id" restart_event_date "$event_date"
        $WP post meta update "$post_id" restart_invitees '[]'
        $WP post meta update "$post_id" restart_item_ids '[]'
    else
        post_id=$($WP post list --post_type=restart-registry --post_status=publish --post_title="$title" --field=ID)
    fi
    echo "$post_id"
}

REGISTRY1_ID=$(create_registry "Alex's Fresh Start Registry" "relocation" "2026-08-15")
REGISTRY2_ID=$(create_registry "Alex's New Chapter" "divorce" "2026-06-01")

# ── Registry items (via Lambda API) ───────────────────────────────────────────

seed_items() {
    local registry_id="$1" auth="$2"
    [ -z "$auth" ] && return

    local existing_ids
    existing_ids=$($WP post meta get "$registry_id" restart_item_ids 2>/dev/null || echo '[]')
    [ "$existing_ids" != '[]' ] && [ "$existing_ids" != '' ] && return

    echo "  Seeding items for registry ${registry_id}..."

    local item_ids=()

    seed_item() {
        local name="$1" url="$2" qty="$3" purchased="$4"
        local response item_id
        response=$(lambda_post "/registries/${registry_id}/items" \
            "{\"name\":\"${name}\",\"url\":\"${url}\",\"quantity_needed\":${qty},\"quantity_purchased\":${purchased}}" \
            "$auth" 2>/dev/null || echo "")
        if [ -n "$response" ]; then
            item_id=$(echo "$response" | grep -o '"id":[0-9]*' | head -1 | sed 's/"id"://')
            [ -n "$item_id" ] && item_ids+=("$item_id")
        fi
    }

    seed_item "Chef's Knife"          "https://www.amazon.com/dp/B00004RFMH" 1 0
    seed_item "Cast Iron Skillet"     "https://www.amazon.com/dp/B00006JSUA" 1 1
    seed_item "Bed Sheets (Queen)"    "https://www.amazon.com/dp/B076JGW5LB" 2 1
    seed_item "Coffee Maker"          "https://www.amazon.com/dp/B01N6T5UND" 1 0
    seed_item "Stand Mixer"           "https://www.amazon.com/dp/B00005UP2P" 1 0
    seed_item "Towel Set"             "https://www.amazon.com/dp/B074DGNR3K" 1 0
    seed_item "Cutting Board Set"     "https://www.amazon.com/dp/B07JKDBDXG" 1 1
    seed_item "Blender"               "https://www.amazon.com/dp/B008J9RH7E" 1 0

    if [ ${#item_ids[@]} -gt 0 ]; then
        local ids_json
        ids_json=$(printf '%s\n' "${item_ids[@]}" | python3 -c "import sys,json; print(json.dumps([int(l.strip()) for l in sys.stdin if l.strip()]))")
        $WP post meta update "$registry_id" restart_item_ids "$ids_json"
        echo "  Linked ${#item_ids[@]} items to registry ${registry_id}."
    fi
}

seed_items "$REGISTRY1_ID" "$AUTH"
seed_items "$REGISTRY2_ID" "$AUTH"

# ── Done ──────────────────────────────────────────────────────────────────────

echo ""
echo "✓ Demo content seeded."
echo ""
echo "  WordPress:  ${WP_URL}"
echo "  Lambda API: ${LAMBDA_URL}"
echo ""
echo "  Demo login: demo / demo"
echo "  Admin login: admin / admin"
echo ""
