# Plan: Registry Email Notifications

## Goal
Email the registry owner when an item is marked as purchased, including the purchaser's name if provided. Give owners control over notification preferences from a dedicated section on their account management page.

## Scope
- Purchase notifications only (invite emails already exist)
- Purchaser name is optional (anonymous purchases remain allowed)
- No purchaser email capture for now
- One registry per user assumed; preferences stored as user meta

## Out of Scope
- Digest emails / batched notifications
- "Registry is nearly complete" alerts
- View tracking
- Requiring purchaser login

---

## Changes

### 1. Upgrade the "Mark as Purchased" modal
**File**: `public/js/restart-registry-public.js`, `public/class-restart-registry-public.php`

Replace the `prompt()` dialog with a proper modal:
- Name field (text input, optional, with nudge copy: "Let them know who it's from!")
- Note/message field (textarea, optional, placeholder "Leave a message for the registry owner (optional)")
- "Mark as Purchased" confirm button
- Cancel button
- Submits via the existing `restart_registry_mark_purchased` AJAX action with `purchaser_name`, `purchaser_note`, and `is_anonymous` fields

### 2. Send notification email on purchase
**File**: `includes/class-restart-registry-controller.php`

- Add `private function send_purchase_notification(array $item, string $purchaser_name, string $purchaser_note): void`
  - Looks up the registry post and owner
  - Checks owner's `restart_notify_on_purchase` user meta (default: true)
  - Sends `wp_mail()` to owner with:
    - Warm intro: "Great news! Someone just marked a gift as purchased from your registry."
    - Item name + quantity purchased
    - Purchaser name (or "Someone" if anonymous)
    - Purchaser note (if provided, displayed as a blockquote-style indented block)
    - Closing copy + registry link: "Head to your registry to see what's still needed."
- Update `mark_item_purchased()` signature to accept `$purchaser_note` and call `send_purchase_notification()` after a successful Lambda update

### 3. Notification preferences section on the manage page
**File**: `public/class-restart-registry-public.php`

Add a "Notification Preferences" section rendered within `render_manage_registry()` (below the items list). Initially one setting:
- "Email me when items are purchased" (checkbox, default on)

Persisted as WordPress user meta key `restart_notify_on_purchase` (bool).

### 4. AJAX handler to save notification preferences
**File**: `public/class-restart-registry-public.php`

- Add `ajax_update_notification_prefs()` handler
- Register on `wp_ajax_restart_registry_update_notification_prefs`
- Validates nonce + login, saves user meta

### 5. JS for notification prefs
**File**: `public/js/restart-registry-public.js`

- Wire up the notification prefs checkbox to call the new AJAX handler on change
- Show a brief success/error notice inline

---

## Todo

- [x] Add "Mark as Purchased" modal HTML to guest view (name field with nudge copy, note textarea)
- [x] Replace `prompt()` in JS with modal open + form submit, pass `purchaser_note`
- [x] Add `purchaser_note` param to `ajax_mark_purchased()` and `mark_item_purchased()`
- [x] Add `send_purchase_notification()` to controller
- [x] Call `send_purchase_notification()` from `mark_item_purchased()` after successful update
- [x] Add Notification Preferences section HTML to `render_manage_registry()`
- [x] Add `ajax_update_notification_prefs()` to public class + register hook
- [x] Wire up prefs checkbox in JS
- [ ] Manual test: mark item purchased as guest → owner receives email
- [ ] Manual test: opt out via prefs → no email sent
- [ ] Manual test: anonymous purchase (no name) → email says "Someone"

---

## Plan: Unique Registry Slugs via Short Codes

**Goal:** Each registry gets a unique, short, human-safe URL slug at creation time instead of a title-derived slug that can collide across users.

**Approach:** Generate a random 6-character alphanumeric code from an unambiguous character set (`abcdefghjkmnpqrstuvwxyz23456789`, no 0/O/1/I/l) and set it as the WordPress `post_name` on insert. The existing `get_registry_by_share_key()` slug lookup works unchanged. Share links become `?registry=r3x7k2`. No new database columns or meta keys — uses the existing WP slug field.

### Short Code Implementation

**File**: `includes/class-restart-registry-controller.php`

- `_generate_short_code()` — generates a 6-char code, retries up to 10× on collision, falls back to `bin2hex(random_bytes(5))` if all collide (virtually impossible)
- `create_registry()` — adds `'post_name' => $this->_generate_short_code()` to the `wp_insert_post` call

### Short Code Todo

- [x] Add `_generate_short_code()` private method to controller
- [x] Set `post_name` to generated short code in `create_registry()`
- [ ] Manual test: create a new registry and verify its share URL uses the short code slug

---
