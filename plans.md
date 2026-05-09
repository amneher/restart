# Plan: Contact form modal — finish the half-built feature

Branch suggestion: continue on `audit/links-and-paths` (the contact link was flagged in the audit), or split off `theme/contact-modal-form`. Lean toward keeping it on the audit branch and amending the PR — it's the same logical work item.

## Current state (already built)

- `theme/assets/js/contact-modal.js` — intercepts `[href="#contact"]` clicks, opens/closes the modal, ESC + overlay close, focus management.
- `theme/style.css:754+` — `.rr-modal` overlay/dialog/close-button styles.
- `theme/functions.php:428-449` — enqueues the JS and injects the modal HTML in `wp_footer` on every page.
- `theme/tests/js/contact-modal.test.js` — open/close behavior covered.

The shell works. What ships today: clicking "Contact" in the nav opens an empty modal with a "Contact Us" heading and the literal text of an unrendered WPForms shortcode (`[wpforms id="YOUR_FORM_ID"]` — WPForms isn't installed, so it falls through as visible text).

## Goal

Replace the WPForms placeholder with a native contact form + server-side handler. Self-contained: no new plugin dependency, no third-party service.

## Approach

### Markup — replace the shortcode with a form
In `theme/functions.php` `wp_footer` action, swap the `do_shortcode(...)` line for a native form:
- `<input name="rr-contact-name" required>`
- `<input type="email" name="rr-contact-email" required>`
- `<textarea name="rr-contact-message" required>`
- Hidden honeypot field (must stay empty — bot trap)
- Hidden nonce field (`wp_nonce_field`)
- Submit button
- A `<div role="status" aria-live="polite">` for success/error messaging

### Handler — `wp_ajax_*` action
Add in `theme/functions.php`:
- `wp_ajax_restart_contact_submit` (logged-in users)
- `wp_ajax_nopriv_restart_contact_submit` (logged-out)

Both route to a single handler that:
1. Verifies the nonce → reject if invalid.
2. Checks the honeypot is empty → silently succeed if not (don't tip off the bot).
3. Validates name/email/message are present and email is well-formed.
4. Calls `wp_mail()` to `get_option('admin_email')` with `Reply-To: <user's email>`.
5. Returns JSON success or field-level errors.

### JS — extend `contact-modal.js`
- On submit: `e.preventDefault()`, POST to `admin-ajax.php` via `fetch`, disable the submit button.
- On success: replace the form with a "Thanks — we'll get back to you" message, auto-close after 3 seconds.
- On error: show field-level errors inline; re-enable submit.
- Localize the AJAX URL + nonce via `wp_localize_script`.

### CSS — form-in-modal styling
- New rules under the existing `.rr-modal__dialog` block: `.rr-contact-form`, `.rr-contact-form__field`, `.rr-contact-form__error`, `.rr-contact-form__status`. Visual style consistent with existing theme inputs.

### Tests
- Update `theme/tests/js/contact-modal.test.js` — the modal markup grows form fields; existing open/close tests still pass.
- Add JS tests: submit success path (mock fetch → success response), submit error path, honeypot suppression at the JS layer.
- Add PHP test in `theme/tests/unit/` for the handler: nonce missing → 403; honeypot filled → silent success without sending email; valid input → `wp_mail` invoked with expected args.

## Decisions (locked in)

1. **Fields**: name, email, **subject (optional)**, message.
2. **Where messages go**: email to `admin_email` only. CPT (`rr_contact_message`) for record-keeping is recorded as future work in `theme/TODO.md` — not in this PR.
3. **After-submit UX**: inline success message, auto-close after 3s.
4. **PR**: new branch `theme/contact-modal-form`, separate PR.

## Risk

- `wp_mail` in dev relies on docker mail config. If the local stack doesn't have a working SMTP, the form will appear to succeed but no email arrives. Worth a quick check in the dev container before relying on it.
- The `wp_navigation` post in the DB still emits `href="#contact"` — that's fine, JS intercepts it. No DB change needed.

---

## Todo

- [ ] Record the future-work CPT idea in `theme/TODO.md`.
- [ ] Replace WPForms placeholder with native form markup (name/email/subject/message + honeypot + nonce).
- [ ] Add `wp_ajax_*` + `wp_ajax_nopriv_*` handler in `functions.php`. Email to `admin_email`, Reply-To set to user.
- [ ] Localize `restartContact` (ajax URL) for the JS.
- [ ] Extend `contact-modal.js` for submit flow with success/error UI; auto-close 3s after success.
- [ ] Add CSS for form-in-modal.
- [ ] Update existing JS tests; add tests for submit success / submit error / honeypot.
- [ ] Add PHP handler test.
- [ ] Verify in browser: submit form, confirm wp_mail invoked (mail log or test mode); error paths.
- [ ] Push branch + open PR.
