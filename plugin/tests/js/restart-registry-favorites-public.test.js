'use strict';

// Covers the Our Favorites page front-end behavior added in
// restart-registry-public.js: the room/tier filter pills
// ([restart_favorites_filters]) and the "Add all <tier> items" bulk-add
// button ([restart_favorites_room]), plus a few regression checks that the
// addItemToRegistry() extraction didn't change single-item quick-add
// behavior.

const flushPromises = () => new Promise(process.nextTick);

global.restartRegistry = {
    ajaxUrl: '/wp-admin/admin-ajax.php',
    nonce: 'test-nonce',
    isLoggedIn: true,
    hasRegistry: true,
    loginUrl: '/login/',
    createRegistryUrl: '/start-a-registry/',
    strings: {
        loading: 'Loading…',
        added: 'Added!',
        error: 'Something went wrong.',
    },
};

window.alert = jest.fn();

function quickAddButton({ tier, name, price, added = false }) {
    return `
        <div class="rr-article-item rr-article-item--tier" data-tier="${tier}">
            <button type="button"
                class="rr-button rr-button-secondary rr-quick-add${added ? ' rr-quick-add--added' : ''}"
                data-tier="${tier}"
                data-name="${name}"
                data-url="https://example.com/${encodeURIComponent(name)}"
                data-price="${price}"
                data-image-url=""
                data-description=""
                data-notes=""
                data-quantity="1">+ Add to My Registry</button>
        </div>
    `;
}

function favoritesRoom(roomName, rowsHtml) {
    return `
        <section class="rr-favorites-room" data-room="${roomName}">
            <div class="rr-favorites-room__header">
                <h3 class="rr-favorites-room__title">${roomName}</h3>
                <div class="rr-favorites-room__bulk-actions">
                    <button type="button" class="rr-button rr-button-secondary rr-bulk-add" data-tier="save">Add all Save items</button>
                    <button type="button" class="rr-button rr-button-secondary rr-bulk-add" data-tier="spend">Add all Spend items</button>
                    <button type="button" class="rr-button rr-button-secondary rr-bulk-add" data-tier="splurge">Add all Splurge items</button>
                </div>
            </div>
            <div class="rr-favorites-room__rows">${rowsHtml}</div>
        </section>
    `;
}

function quickAddModals() {
    return `
        <div class="rr-modal rr-quick-add-modal" id="rr-qa-auth-modal" aria-inert="true">
            <div class="rr-modal__body">
                <p class="rr-qa-modal__item-name"></p>
                <a id="rr-qa-login-link" href="#">Sign In</a>
                <a id="rr-qa-register-link" href="#">Create a Registry</a>
            </div>
        </div>
        <div class="rr-modal rr-quick-add-modal" id="rr-qa-no-registry-modal" aria-inert="true">
            <div class="rr-modal__body"><p>No registry.</p></div>
        </div>
    `;
}

function filterBar(rooms = []) {
    const tierPills = ['save', 'spend', 'splurge']
        .map((t) => `<button type="button" class="rr-favorites-filters__pill is-active" data-tier-pill="${t}">${t}</button>`)
        .join('');
    return `
        <div class="rr-favorites-filters">
            <div class="rr-favorites-filters__group rr-favorites-filters__group--rooms" data-room-pills></div>
            <div class="rr-favorites-filters__group rr-favorites-filters__group--tiers" data-tier-pills>${tierPills}</div>
        </div>
    `;
}

function buildTwoRoomPage() {
    document.body.innerHTML = filterBar()
        + favoritesRoom('Living Room',
            quickAddButton({ tier: 'save', name: 'Budget Sofa', price: '299' })
            + quickAddButton({ tier: 'spend', name: 'Mid Sofa', price: '599' })
            + quickAddButton({ tier: 'save', name: 'Budget Lamp', price: '19' }))
        + favoritesRoom('Bathroom',
            quickAddButton({ tier: 'save', name: 'Budget Towels', price: '29' })
            + quickAddButton({ tier: 'splurge', name: 'Luxury Towels', price: '129' }))
        + quickAddModals();
}

function loadModule() {
    jest.isolateModules(() => {
        require('../../public/js/restart-registry-public.js');
    });
}

beforeEach(() => {
    jest.clearAllMocks();
    global.fetch = jest.fn();
    restartRegistry.isLoggedIn  = true;
    restartRegistry.hasRegistry = true;
});

