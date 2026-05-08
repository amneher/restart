/** @type {import('jest').Config} */
module.exports = {
    testEnvironment: 'jsdom',
    testMatch: ['**/tests/js/**/*.test.js'],
    collectCoverageFrom: [
        'assets/js/**/*.js',
    ],
    coverageReporters: ['text', 'lcov', 'cobertura'],
};
