<?php

/**
 * Registry Controller
 *
 * Manages registry CRUD via the WordPress CPT (restart-registry) for
 * registry metadata and the Lambda FastAPI service for item data.
 *
 * Registry identity  → WP post ID  (replaces the old share_key)
 * Item identity      → Lambda SQLite row ID (stored in restart_item_ids meta)
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry_Controller {

    /** @var Restart_Registry_Lambda_Client */
    private $lambda;

    /** @var Restart_Registry_Affiliate_Converter */
    private $affiliate_converter;

    public function __construct(
        ?Restart_Registry_Lambda_Client $lambda = null,
        ?Restart_Registry_Affiliate_Converter $affiliate_converter = null
    ) {
        require_once plugin_dir_path(__FILE__) . 'class-lambda-api-client.php';
        require_once plugin_dir_path(__FILE__) . 'class-affiliate-converter.php';
        $this->lambda              = $lambda ?? new Restart_Registry_Lambda_Client();
        $this->affiliate_converter = $affiliate_converter ?? new Restart_Registry_Affiliate_Converter();
    }

    // =========================================================================
    // Registry read
    // =========================================================================

    /**
     * Build a registry array from a WP post object (without items).
     */
    private function post_to_registry(\WP_Post $post): array {
        $invitees = json_decode(get_post_meta($post->ID, 'restart_invitees', true) ?: '[]', true) ?: [];
        $item_ids = json_decode(get_post_meta($post->ID, 'restart_item_ids', true) ?: '[]', true) ?: [];

        // is_for_self defaults to true for legacy registries that pre-date the
        // recipient meta — they were created by the recipient.
        $raw_for_self = get_post_meta($post->ID, 'restart_is_for_self', true);
        $is_for_self  = ($raw_for_self === '' || $raw_for_self === false)
            ? true
            : (string) $raw_for_self !== '0';

        return [
            'id'          => $post->ID,
            'user_id'     => (int) $post->post_author,
            'title'       => $post->post_title,
            'description' => $post->post_content,
            'is_public'   => $post->post_status === 'publish',
            'permalink'   => get_permalink($post->ID),
            // backward-compat alias used in the public shortcode class
            'share_key'   => $post->ID,
            'meta'        => [
                'invitees'                => $invitees,
                'item_ids'                => $item_ids,
                'event_type'              => get_post_meta($post->ID, 'restart_event_type', true) ?: '',
                'event_date'              => get_post_meta($post->ID, 'restart_event_date', true) ?: '',
                'is_for_self'             => $is_for_self,
                'recipient_name'          => get_post_meta($post->ID, 'restart_recipient_name', true) ?: '',
                'recipient_relationship'  => get_post_meta($post->ID, 'restart_recipient_relationship', true) ?: '',
                'recipient_email'         => get_post_meta($post->ID, 'restart_recipient_email', true) ?: '',
            ],
            // items populated separately to avoid eager-loading Lambda on every call
            'items' => [],
        ];
    }

    /**
     * Get a registry by WP post ID (with items loaded from Lambda).
     */
    public function get_registry(int $registry_id) {
        $post = get_post($registry_id);
        if (!$post || $post->post_type !== 'restart-registry') {
            return new WP_Error('not_found', __('Registry not found.', 'restart-registry'));
        }
        $registry           = $this->post_to_registry($post);
        $registry['items']  = $this->get_registry_items($registry_id);
        return $registry;
    }

    /**
     * Look up a registry by its WP post ID (numeric string) or post slug.
     * Used by the ?registry=<key> share-link flow.
     */
    public function get_registry_by_share_key(string $key) {
        if (is_numeric($key)) {
            return $this->get_registry((int) $key);
        }

        $posts = get_posts([
            'post_type'      => 'restart-registry',
            'name'           => $key,
            'posts_per_page' => 1,
            'post_status'    => ['publish', 'private'],
        ]);

        if (empty($posts)) {
            return new WP_Error('not_found', __('Registry not found.', 'restart-registry'));
        }

        $registry          = $this->post_to_registry($posts[0]);
        $registry['items'] = $this->get_registry_items($posts[0]->ID);
        return $registry;
    }

    /**
     * Get the first registry belonging to a WP user (with items).
     */
    public function get_user_registry(int $user_id): ?array {
        $posts = get_posts([
            'post_type'      => 'restart-registry',
            'author'         => $user_id,
            'posts_per_page' => 1,
            'post_status'    => ['publish', 'private', 'draft'],
        ]);

        if (empty($posts)) {
            return null;
        }

        $registry          = $this->post_to_registry($posts[0]);
        $registry['items'] = $this->get_registry_items($posts[0]->ID);
        return $registry;
    }

    // =========================================================================
    // Registry write
    // =========================================================================

    /**
     * Create a new registry WP post for a user.
     * Returns ['id' => post_id, 'share_key' => post_id] or WP_Error.
     */
    public function create_registry(int $user_id, string $title, string $description = '', bool $is_public = false, array $meta = []) {
        $existing = get_posts([
            'post_type'      => 'restart-registry',
            'author'         => $user_id,
            'posts_per_page' => 1,
            'post_status'    => ['publish', 'private', 'draft'],
        ]);

        if (!empty($existing)) {
            return new WP_Error('registry_exists', __('You already have a registry.', 'restart-registry'));
        }

        $post_id = wp_insert_post([
            'post_type'    => 'restart-registry',
            'post_title'   => sanitize_text_field($title),
            'post_content' => sanitize_textarea_field($description),
            'post_status'  => $is_public ? 'publish' : 'private',
            'post_author'  => $user_id,
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, 'restart_invitees', '[]');
        update_post_meta($post_id, 'restart_item_ids', '[]');
        update_post_meta($post_id, 'restart_event_type', sanitize_text_field($meta['event_type'] ?? ''));
        update_post_meta($post_id, 'restart_event_date', sanitize_text_field($meta['event_date'] ?? ''));

        // Recipient meta — defaults to is_for_self=true, recipient fields empty.
        $is_for_self = !isset($meta['is_for_self']) || (bool) $meta['is_for_self'];
        update_post_meta($post_id, 'restart_is_for_self', $is_for_self ? '1' : '0');
        update_post_meta($post_id, 'restart_recipient_name', sanitize_text_field($meta['recipient_name'] ?? ''));
        update_post_meta($post_id, 'restart_recipient_relationship', sanitize_text_field($meta['recipient_relationship'] ?? ''));
        update_post_meta($post_id, 'restart_recipient_email', sanitize_email($meta['recipient_email'] ?? ''));

        return [
            'id'        => $post_id,
            'share_key' => $post_id,
        ];
    }

    /**
     * Update registry post fields.
     * Accepted keys: title, description, is_public, event_type, event_date,
     * is_for_self, recipient_name, recipient_relationship, recipient_email,
     * hero_image_id (sets the post's featured image / thumbnail).
     */
    public function update_registry(int $registry_id, array $data): bool {
        $update = ['ID' => $registry_id];

        if (isset($data['title'])) {
            $update['post_title'] = sanitize_text_field($data['title']);
        }
        if (isset($data['description'])) {
            $update['post_content'] = sanitize_textarea_field($data['description']);
        }
        if (isset($data['is_public'])) {
            $update['post_status'] = $data['is_public'] ? 'publish' : 'private';
        }

        if (count($update) > 1) {
            wp_update_post($update);
        }

        if (isset($data['event_type'])) {
            update_post_meta($registry_id, 'restart_event_type', sanitize_text_field($data['event_type']));
        }
        if (isset($data['event_date'])) {
            update_post_meta($registry_id, 'restart_event_date', sanitize_text_field($data['event_date']));
        }
        if (isset($data['is_for_self'])) {
            update_post_meta($registry_id, 'restart_is_for_self', $data['is_for_self'] ? '1' : '0');
        }
        if (isset($data['recipient_name'])) {
            update_post_meta($registry_id, 'restart_recipient_name', sanitize_text_field($data['recipient_name']));
        }
        if (isset($data['recipient_relationship'])) {
            update_post_meta($registry_id, 'restart_recipient_relationship', sanitize_text_field($data['recipient_relationship']));
        }
        if (isset($data['recipient_email'])) {
            update_post_meta($registry_id, 'restart_recipient_email', sanitize_email($data['recipient_email']));
        }
        if (array_key_exists('hero_image_id', $data)) {
            $attach_id = (int) $data['hero_image_id'];
            if ($attach_id > 0) {
                set_post_thumbnail($registry_id, $attach_id);
            } else {
                delete_post_thumbnail($registry_id);
            }
        }

        return true;
    }

    /**
     * Delete a registry post and all its Lambda items.
     */
    public function delete_registry(int $registry_id): bool {
        $item_ids = json_decode(get_post_meta($registry_id, 'restart_item_ids', true) ?: '[]', true) ?: [];
        foreach ($item_ids as $item_id) {
            $this->lambda->delete_item((int) $item_id);
        }
        return (bool) wp_delete_post($registry_id, true);
    }

    // =========================================================================
    // Items
    // =========================================================================

    /**
     * Fetch all Lambda items for a registry (order preserved from meta).
     */
    public function get_registry_items(int $registry_id): array {
        $item_ids = json_decode(get_post_meta($registry_id, 'restart_item_ids', true) ?: '[]', true) ?: [];
        if (empty($item_ids)) {
            return [];
        }
        return $this->lambda->get_items($item_ids);
    }

    /**
     * Fetch a single Lambda item.
     */
    public function get_item(int $item_id) {
        return $this->lambda->get_item($item_id);
    }

    /**
     * Create a Lambda item and link its ID to the registry meta.
     * Returns ['id' => lambda_id, 'is_affiliate' => bool, 'retailer' => string] or WP_Error.
     *
     * Required: name, url.  Optional: description, price, quantity.
     */
    public function add_item(int $registry_id, array $data) {
        $url              = esc_url_raw($data['url']);
        $affiliate_result = $this->affiliate_converter->convert_url($url);

        $lambda_data = [
            'registry_id' => $registry_id,
            'name'        => $this->truncate_name(sanitize_text_field($data['name'])),
            'url'         => $url,
            'price'       => !empty($data['price']) ? (float) $data['price'] : null,
        ];

        if (!empty($data['description'])) {
            $lambda_data['description'] = sanitize_textarea_field($data['description']);
        }
        if ($affiliate_result['retailer']) {
            $lambda_data['retailer'] = $affiliate_result['retailer'];
        }
        if ($affiliate_result['is_affiliate'] && !empty($affiliate_result['affiliate_url'])) {
            $lambda_data['affiliate_url']    = $affiliate_result['affiliate_url'];
            $lambda_data['affiliate_status'] = 'active';
        }
        if (!empty($data['quantity'])) {
            $lambda_data['quantity_needed'] = (int) $data['quantity'];
        }
        if (!empty($data['image_url'])) {
            $lambda_data['image_url'] = esc_url_raw($data['image_url']);
        }

        $item = $this->lambda->create_item($lambda_data);
        if (is_wp_error($item)) {
            return $item;
        }

        // Link item ID in post meta
        $item_ids   = json_decode(get_post_meta($registry_id, 'restart_item_ids', true) ?: '[]', true) ?: [];
        $item_ids[] = (int) $item['id'];
        update_post_meta($registry_id, 'restart_item_ids', json_encode($item_ids));

        return [
            'id'           => $item['id'],
            'is_affiliate' => $affiliate_result['is_affiliate'],
            'retailer'     => $affiliate_result['retailer'],
            'html_item'    => $item,
        ];
    }

    /**
     * Update a Lambda item's editable fields.
     * Accepted keys: name, url, description, price, quantity (→ quantity_needed).
     */
    public function update_item(int $item_id, array $data) {
        $update = [];
        if (isset($data['name']))        $update['name']           = $this->truncate_name(sanitize_text_field($data['name']));
        if (!empty($data['url']))        $update['url']            = esc_url_raw($data['url']);
        if (isset($data['description'])) $update['description']    = sanitize_textarea_field($data['description']);
        if (!empty($data['price']))      $update['price']          = max(0.01, (float) $data['price']);
        if (isset($data['quantity']))    $update['quantity_needed'] = max(1, (int) $data['quantity']);
        if (isset($data['image_url']))   $update['image_url']      = !empty($data['image_url']) ? esc_url_raw($data['image_url']) : null;

        if (empty($update)) {
            return new WP_Error('no_data', __('No data to update.', 'restart-registry'));
        }

        return $this->lambda->update_item($item_id, $update);
    }

    /**
     * Delete a Lambda item and remove it from the registry meta.
     */
    public function delete_item(int $item_id, int $registry_id): bool {
        $this->lambda->delete_item($item_id);

        $item_ids = json_decode(get_post_meta($registry_id, 'restart_item_ids', true) ?: '[]', true) ?: [];
        $item_ids = array_values(array_filter($item_ids, fn($id) => (int) $id !== $item_id));
        update_post_meta($registry_id, 'restart_item_ids', json_encode($item_ids));

        return true;
    }

    /**
     * Increment quantity_purchased for an item.
     * Returns the updated item or WP_Error.
     */
    public function mark_item_purchased(int $item_id, int $quantity = 1, string $purchaser_name = '', string $purchaser_email = '', string $purchaser_note = '', bool $is_anonymous = false) {
        $item = $this->lambda->get_item($item_id);
        if (!$item || is_wp_error($item)) {
            return new WP_Error('not_found', __('Item not found.', 'restart-registry'));
        }

        $current   = (int) ($item['quantity_purchased'] ?? 0);
        $needed    = (int) ($item['quantity_needed'] ?? 1);
        $remaining = $needed - $current;

        if ($quantity > $remaining) {
            return new WP_Error('quantity_exceeded', __('Cannot purchase more than needed.', 'restart-registry'));
        }

        $result = $this->lambda->update_item($item_id, ['quantity_purchased' => $current + $quantity]);
        if (!is_wp_error($result)) {
            $this->send_purchase_notification($item, $purchaser_name, $purchaser_note);
        }
        return $result;
    }

    // =========================================================================
    // Invites
    // =========================================================================

    /**
     * Add an email or WP username to the registry invitee list and send an email.
     * Returns ['invite_id' => index] or WP_Error.
     */
    public function send_invite(int $registry_id, string $invitee) {
        $invitees = json_decode(get_post_meta($registry_id, 'restart_invitees', true) ?: '[]', true) ?: [];

        if (in_array($invitee, $invitees, true)) {
            return new WP_Error('already_invited', __('This contact has already been invited.', 'restart-registry'));
        }

        $invitees[] = $invitee;
        update_post_meta($registry_id, 'restart_invitees', json_encode($invitees));

        if (is_email($invitee)) {
            $this->send_invite_email($invitee, $registry_id);
        }

        return ['invite_id' => count($invitees) - 1];
    }

    /**
     * Return the invitee list as an array of row-like arrays.
     */
    public function get_registry_invites(int $registry_id): array {
        $invitees = json_decode(get_post_meta($registry_id, 'restart_invitees', true) ?: '[]', true) ?: [];
        return array_map(
            fn($invitee, $i) => ['id' => $i, 'email' => $invitee],
            $invitees,
            array_keys($invitees)
        );
    }

    /**
     * Remove a single invitee from the registry by their email/username string.
     * Returns true on success, WP_Error if the invitee wasn't on the list.
     */
    public function delete_invitee(int $registry_id, string $invitee) {
        $invitees = json_decode(get_post_meta($registry_id, 'restart_invitees', true) ?: '[]', true) ?: [];
        $idx = array_search($invitee, $invitees, true);
        if ($idx === false) {
            return new WP_Error('not_invited', __('That contact is not currently invited.', 'restart-registry'));
        }
        array_splice($invitees, $idx, 1);
        update_post_meta($registry_id, 'restart_invitees', json_encode(array_values($invitees)));
        return true;
    }

    private function send_purchase_notification(array $item, string $purchaser_name, string $purchaser_note): void {
        $registry_id = (int) ($item['registry_id'] ?? 0);
        if (!$registry_id) return;

        $post = get_post($registry_id);
        if (!$post) return;

        $owner_id = (int) $post->post_author;
        $owner    = get_userdata($owner_id);
        if (!$owner) return;

        // Respect opt-out (default: notify)
        if (get_user_meta($owner_id, 'restart_notify_on_purchase', true) === '0') return;

        $registry_title = $post->post_title;
        $registry_url   = get_permalink($registry_id) ?: home_url('/');
        $item_name      = $item['name'] ?? __('an item', 'restart-registry');
        $from_email     = get_option('restart_registry_email_from', get_option('admin_email'));
        $from_name      = get_option('restart_registry_email_name', get_bloginfo('name'));

        $display_name = $purchaser_name ?: __('Someone', 'restart-registry');

        $subject = sprintf(
            /* translators: 1: purchaser name or "Someone", 2: item name */
            __('%1$s just purchased "%2$s" from your registry!', 'restart-registry'),
            $display_name,
            $item_name
        );

        $lines = [
            sprintf(__('Hi %s,', 'restart-registry'), $owner->display_name),
            '',
            sprintf(
                /* translators: 1: purchaser name or "Someone", 2: registry title */
                __('Great news — %1$s just marked a gift as purchased from your registry "%2$s".', 'restart-registry'),
                $display_name,
                $registry_title
            ),
            '',
            sprintf(__('Gift purchased: %s', 'restart-registry'), $item_name),
        ];

        if ($purchaser_name) {
            $lines[] = sprintf(__('From: %s', 'restart-registry'), $purchaser_name);
        }

        if ($purchaser_note) {
            $lines[] = '';
            $lines[] = __('They left you a message:', 'restart-registry');
            $lines[] = '';
            // Indent each line of the note
            foreach (explode("\n", $purchaser_note) as $note_line) {
                $lines[] = '    ' . $note_line;
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            __("Head to your registry to see what's still needed:\n%s", 'restart-registry'),
            $registry_url
        );
        $lines[] = '';
        $lines[] = __('With love,', 'restart-registry');
        $lines[] = __('The Restart Team', 'restart-registry');

        wp_mail(
            $owner->user_email,
            $subject,
            implode("\n", $lines),
            ['From: ' . $from_name . ' <' . $from_email . '>']
        );
    }

    private function send_invite_email(string $email, int $registry_id): void {
        $post      = get_post($registry_id);
        $author    = $post ? get_userdata((int) $post->post_author) : null;
        $name      = $author ? $author->display_name : __('Someone', 'restart-registry');
        $title     = $post ? $post->post_title : __('a gift registry', 'restart-registry');
        $link      = get_permalink($registry_id) ?: home_url('/');

        $subject = sprintf(__('%s invited you to view their gift registry!', 'restart-registry'), $name);
        $message = sprintf(
            __("Hello!\n\n%s has invited you to view their gift registry: %s\n\nClick below to see their wishlist:\n%s\n\nBest,\nThe Restart Team", 'restart-registry'),
            $name,
            $title,
            $link
        );

        wp_mail($email, $subject, $message);
    }

    // =========================================================================
    // Access control
    // =========================================================================

    /**
     * True if the user may view the registry (public, owner, admin, or invitee).
     */
    public function can_view_registry(int $registry_id, ?int $user_id = null): bool {
        $post = get_post($registry_id);
        if (!$post || $post->post_type !== 'restart-registry') {
            return false;
        }

        if ($post->post_status === 'publish') {
            return true;
        }
        if ($user_id && (int) $post->post_author === $user_id) {
            return true;
        }
        if ($user_id && user_can($user_id, 'manage_restart_registry')) {
            return true;
        }

        // Check invitee list (email or username match)
        if ($user_id) {
            $invitees = json_decode(get_post_meta($registry_id, 'restart_invitees', true) ?: '[]', true) ?: [];
            $user     = get_userdata($user_id);
            if ($user && (
                in_array($user->user_email, $invitees, true) ||
                in_array($user->user_login, $invitees, true)
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shorten a product name to at most 100 characters.
     *
     * Tries common product-name separators (` - `, ` | `, `: `) that divide the
     * core product name from variant/spec detail before falling back to a
     * word-boundary cut. Comma-space is tried last and only when the leading
     * segment is at least 20 characters, to avoid splitting "Brand, Product".
     */
    private function truncate_name(string $name): string {
        if (mb_strlen($name) <= 100) {
            return $name;
        }

        // Separators that typically mark the end of the core product name
        foreach ([' - ', ' | ', ': ', ' – ', ' — '] as $sep) {
            $pos = mb_strpos($name, $sep);
            if ($pos !== false && $pos >= 8) {
                $candidate = mb_substr($name, 0, $pos);
                if (mb_strlen($candidate) <= 100) {
                    return $candidate;
                }
            }
        }

        // Comma-space: only when the leading segment is substantial
        $comma_pos = mb_strpos($name, ', ');
        if ($comma_pos !== false && $comma_pos >= 20) {
            $candidate = mb_substr($name, 0, $comma_pos);
            if (mb_strlen($candidate) <= 100) {
                return $candidate;
            }
        }

        // Word-boundary fallback
        $truncated  = mb_substr($name, 0, 100);
        $last_space = mb_strrpos($truncated, ' ');
        return ($last_space > 50 ? mb_substr($truncated, 0, $last_space) : $truncated);
    }

    /**
     * True if the user may edit the registry (author or admin).
     */
    public function can_edit_registry(int $registry_id, int $user_id): bool {
        $post = get_post($registry_id);
        if (!$post || $post->post_type !== 'restart-registry') {
            return false;
        }
        return (int) $post->post_author === $user_id || user_can($user_id, 'manage_restart_registry');
    }
}