// ── Filter pills ─────────────────────────────────────────────────────────────

describe('room/tier filter pills', () => {
    beforeEach(() => {
        buildTwoRoomPage();
        loadModule();
    });

    it('populates one room pill per [data-room] section found on the page', () => {
        const pills = Array.from(document.querySelectorAll('[data-room-pill]'));
        expect(pills.map((p) => p.dataset.roomPill)).toEqual(['Living Room', 'Bathroom']);
        expect(pills.map((p) => p.textContent)).toEqual(['Living Room', 'Bathroom']);
    });

    it('room pills start active', () => {
        document.querySelectorAll('[data-room-pill]').forEach((p) => {
            expect(p.classList.contains('is-active')).toBe(true);
        });
    });

    it('clicking a room pill hides that room and toggles the pill off', () => {
        const pill = document.querySelector('[data-room-pill="Bathroom"]');
        pill.click();

        expect(pill.classList.contains('is-active')).toBe(false);
        expect(document.querySelector('.rr-favorites-room[data-room="Bathroom"]').style.display).toBe('none');
        expect(document.querySelector('.rr-favorites-room[data-room="Living Room"]').style.display).toBe('');
    });

    it('clicking an inactive room pill again shows the room and reactivates the pill', () => {
        const pill = document.querySelector('[data-room-pill="Bathroom"]');
        pill.click();
        pill.click();

        expect(pill.classList.contains('is-active')).toBe(true);
        expect(document.querySelector('.rr-favorites-room[data-room="Bathroom"]').style.display).toBe('');
    });

    it('clicking a tier pill hides matching cards across every room, not just one', () => {
        const pill = document.querySelector('[data-tier-pill="save"]');
        pill.click();

        expect(pill.classList.contains('is-active')).toBe(false);
        document.querySelectorAll('.rr-article-item--tier[data-tier="save"]').forEach((card) => {
            expect(card.style.display).toBe('none');
        });
        // Untouched tiers stay visible
        expect(document.querySelector('.rr-article-item--tier[data-tier="spend"]').style.display).toBe('');
        expect(document.querySelector('.rr-article-item--tier[data-tier="splurge"]').style.display).toBe('');
    });

    it('clicking an inactive tier pill again shows those cards again', () => {
        const pill = document.querySelector('[data-tier-pill="save"]');
        pill.click();
        pill.click();

        expect(pill.classList.contains('is-active')).toBe(true);
        document.querySelectorAll('.rr-article-item--tier[data-tier="save"]').forEach((card) => {
            expect(card.style.display).toBe('');
        });
    });

    it('does nothing when there is no .rr-favorites-filters bar on the page (no error)', () => {
        document.body.innerHTML = favoritesRoom('Living Room', quickAddButton({ tier: 'save', name: 'X', price: '1' }));
        expect(() => loadModule()).not.toThrow();
    });
});

// ── Bulk-add ─────────────────────────────────────────────────────────────────

