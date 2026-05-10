(function () {
    const form = document.getElementById('restart-registry-form');
    if (!form) return;

    const privateToggle = document.getElementById('is-private');
    const inviteesGroup = document.getElementById('invitees-group');
    const errorBox = document.getElementById('restart-form-error');

    privateToggle.addEventListener('change', function () {
        inviteesGroup.hidden = !this.checked;
        if (!this.checked) {
            document.getElementById('invitees').value = '';
        }
    });

    // Recipient toggle: visible "for someone else" checkbox flips the
    // hidden is_for_self field and reveals the recipient fields.
    const notForSelfToggle = document.getElementById('rr-not-for-self');
    const isForSelfInput   = document.getElementById('rr-is-for-self');
    const recipientFields  = document.getElementById('rr-recipient-fields');
    if (notForSelfToggle && isForSelfInput && recipientFields) {
        notForSelfToggle.addEventListener('change', function () {
            isForSelfInput.value = this.checked ? '0' : '1';
            recipientFields.hidden = !this.checked;
            if (!this.checked) {
                ['rr-recipient-name', 'rr-recipient-relationship', 'rr-recipient-email'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (el) el.value = '';
                });
            }
        });
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        errorBox.hidden = true;

        const title = document.getElementById('registry-title').value.trim();
        if (!title) {
            showError('Registry Name is required.');
            return;
        }

        const isPrivate = privateToggle.checked;
        const inviteesRaw = document.getElementById('invitees').value;
        const invitees = isPrivate
            ? inviteesRaw.split(',').map(s => s.trim()).filter(Boolean)
            : [];

        const eventType = document.getElementById('event-type').value || null;
        const eventDate = document.getElementById('event-date').value || null;
        const story = document.getElementById('registry-story').value.trim() || null;
        const isForSelf = (isForSelfInput && isForSelfInput.value === '1');
        const recipientName = document.getElementById('rr-recipient-name')?.value?.trim() || null;
        const recipientRel  = document.getElementById('rr-recipient-relationship')?.value?.trim() || null;
        const recipientEmail = document.getElementById('rr-recipient-email')?.value?.trim() || null;

        const payload = {
            title,
            username: restartRegistry.username,
            is_private: isPrivate,
            story,
            meta: {
                event_type: eventType,
                event_date: eventDate,
                invitees,
                item_ids: [],
                is_for_self: isForSelf,
                recipient_name: isForSelf ? null : recipientName,
                recipient_relationship: isForSelf ? null : recipientRel,
                recipient_email: isForSelf ? null : recipientEmail,
            },
        };

        const credentials = btoa(restartRegistry.username + ':' + restartRegistry.apiKey);
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating\u2026';

        try {
            const res = await fetch(restartRegistry.lambdaUrl + '/registries', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Basic ' + credentials,
                },
                body: JSON.stringify(payload),
            });

            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                const detail = data.detail || ('Server error (' + res.status + ')');
                showError(typeof detail === 'string' ? detail : JSON.stringify(detail));
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Registry';
                return;
            }

            window.location.href = restartRegistry.myAccountUrl;
        } catch (err) {
            showError('Could not reach the server. Please try again.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create Registry';
        }
    });

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.hidden = false;
    }
})();
