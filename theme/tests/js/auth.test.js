const $ = require('jquery');

global.$ = $;
global.jQuery = $;

describe('auth.js', () => {
    const loadScript = () => {
        jest.resetModules();
        require('../../assets/js/auth.js');
    };

    beforeEach(() => {
        document.body.innerHTML = '';
        window.restartAuth = {
            ajaxUrl: '/ajax',
            registerNonce: 'reg-nonce',
            updateProfileNonce: 'profile-nonce',
        };
    });

    afterEach(() => {
        jest.restoreAllMocks();
        $(document).off('submit click');
    });

    describe('register form', () => {
        const setupDom = () => {
            document.body.innerHTML = `
                <form id="rr-register-form">
                    <input name="username" value="alex" />
                    <input name="email" value="alex@example.com" />
                    <input name="password" value="hunter2hunter2" />
                    <button id="rr-register-submit">Create Account</button>
                </form>
                <div id="rr-register-error" style="display:none"></div>
            `;
        };

        test('calls ajax with correct data on submit', () => {
            const deferred = $.Deferred();
            const postSpy = jest.spyOn($, 'post').mockReturnValue(deferred.promise());

            setupDom();
            loadScript();

            $('#rr-register-form').trigger('submit');

            expect(postSpy).toHaveBeenCalledTimes(1);
            const [url, data] = postSpy.mock.calls[0];
            expect(url).toBe('/ajax');
            expect(data.action).toBe('restart_register');
            expect(data.username).toBe('alex');
            expect(data.email).toBe('alex@example.com');
            expect(data.password).toBe('hunter2hunter2');
            expect(data.nonce).toBe('reg-nonce');

            deferred.resolve({ success: true, data: { redirect: '/my-account/' } });
        });

        test('shows error on failure response', () => {
            const deferred = $.Deferred();
            jest.spyOn($, 'post').mockReturnValue(deferred.promise());

            setupDom();
            loadScript();

            $('#rr-register-form').trigger('submit');
            deferred.resolve({ success: false, data: { message: 'Username taken' } });

            const $err = $('#rr-register-error');
            expect($err.text()).toBe('Username taken');
            expect($err.css('display')).not.toBe('none');
        });

        test('shows generic error on ajax failure', () => {
            const deferred = $.Deferred();
            jest.spyOn($, 'post').mockReturnValue(deferred.promise());

            setupDom();
            loadScript();

            $('#rr-register-form').trigger('submit');
            deferred.reject();

            const $err = $('#rr-register-error');
            expect($err.text()).toMatch(/Something went wrong/);
            expect($err.css('display')).not.toBe('none');
        });
    });

    describe('profile toggle', () => {
        test('shows panel when clicked', () => {
            document.body.innerHTML = `
                <a id="rr-edit-profile-toggle" href="#edit-profile">Edit Profile</a>
                <div id="rr-edit-profile-panel" hidden></div>
            `;

            const slideDownSpy = jest.spyOn($.fn, 'slideDown')
                .mockImplementation(function () { return this; });

            loadScript();

            $('#rr-edit-profile-toggle').trigger('click');

            expect($('#rr-edit-profile-panel').attr('hidden')).toBeUndefined();
            expect(slideDownSpy).toHaveBeenCalled();
        });
    });

    describe('profile form', () => {
        test('calls ajax on submit', () => {
            document.body.innerHTML = `
                <form id="rr-profile-form">
                    <input id="rr-profile-nonce" value="profile-nonce-val" />
                    <input name="display_name" value="Alex" />
                    <input name="email" value="alex@example.com" />
                    <input name="password" value="" />
                    <button id="rr-profile-save">Save Changes</button>
                </form>
                <div id="rr-profile-error" style="display:none"></div>
                <div id="rr-profile-message" style="display:none"></div>
            `;

            const deferred = $.Deferred();
            const postSpy = jest.spyOn($, 'post').mockReturnValue(deferred.promise());

            loadScript();

            $('#rr-profile-form').trigger('submit');

            expect(postSpy).toHaveBeenCalledTimes(1);
            const [url, data] = postSpy.mock.calls[0];
            expect(url).toBe('/ajax');
            expect(data.action).toBe('restart_update_profile');
            expect(data.display_name).toBe('Alex');
            expect(data.email).toBe('alex@example.com');
            expect(data.nonce).toBe('profile-nonce-val');

            deferred.resolve({ success: true, data: { message: 'Saved' } });
        });
    });
});
