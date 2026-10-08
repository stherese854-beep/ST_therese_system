// ============================================================
//  ICONS  (js/icons.js)
// ============================================================
//  The pages were written with emojis (📅 Book, 🗑 Delete, ⚠️ ...).
//  This turns every emoji on the screen into a matching Bootstrap
//  Icon, so the whole system shows clean line icons instead.
//
//  It watches the page while it loads and whenever a script adds
//  or changes text, so pop-ups and buttons whose text changes get
//  icons too. Emails and SMS still use emojis (icon fonts cannot
//  show inside an email), and so do the words typed in text boxes.
//
//  Load it in <head> right after bootstrap-icons.min.css.
// ============================================================
(function () {
    // emoji -> Bootstrap Icon name (https://icons.getbootstrap.com)
    // "tooth" is our own icon (css/style.css), as Bootstrap has none.
    var MAP = {
        '🦷': 'tooth', '🗑': 'trash3', '⚠': 'exclamation-triangle-fill', '📅': 'calendar-event',
        '🗓': 'calendar3', '★': 'star-fill', '⭐': 'star-fill', '☆': 'star', '✓': 'check-lg', '✔': 'check-lg',
        '✅': 'check-circle-fill', '☐': 'square', '💾': 'floppy', '📧': 'envelope-at', '✉': 'envelope',
        '🖨': 'printer', '🩺': 'clipboard2-pulse', '📄': 'file-earmark-text', '🗄': 'archive',
        '📞': 'telephone', '🚫': 'slash-circle', '⛔': 'x-octagon-fill', '👤': 'person', '👥': 'people',
        '👪': 'people-fill', '👨': 'person', '👩': 'person', '👧': 'person', '💬': 'chat-dots',
        '📱': 'phone', '👁': 'eye', '🔍': 'search', '🔎': 'search', '✕': 'x-lg', '✗': 'x-lg',
        '❌': 'x-circle-fill', '⏳': 'hourglass-split', '✏': 'pencil', '✎': 'pencil', '🔁': 'arrow-repeat',
        '📋': 'clipboard', '⬆': 'upload', '⬇': 'download', '📣': 'megaphone', '➕': 'plus-lg',
        '➖': 'dash-lg', '📝': 'pencil-square', '🔒': 'lock', '🔐': 'shield-lock', '🔑': 'key',
        '📍': 'geo-alt', '💡': 'lightbulb', '📭': 'inbox', '🔠': 'fonts', '🕘': 'clock', '🕐': 'clock',
        '⏰': 'alarm', '📘': 'facebook', '🏥': 'hospital', '🧾': 'receipt', '🔀': 'arrow-left-right',
        '💊': 'capsule', '📌': 'pin-angle', '📤': 'send', '⚕': 'heart-pulse', '🎨': 'palette',
        '🖼': 'image', '🧪': 'bug', '🎉': 'stars', '📈': 'graph-up', '🗂': 'folder2-open',
        '📷': 'camera', '⚙': 'gear', '🛠': 'tools', '⏻': 'box-arrow-right', '📊': 'bar-chart',
        '🗒': 'sticky', '🎂': 'cake2', '🏠': 'house', '🔢': '123', '❓': 'question-circle',
        '❔': 'question-circle', '📜': 'journal-text', '🖥': 'display', '⏸': 'pause-fill',
        '🙏': 'chat-heart', '☰': 'list', '🌐': 'globe', '🔔': 'bell', '🪥': 'brush',
        '😟': 'emoji-frown', '🟢': 'circle-fill text-success', '🔴': 'circle-fill text-danger',
        '🟠': 'circle-fill text-warning', '🔵': 'circle-fill text-primary'
    };
    // One emoji, with its optional "colour" selector (FE0F) and any
    // joined parts (👨‍👩‍👧). Plain ★ ✓ ✕ are included so they match the rest.
    var RE = /(?:[\u{1F000}-\u{1FAFF}☀-➿⬀-⯿⌀-⏿])️?(?:‍[\u{1F000}-\u{1FAFF}☀-➿]️?)*/gu;
    var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, TITLE: 1, NOSCRIPT: 1, CANVAS: 1, svg: 1, CODE: 1, PRE: 1 };

    function iconFor(e) {
        var name = MAP[e] || MAP[e.replace(/[️‍].*$/u, '')] || MAP[String.fromCodePoint(e.codePointAt(0))];
        return name ? name.split(' ') : null;
    }
    function makeIcon(e) {
        var parts = iconFor(e); if (!parts) return null;
        var i = document.createElement('i');
        i.className = 'bi bi-' + parts[0] + (parts.length > 1 ? ' ' + parts.slice(1).join(' ') : '');
        i.setAttribute('aria-hidden', 'true');
        return i;
    }
    // Text that cannot hold an icon (drop-down choices, tooltips) just loses the emoji.
    function strip(s) { return s.replace(RE, function (e) { return /^[★☆✓]/.test(e) ? e : ''; }).replace(/^\s+/, '').replace(/ {2,}/g, ' '); }

    function convertText(node) {
        var t = node.nodeValue;
        if (!t || !RE.test(t)) return;
        RE.lastIndex = 0;
        var p = node.parentNode;
        if (!p || SKIP[p.nodeName] || (p.closest && p.closest('[contenteditable=""],[contenteditable="true"],[data-keep-emoji]'))) return;
        if (p.nodeName === 'OPTION' || p.nodeName === 'OPTGROUP') { node.nodeValue = strip(t); return; }
        var frag = document.createDocumentFragment(), last = 0, m, changed = false;
        while ((m = RE.exec(t))) {
            var ic = makeIcon(m[0]);
            if (!ic) continue;
            if (m.index > last) frag.appendChild(document.createTextNode(t.slice(last, m.index)));
            frag.appendChild(ic);
            last = m.index + m[0].length; changed = true;
        }
        RE.lastIndex = 0;
        if (!changed) return;
        if (last < t.length) frag.appendChild(document.createTextNode(t.slice(last)));
        p.replaceChild(frag, node);
    }
    var ATTRS = ['title', 'placeholder', 'aria-label', 'data-tip', 'alt'];
    function convertAttrs(el) {
        for (var k = 0; k < ATTRS.length; k++) {
            var v = el.getAttribute(ATTRS[k]);
            if (v && RE.test(v)) { RE.lastIndex = 0; el.setAttribute(ATTRS[k], strip(v)); }
            RE.lastIndex = 0;
        }
        if ((el.type === 'button' || el.type === 'submit') && el.tagName === 'INPUT' && RE.test(el.value)) el.value = strip(el.value);
        RE.lastIndex = 0;
    }
    function convert(root) {
        if (!root) return;
        if (root.nodeType === 3) { convertText(root); return; }
        if (root.nodeType !== 1 || SKIP[root.nodeName]) return;
        convertAttrs(root);
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT), n, todo = [];
        while ((n = w.nextNode())) {
            if (n.nodeType === 1) { if (n.hasAttribute('title') || n.hasAttribute('placeholder') || n.hasAttribute('data-tip') || n.tagName === 'INPUT') convertAttrs(n); }
            else todo.push(n);
        }
        todo.forEach(convertText);
    }
    window.emojiToIcons = convert;

    // Our own tooth icon (Bootstrap Icons has no tooth), drawn in the text colour.
    var css = document.createElement('style');
    css.textContent = '.bi-tooth::before{content:"";width:1em;height:1em;background:currentColor;' +
        '-webkit-mask:var(--tooth) center/contain no-repeat;mask:var(--tooth) center/contain no-repeat}' +
        ':root{--tooth:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27%3E' +
        '%3Cpath d=%27M7 2C4.2 2 2.5 4.3 2.5 7.2c0 2.3.9 3.9 1.6 5.5.8 1.8.9 3.5 1.2 5.6.3 2.1.9 3.7 2.2 3.7 1.5 0 1.8-2 2.2-4' +
        ' .3-1.6.8-2.8 2.3-2.8s2 1.2 2.3 2.8c.4 2 .7 4 2.2 4 1.3 0 1.9-1.6 2.2-3.7.3-2.1.4-3.8 1.2-5.6.7-1.6 1.6-3.2 1.6-5.5' +
        'C21.5 4.3 19.8 2 17 2c-2 0-3.2 1.2-5 1.2S9 2 7 2z%27/%3E%3C/svg%3E")}';
    document.head.appendChild(css);

    // Watch the page from the very start, so emojis are swapped before they are drawn.
    new MutationObserver(function (list) {
        for (var i = 0; i < list.length; i++) {
            var r = list[i];
            if (r.type === 'childList') r.addedNodes.forEach(convert);
            else if (r.type === 'characterData') convertText(r.target);
            else if (r.type === 'attributes') convertAttrs(r.target);
        }
    }).observe(document.documentElement, { childList: true, subtree: true, characterData: true,
                                            attributes: true, attributeFilter: ATTRS });
    // The page's own <title> and a last full sweep once it has loaded.
    function sweep() { document.title = strip(document.title); convert(document.body); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sweep); else sweep();

    // Browser pop-ups (confirm / alert / prompt) are plain text: drop the emojis there.
    ['alert', 'confirm', 'prompt'].forEach(function (f) {
        var orig = window[f];
        window[f] = function (msg) { var a = [].slice.call(arguments); if (typeof msg === 'string') a[0] = strip(msg); return orig.apply(window, a); };
    });
})();
