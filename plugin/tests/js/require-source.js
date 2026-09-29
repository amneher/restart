'use strict';

const path = require('path');

// Loads a script under test, switching to its minified build when
// ASSET_BUILD=min is set (see `npm run test:min`). Running the same test
// suite against both builds is how we confirm minification didn't change
// behavior.
function requireScript(dir, relPath) {
    const suffix = process.env.ASSET_BUILD === 'min' ? '.min' : '';
    require(path.join(dir, relPath.replace(/\.js$/, `${suffix}.js`)));
}

module.exports = { requireScript };
