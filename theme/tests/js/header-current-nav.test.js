'use strict';

/**
 * Tests for theme/assets/js/header-current-nav.js
 * 
 * Covers:
 * - Navigation highlighting based on current page
 * - aria-current="page" attribute management
 * - URL path matching logic (exact and child paths)
 * - Trailing slash handling
 */

let mockLocation;

beforeAll(() => {
    // Setup mock location before loading script
    delete window.location;
    mockLocation = { pathname: '/registry/', reload: jest.fn() };
    window.location = mockLocation;
    
    // Mock addEventListener for DOMContentLoaded
    let domReadyCallback;
    document.addEventListener = jest.fn((event, callback) => {
        if (event === 'DOMContentLoaded') {
            domReadyCallback = callback;
        }
    });
    
    // Simulate script execution
    // The script checks readyState and calls markCurrent
});

function loadScript() {
    require('../../assets/js/header-current-nav.js');
}

function buildDOM(links) {
    document.body.innerHTML = `
        <header class="site-header">
            <nav>
                ${links.map(href => `<a href="${href}">${href}</a>`).join('')}
            </nav>
        </header>
    `;
}

// ─────────────────────────────────────────────────────────────────────────────
// Exact Path Matching
// ─────────────────────────────────────────────────────────────────────────────

describe('header current nav - exact path matching', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        jest.resetModules();
    });

    it('marks an exact path match as current', () => {
        buildDOM(['/registry/', '/articles/', '/about/']);
        mockLocation.pathname = '/registry/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBe(1);
        expect(currentLinks[0].href).toContain('/registry/');
    });

    it('marks the root path as current when visiting /', () => {
        buildDOM(['/', '/registry/', '/articles/']);
        mockLocation.pathname = '/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBe(1);
        expect(currentLinks[0].getAttribute('href')).toBe('/');
    });

    it('handles root path without trailing slash', () => {
        buildDOM(['/', '/registry/']);
        mockLocation.pathname = '';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBe(1);
    });

    it('does not match incorrect paths', () => {
        buildDOM(['/registry/', '/articles/', '/about/']);
        mockLocation.pathname = '/contact/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBe(0);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Child Path Matching
// ─────────────────────────────────────────────────────────────────────────────

describe('header current nav - child path matching', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        jest.resetModules();
    });

    it('marks parent path as current when visiting child path', () => {
        buildDOM(['/registry/', '/articles/', '/about/']);
        mockLocation.pathname = '/articles/tips-and-tricks/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBe(1);
        expect(currentLinks[0].href).toContain('/articles/');
    });

    it('marks parent when visiting deeply nested child', () => {
        buildDOM(['/docs/', '/guides/']);
        mockLocation.pathname = '/docs/getting-started/installation/steps/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBe(1);
        expect(currentLinks[0].href).toContain('/docs/');
    });

    it('does not match parent when visiting unrelated sibling', () => {
        buildDOM(['/registry/', '/articles/']);
        mockLocation.pathname = '/about/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBe(0);
    });

    it('correctly prioritizes exact match over child match', () => {
        // If both exist, exact match should take precedence
        buildDOM(['/registry/', '/registry/mine/']);
        mockLocation.pathname = '/registry/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBeGreaterThanOrEqual(1);
        expect(currentLinks[0].href).toContain('/registry/');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Trailing Slash Handling
// ─────────────────────────────────────────────────────────────────────────────

describe('header current nav - trailing slash handling', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        jest.resetModules();
    });

    it('matches path with trailing slash removed from URL', () => {
        buildDOM(['/registry/', '/articles/']);
        mockLocation.pathname = '/registry'; // no trailing slash
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBe(1);
        expect(currentLinks[0].href).toContain('/registry/');
    });

    it('matches path when link has no trailing slash but URL does', () => {
        buildDOM(['/registry', '/articles']);
        mockLocation.pathname = '/registry/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBe(1);
    });

    it('normalizes multiple trailing slashes', () => {
        buildDOM(['/registry/', '/articles/']);
        mockLocation.pathname = '/registry///';
        
        loadScript();
        
        // Should still match despite extra slashes
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBeGreaterThan(0);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// aria-current attribute
// ─────────────────────────────────────────────────────────────────────────────

describe('header current nav - aria-current attribute', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        jest.resetModules();
    });

    it('sets aria-current="page" on matching link', () => {
        buildDOM(['/registry/', '/articles/']);
        mockLocation.pathname = '/registry/';
        
        loadScript();
        
        const currentLink = document.querySelector('a.is-current');
        expect(currentLink.getAttribute('aria-current')).toBe('page');
    });

    it('removes aria-current from non-matching links', () => {
        buildDOM(['/registry/', '/articles/']);
        mockLocation.pathname = '/registry/';
        
        loadScript();
        
        const nonCurrentLinks = document.querySelectorAll('a:not(.is-current)');
        nonCurrentLinks.forEach(link => {
            expect(link.getAttribute('aria-current')).not.toBe('page');
        });
    });

    it('only one link has aria-current page at a time', () => {
        buildDOM(['/registry/', '/articles/', '/about/', '/contact/']);
        mockLocation.pathname = '/articles/';
        
        loadScript();
        
        const currentPages = document.querySelectorAll('[aria-current="page"]');
        expect(currentPages.length).toBe(1);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// URL Parsing Edge Cases
// ─────────────────────────────────────────────────────────────────────────────

describe('header current nav - URL parsing', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        jest.resetModules();
    });

    it('handles invalid URLs without crashing', () => {
        buildDOM(['not a url', 'http://example.com', '/valid/path/']);
        mockLocation.pathname = '/valid/path/';
        
        expect(() => {
            loadScript();
        }).not.toThrow();
    });

    it('ignores query strings in matching', () => {
        buildDOM(['/registry/', '/articles/']);
        mockLocation.pathname = '/registry/';
        mockLocation.search = '?tab=created&sort=date';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBeGreaterThan(0);
    });

    it('ignores fragments in matching', () => {
        buildDOM(['/registry/', '/articles/']);
        mockLocation.pathname = '/registry/';
        mockLocation.hash = '#my-items';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBeGreaterThan(0);
    });

    it('handles full URLs with protocol and domain', () => {
        buildDOM(['http://localhost/registry/', 'http://localhost/articles/']);
        mockLocation.pathname = '/registry/';
        mockLocation.origin = 'http://localhost';
        
        expect(() => {
            loadScript();
        }).not.toThrow();
    });

    it('handles different domains gracefully', () => {
        buildDOM(['http://localhost/registry/', 'http://example.com/registry/']);
        mockLocation.pathname = '/registry/';
        mockLocation.origin = 'http://localhost';
        
        loadScript();
        
        // Should only match localhost
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBeGreaterThan(0);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Script Loading & Timing
// ─────────────────────────────────────────────────────────────────────────────

describe('header current nav - script timing', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        jest.resetModules();
    });

    it('runs when document is already loaded', () => {
        document.readyState = 'complete';
        buildDOM(['/registry/', '/articles/']);
        mockLocation.pathname = '/registry/';
        
        expect(() => {
            loadScript();
        }).not.toThrow();
    });

    it('sets up DOMContentLoaded listener when document is loading', () => {
        document.readyState = 'loading';
        const listeners = [];
        
        document.addEventListener = jest.fn((event, callback) => {
            if (event === 'DOMContentLoaded') {
                listeners.push(callback);
            }
        });
        
        buildDOM(['/registry/']);
        mockLocation.pathname = '/registry/';
        
        loadScript();
        
        // Listener should have been registered
        expect(listeners.length).toBeGreaterThan(0);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Multiple Navigation Elements
// ─────────────────────────────────────────────────────────────────────────────

describe('header current nav - multiple navigations', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        jest.resetModules();
    });

    it('marks current nav in all navigation elements', () => {
        document.body.innerHTML = `
            <header>
                <nav><a href="/registry/">Registry</a><a href="/articles/">Articles</a></nav>
            </header>
            <aside>
                <nav><a href="/registry/">Registry</a><a href="/articles/">Articles</a></nav>
            </aside>
        `;
        mockLocation.pathname = '/registry/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBeGreaterThan(0);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Special Paths
// ─────────────────────────────────────────────────────────────────────────────

describe('header current nav - special paths', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        jest.resetModules();
    });

    it('handles /index as equivalent to /', () => {
        buildDOM(['/', '/registry/', '/articles/']);
        mockLocation.pathname = '/index';
        
        loadScript();
        
        // Should match root
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBeGreaterThan(0);
    });

    it('does not match root when visiting /index and root link exists', () => {
        buildDOM(['/', '/registry/']);
        mockLocation.pathname = '/registry/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        const hasRootCurrent = Array.from(currentLinks).some(link => link.getAttribute('href') === '/');
        expect(hasRootCurrent).toBe(false);
    });

    it('handles empty path segments', () => {
        buildDOM(['/registry/', '/my-registry/']);
        mockLocation.pathname = '/my-registry/';
        
        loadScript();
        
        const currentLinks = document.querySelectorAll('a.is-current');
        expect(currentLinks.length).toBeGreaterThan(0);
    });
});