describe('bulk-add ("Add all <tier> items in this room")', () => {
    beforeEach(() => {
        buildTwoRoomPage();
        loadModule();
    });

    it('opens the auth modal and does not call fetch when not logged in', () => {
        restartRegistry.isLoggedIn = false;

        document.querySelector('.rr-favorites-room[data-room="Living Room"] .rr-bulk-add[data-tier="save"]').click();

        expect(document.getElementById('rr-qa-auth-modal').classList.contains('is-open')).toBe(true);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('sets the item-name slot to an item count when opening the auth modal', () => {
        restartRegistry.isLoggedIn = false;

        document.querySelector('.rr-favorites-room[data-room="Living Room"] .rr-bulk-add[data-tier="save"]').click();

        expect(document.querySelector('#rr-qa-auth-modal .rr-qa-modal__item-name').textContent).toBe('2 items');
    });

    it('opens the no-registry modal and does not call fetch when logged in with no registry', () => {
        restartRegistry.hasRegistry = false;

        document.querySelector('.rr-favorites-room[data-room="Living Room"] .rr-bulk-add[data-tier="save"]').click();

        expect(document.getElementById('rr-qa-no-registry-modal').classList.contains('is-open')).toBe(true);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('calls fetch once per matching item in that room only', async () => {
        fetch.mockResolvedValue({ json: () => Promise.resolve({ success: true }) });

        document.querySelector('.rr-favorites-room[data-room="Living Room"] .rr-bulk-add[data-tier="save"]').click();
        await flushPromises();
        await flushPromises();

        // Living Room has 2 "save" items (Budget Sofa, Budget Lamp); Bathroom's
        // "save" item (Budget Towels) must not be touched by this room's button.
        expect(fetch).toHaveBeenCalledTimes(2);
        const names = fetch.mock.calls.map(([, opts]) => new URLSearchParams(opts.body).get('name'));
        expect(names.sort()).toEqual(['Budget Lamp', 'Budget Sofa']);
    });

    it('marks each successfully-added item button as added', async () => {
        fetch.mockResolvedValue({ json: () => Promise.resolve({ success: true }) });

        document.querySelector('.rr-favorites-room[data-room="Living Room"] .rr-bulk-add[data-tier="save"]').click();
        await flushPromises();
        await flushPromises();

        const room = document.querySelector('.rr-favorites-room[data-room="Living Room"]');
        room.querySelectorAll('.rr-quick-add[data-tier="save"]').forEach((btn) => {
            expect(btn.classList.contains('rr-quick-add--added')).toBe(true);
            expect(btn.disabled).toBe(true);
        });
    });

    it('reports a partial-failure summary and does not mark the failed item as added', async () => {
        fetch
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: true }) })
            .mockResolvedValueOnce({ json: () => Promise.resolve({ success: false, data: { message: 'nope' } }) });

        const bulkBtn = document.querySelector('.rr-favorites-room[data-room="Living Room"] .rr-bulk-add[data-tier="save"]');
        bulkBtn.click();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        expect(bulkBtn.textContent).toBe('Added 1 of 2 — 1 failed');
        const addedButtons = document.querySelectorAll('.rr-favorites-room[data-room="Living Room"] .rr-quick-add[data-tier="save"].rr-quick-add--added');
        expect(addedButtons.length).toBe(1);
    });

    it('skips items already marked as added', () => {
        document.body.innerHTML = filterBar()
            + favoritesRoom('Living Room', quickAddButton({ tier: 'save', name: 'Already Added', price: '10', added: true }))
            + quickAddModals();
        loadModule();
        fetch.mockResolvedValue({ json: () => Promise.resolve({ success: true }) });

        document.querySelector('.rr-bulk-add[data-tier="save"]').click();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('does nothing when the tier has no items in that room', () => {
        document.querySelector('.rr-favorites-room[data-room="Bathroom"] .rr-bulk-add[data-tier="spend"]').click();

        expect(fetch).not.toHaveBeenCalled();
    });
});

// ── Single quick-add regression (addItemToRegistry extraction) ───────────────

describe('single quick-add button (unchanged behavior after addItemToRegistry extraction)', () => {
    beforeEach(() => {
        buildTwoRoomPage();
        loadModule();
    });

    it('opens the auth modal with the item name when not logged in', () => {
        restartRegistry.isLoggedIn = false;

        document.querySelector('.rr-quick-add[data-name="Budget Sofa"]').click();

        expect(document.getElementById('rr-qa-auth-modal').classList.contains('is-open')).toBe(true);
        expect(document.querySelector('#rr-qa-auth-modal .rr-qa-modal__item-name').textContent).toBe('Budget Sofa');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('opens the no-registry modal when logged in with no registry', () => {
        restartRegistry.hasRegistry = false;

        document.querySelector('.rr-quick-add[data-name="Budget Sofa"]').click();

        expect(document.getElementById('rr-qa-no-registry-modal').classList.contains('is-open')).toBe(true);
    });

    it('posts the button\'s dataset and shows the added state on success', async () => {
        fetch.mockResolvedValue({ json: () => Promise.resolve({ success: true }) });
        const btn = document.querySelector('.rr-quick-add[data-name="Budget Sofa"]');

        btn.click();
        await flushPromises();

        expect(fetch).toHaveBeenCalledTimes(1);
        const [, opts] = fetch.mock.calls[0];
        const body = new URLSearchParams(opts.body);
        expect(body.get('action')).toBe('restart_registry_quick_add');
        expect(body.get('name')).toBe('Budget Sofa');
        expect(body.get('price')).toBe('299');
        expect(btn.textContent).toBe('Added!');
        expect(btn.classList.contains('rr-quick-add--added')).toBe(true);
    });
});
