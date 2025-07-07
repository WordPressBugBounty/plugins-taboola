/*  ────────────────────────────────────────────────────────────────────────────
    Utility helpers
    ────────────────────────────────────────────────────────────────────────── */

(function () {
  'use strict';

  /* ----------  insertAfter  ------------------------------------------------ */
  /* Fast path for “append” avoids creating a reference to nextSibling.       */
  function insertAfter(newNode, referenceNode) {
    const parent = referenceNode.parentNode;
    if (parent.lastChild === referenceNode) {
      parent.appendChild(newNode);
    } else {
      parent.insertBefore(newNode, referenceNode.nextSibling);
    }
  }

  /* ----------  getElementByXPath  ----------------------------------------- */
  /* 1) Use native evaluate where available (all evergreen browsers).         */
  /* 2) Small, allocation-free fallback for very old IE engines.              */
  function getElementByXPath(xPath, doc = document) {
    if (doc.evaluate) {
      // XPathResult.FIRST_ORDERED_NODE_TYPE = 9
      return doc.evaluate(
        xPath,
        doc,
        null,
        9,
        null
      ).singleNodeValue;
    }

    // Fallback (legacy IE) – minimal parsing, no .filter / regex loops
    xPath = xPath.replace(/^\/+/, ''); // strip leading “/”
    const steps = xPath.split('/');
    let current = doc;

    for (let i = 0, l = steps.length; i < l && current; i += 1) {
      const match = /([^\[\]]+)(?:\[(\d+)\])?/.exec(steps[i]);
      if (!match) return null;
      const [, tag, idx] = match;
      const pos = idx ? (idx - 1) : 0; // XPath indices are 1-based
      current = current.getElementsByTagName(tag)[pos] || null;
    }
    return current;
  }

  /* ----------  Array.prototype.filter polyfill (unchanged API) ------------ */
  /* MIT – kept for <= IE8; tightened slightly for speed/readability.         */
  /* eslint-disable-next-line no-extend-native */
  if (!Array.prototype.filter) {
    Array.prototype.filter = function (callback, thisArg) {
      if (typeof callback !== 'function') throw new TypeError();
      const out = [];
      for (let i = 0, l = this.length >>> 0; i < l; i += 1) {
        if (i in this) {
          const val = this[i];
          if (callback.call(thisArg, val, i, this)) out.push(val);
        }
      }
      return out;
    };
  }

  /* ----------  Injection helpers  ----------------------------------------- */

  function injectWidgetByXpath(xpath) {
    const anchor =
      getElementByXPath(xpath) || document.getElementById('tbdefault');
    if (anchor) innerInject(anchor);
  }

  function injectWidgetByMarker(markerId) {
    const markerNode = document.getElementById(markerId);
    if (markerNode && markerNode.parentNode) innerInject(markerNode.parentNode);
  }

  /* One reflow: build everything in a fragment, then insert once.           */
  function innerInject(node) {
    if (!node) return;

    const fragment = document.createDocumentFragment();
    const container = document.createElement('span');
    const script = document.createElement('script');

    /* Keep single quotes – some minifiers mangle this otherwise.            */
    container.insertAdjacentHTML('beforeend', '{{HTML}}');
    script.text = "{{SCRIPT}}";

    fragment.appendChild(container);
    fragment.appendChild(script);
    insertAfter(fragment, node);
  }

  /* ----------  Export to global scope (names unchanged) ------------------- */
  window.insertAfter = insertAfter;
  window.getElementByXPath = getElementByXPath;
  window.injectWidgetByXpath = injectWidgetByXpath;
  window.injectWidgetByMarker = injectWidgetByMarker;
  window.innerInject = innerInject;
}());
