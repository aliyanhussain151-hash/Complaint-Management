/* screen-fit.js – picks a comfortable overall size for the visitor's SCREEN.
 *
 * Why: the pages are designed for a ~1920px-wide monitor. On an older laptop
 * (1366x768, or 1920x1080 with Windows scaling at 125-150%) everything looks
 * oversized and people end up zooming Chrome out to ~67% just to see the page.
 *
 * What it does: on laptop-sized desktop screens it shrinks the whole page a
 * little (CSS `zoom` on <html>) so the page looks the way it would at ~70-100%
 * on a big monitor. The factor is based on screen.width, which Chrome keeps
 * constant while the user presses Ctrl +/-, so normal browser zoom still works
 * on top of it (zoom in -> bigger, zoom out -> smaller, layout reflows).
 *
 *   screen width 1920px or more -> 1.00 (unchanged)
 *   1600px -> 0.83     1536px -> 0.80     1440px -> 0.75     1366px -> 0.71
 *   anything narrower is limited to MIN_SCALE; phones/tablets/touch-only
 *   devices and screens under MIN_SCREEN are never touched.
 *
 * Override for one visitor: open any page with ?fit=off (remembered) or ?fit=on.
 * Tune for everyone: change the three constants below.
 */
(function () {
    'use strict';

    var DESIGN_WIDTH = 1920;  // screen width the pages look "right" at 100%
    var MIN_SCALE    = 0.70;  // never shrink more than this
    var MIN_SCREEN   = 1100;  // below this (tablets, phones) do nothing

    var root = document.documentElement;

    try {
        // ?fit=off / ?fit=on  -> remember the visitor's choice
        var m = /[?&]fit=(on|off)\b/.exec(location.search);
        if (m) { localStorage.setItem('usp-fit', m[1]); }
        if (localStorage.getItem('usp-fit') === 'off') { return; }
    } catch (e) { /* storage blocked - carry on with the default */ }

    // CSS zoom is supported by Chrome, Edge, Safari and Firefox 126+; elsewhere leave the page alone
    if (!window.CSS || !CSS.supports || !CSS.supports('zoom', '1')) { return; }

    // Only mouse/trackpad desktops and laptops
    if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) { return; }

    var screenWidth = Math.max(screen.width || 0, screen.height || 0);
    if (screenWidth < MIN_SCREEN || screenWidth >= DESIGN_WIDTH) { return; }

    var scale = Math.max(MIN_SCALE, Math.round((screenWidth / DESIGN_WIDTH) * 100) / 100);

    root.style.zoom = String(scale);
    root.style.setProperty('--fit', String(scale));
    root.setAttribute('data-fit', String(scale));
})();
