(function () {
  'use strict';

  function insertAfter(newNode, referenceNode) {
    const parent = referenceNode.parentNode;
    if (parent.lastChild === referenceNode) {
      parent.appendChild(newNode);
    } else {
      parent.insertBefore(newNode, referenceNode.nextSibling);
    }
  }

  function getElementByXPath(xPath, doc = document) {
    if (doc.evaluate) {
      return doc.evaluate(
        xPath,
        doc,
        null,
        9,
        null
      ).singleNodeValue;
    }

    xPath = xPath.replace(/^\/+/, '');
    const steps = xPath.split('/');
    let current = doc;

    for (let i = 0, l = steps.length; i < l && current; i += 1) {
      const match = /([^\[\]]+)(?:\[(\d+)\])?/.exec(steps[i]);
      if (!match) return null;
      const [, tag, idx] = match;
      const pos = idx ? (idx - 1) : 0;
      current = current.getElementsByTagName(tag)[pos] || null;
    }
    return current;
  }

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

  function injectWidgetByXpath(xpath) {
    const anchor =
      getElementByXPath(xpath) || document.getElementById('tbdefault');
    if (anchor) innerInject(anchor);
  }

  function injectWidgetByMarker(markerId) {
    const markerNode = document.getElementById(markerId);
    if (markerNode && markerNode.parentNode) innerInject(markerNode.parentNode);
  }

  function innerInject(node) {
    if (!node) return;

    const fragment = document.createDocumentFragment();
    const container = document.createElement('span');
    const script = document.createElement('script');

    container.insertAdjacentHTML('beforeend', '{{HTML}}');
    script.text = "{{SCRIPT}}";

    fragment.appendChild(container);
    fragment.appendChild(script);
    insertAfter(fragment, node);
  }

  window.insertAfter = insertAfter;
  window.getElementByXPath = getElementByXPath;
  window.injectWidgetByXpath = injectWidgetByXpath;
  window.injectWidgetByMarker = injectWidgetByMarker;
  window.innerInject = innerInject;
}());
