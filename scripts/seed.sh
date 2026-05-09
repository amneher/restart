#!/usr/bin/env bash
# Seed WordPress and the Lambda API with demo content.
# Requires the full stack to be running: make up
set -euo pipefail

# Load .env if present so WP_LOCAL_USERNAME / WP_LOCAL_PASSWORD (admin creds)
# are available. Values default to admin/admin if .env is absent.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/../.env" ]; then
    set -a
    # shellcheck disable=SC1091
    . "${SCRIPT_DIR}/../.env"
    set +a
fi

LAMBDA_URL="${LAMBDA_URL:-http://localhost:5000}"
WP_URL="${WP_URL:-http://localhost:8083}"
ADMIN_USER="${WP_LOCAL_USERNAME:-admin}"
ADMIN_PASSWORD="${WP_LOCAL_PASSWORD:-admin}"
ADMIN_EMAIL="${ADMIN_USER}@example.com"
WP="docker compose exec -T wordpress wp --allow-root"

# ── Helpers ───────────────────────────────────────────────────────────────────

wait_for_wp() {
    echo "→ Waiting for WordPress..."
    local i=0
    # Probe DB connectivity via WP's internal PHP MySQL connection — `wp db *`
    # commands shell out to the `mysql` binary, which isn't in the WP image.
    until [ "$($WP eval 'global $wpdb; echo $wpdb->get_var("SELECT 1");' 2>/dev/null)" = "1" ]; do
        sleep 3
        i=$((i + 1))
        [ $i -gt 20 ] && echo "WordPress did not become ready in time." && exit 1
    done

    if ! $WP core is-installed 2>/dev/null; then
        echo "  Installing WordPress core..."
        $WP core install \
            --url="$WP_URL" \
            --title="the ReStart" \
            --admin_user="$ADMIN_USER" \
            --admin_password="$ADMIN_PASSWORD" \
            --admin_email="$ADMIN_EMAIL" \
            --skip-email >/dev/null
    fi
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

echo "→ Ensuring admin user '${ADMIN_USER}' exists..."
if ! $WP user get "$ADMIN_USER" 2>/dev/null | grep -q "$ADMIN_USER"; then
    $WP user create "$ADMIN_USER" "$ADMIN_EMAIL" \
        --role=administrator \
        --user_pass="$ADMIN_PASSWORD" \
        --display_name="Admin"
fi

# ── Lambda client credentials ─────────────────────────────────────────────────

echo "→ Configuring Lambda client credentials..."
if ! $WP option get restart_lambda_app_password >/dev/null 2>&1; then
    ADMIN_ID=$($WP user get "$ADMIN_USER" --field=ID)
    LAMBDA_PWD=$($WP user application-password create "$ADMIN_ID" "Lambda Client" --porcelain 2>/dev/null)
    if [ -n "$LAMBDA_PWD" ]; then
        $WP option update restart_lambda_username "$ADMIN_USER" >/dev/null
        $WP option update restart_lambda_app_password "$LAMBDA_PWD" >/dev/null
        echo "  Lambda credentials configured."
    fi
else
    echo "  Lambda credentials already configured."
fi

# ── Site identity ─────────────────────────────────────────────────────────────

echo "→ Setting site title and tagline..."
SITE_TITLE="the ReStart"
SITE_TAGLINE="Gift registries for life's fresh starts."
[ "$($WP option get blogname 2>/dev/null)" = "$SITE_TITLE" ] || \
    $WP option update blogname "$SITE_TITLE" >/dev/null
[ "$($WP option get blogdescription 2>/dev/null)" = "$SITE_TAGLINE" ] || \
    $WP option update blogdescription "$SITE_TAGLINE" >/dev/null

# ── Permalinks ────────────────────────────────────────────────────────────────

echo "→ Setting permalink structure..."
$WP rewrite structure '/%postname%/' --hard >/dev/null
$WP rewrite flush >/dev/null

echo "→ Enabling user registration..."
$WP option update users_can_register 1 >/dev/null
$WP option update default_role subscriber >/dev/null

# ── Pages ─────────────────────────────────────────────────────────────────────

echo "→ Creating pages..."
create_page() {
    local title="$1" slug="$2" template="${3:-}" content="${4:-}"
    if ! $WP post list --post_type=page --post_status=publish --name="$slug" --format=count | grep -q '^[1-9]'; then
        local args=(--post_type=page --post_status=publish --post_title="$title" --post_name="$slug" --porcelain)
        [ -n "$template" ] && args+=(--page_template="$template")
        [ -n "$content" ] && args+=(--post_content="$content")
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
create_page "Find a Registry"                "find-a-registry"
create_page "FAQ"                            "faq"                 "page-faq"
create_page "About Us"                       "about-us"            "page-about-us"
create_page "Terms and Conditions"           "terms-and-conditions" ""                          "<!-- wp:paragraph --><p>Terms and Conditions content coming soon.</p><!-- /wp:paragraph -->"

# WP installs a draft Privacy Policy page at slug `privacy-policy` automatically.
# Publish that draft with stub content rather than creating a parallel page (which
# would land at /privacy-policy-2/ due to the slug conflict).
PRIVACY_ID=$($WP post list --post_type=page --post_status=any --name=privacy-policy --field=ID 2>/dev/null | head -1)
if [ -n "$PRIVACY_ID" ]; then
    $WP post update "$PRIVACY_ID" --post_status=publish --post_title="Privacy Policy" \
        --post_content='<!-- wp:paragraph --><p>Privacy Policy content coming soon.</p><!-- /wp:paragraph -->' >/dev/null
else
    create_page "Privacy Policy" "privacy-policy" "" "<!-- wp:paragraph --><p>Privacy Policy content coming soon.</p><!-- /wp:paragraph -->"
fi

$WP option update show_on_front page
$WP option update page_on_front "$HOME_ID"

# ── Blog categories ───────────────────────────────────────────────────────────

echo "→ Creating categories..."
for cat in articles gifts favorites; do
    $WP term create category "$cat" --slug="$cat" 2>/dev/null || true
done
ARTICLES_ID=$($WP term list category --slug=articles --field=term_id --format=csv 2>/dev/null | grep -v term_id | head -1 || echo "")

# ── Blog posts ────────────────────────────────────────────────────────────────

echo "→ Creating blog posts..."
if [ -n "$ARTICLES_ID" ]; then
    for i in 1 2 3 4 5; do
        ARTICLE_TITLE="Demo Article ${i}: Registry Tips and Ideas"
        EXISTING_ID=$($WP eval "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare(\"SELECT ID FROM \$wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_title=%s LIMIT 1\", '$ARTICLE_TITLE'));" 2>/dev/null | tr -d '[:space:]')
        if [ -z "$EXISTING_ID" ]; then
            $WP post create \
                --post_type=post \
                --post_status=publish \
                --post_title="$ARTICLE_TITLE" \
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
    local slug post_id
    # Derive slug matching WordPress sanitize_title: remove punctuation, lowercase, spaces→hyphens
    slug=$(echo "$title" | tr '[:upper:]' '[:lower:]' | sed "s/'//g" | sed 's/[^a-z0-9]/-/g' | sed 's/-\+/-/g' | sed 's/^-\|-$//g')
    # Use wp eval for exact slug match — wp post list --post_name uses LIKE, not =
    post_id=$($WP eval "
        \$posts = get_posts(['post_type'=>'restart-registry','post_status'=>'publish','name'=>'$slug','fields'=>'ids','numberposts'=>1]);
        echo empty(\$posts) ? '' : \$posts[0];
    " 2>/dev/null | tr -d '[:space:]')
    if [ -z "$post_id" ]; then
        post_id=$($WP post create \
            --post_type=restart-registry \
            --post_status=publish \
            --post_title="$title" \
            --post_name="$slug" \
            --post_author="$DEMO_ID" \
            --porcelain)
        $WP post meta update "$post_id" restart_event_type "$event_type" >/dev/null
        $WP post meta update "$post_id" restart_event_date "$event_date" >/dev/null
        $WP post meta update "$post_id" restart_invitees '[]' >/dev/null
        $WP post meta update "$post_id" restart_item_ids '[]' >/dev/null
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
echo "  Admin login: ${ADMIN_USER} / ${ADMIN_PASSWORD}"
echo ""
