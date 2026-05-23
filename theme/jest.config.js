/** @type {import('jest').Config} */
module.exports = {
    testEnvironment: 'jsdom',
    testMatch: ['**/tests/js/**/*.test.js'],
    setupFiles: ['./tests/js/setup.js'],
    collectCoverageFrom: [
        'assets/js/**/*.js',
    ],
    coverageReporters: ['text', 'lcov', 'cobertura'],
};
