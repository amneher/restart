/** @type {import('jest').Config} */
module.exports = {
    testEnvironment: 'jest-environment-jsdom',
    testMatch: ['**/tests/js/**/*.test.js'],
    // jQuery doesn't export as a CommonJS module cleanly; transform is not needed
    // but we do need to tell Jest where to look for test files.
    globals: {},
};
