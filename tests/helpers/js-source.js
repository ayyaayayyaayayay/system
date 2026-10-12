'use strict';

// Function boundaries must include an async modifier after transport migrations.
function findSourceMarker(source, marker, offset = 0) {
    let found = source.indexOf(marker, offset);
    if (found < 0 && marker.includes('function ') && !marker.includes('async function ')) {
        found = source.indexOf(marker.replace('function ', 'async function '), offset);
    }
    if (found >= 6 && marker.startsWith('function ') && source.slice(found - 6, found) === 'async ') found -= 6;
    return found;
}

module.exports = { findSourceMarker };
