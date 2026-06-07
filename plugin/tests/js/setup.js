'use strict';

// jsdom 26 doesn't implement browser navigation. When test setup code does
// `delete window.location; window.location = {...}` to install a mock,
// jsdom's Location setter fires, internally calls navigateFetch, and emits a
// console.error of type 'not implemented'. The tests still pass — the mock is
// installed and window.location.reload/href are usable — but the stderr noise
// is confusing. Filter it out here, before any test file runs.
const _originalConsoleError = console.error.bind(console);
console.error = function (...args) {
    const first = args[0];
    if (first && typeof first === 'object' && first.type === 'not implemented') { return;
    }
    _originalConsoleError(...args);
};
