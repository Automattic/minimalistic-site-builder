'use strict';

const { JSDOM, VirtualConsole } = require('jsdom');

let installedDom = null;

/**
 * Install the browser globals required by the registered Gutenberg runtime.
 *
 * The first installation wins for the life of the process. Oracle tools use a
 * quiet virtual console so stdout remains machine-readable; the production
 * Node CLI retains JSDOM's normal error forwarding.
 */
function installDomEnvironment({ forwardJsdomErrors = true } = {}) {
  if (installedDom) return installedDom;

  const options = {
    url: 'http://localhost',
    pretendToBeVisual: true,
  };
  if (!forwardJsdomErrors) {
    options.virtualConsole = new VirtualConsole();
  }

  const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>', options);
  global.window = dom.window;
  global.document = dom.window.document;
  global.DOMParser = dom.window.DOMParser;
  global.XMLSerializer = dom.window.XMLSerializer;
  global.Node = dom.window.Node;
  global.Element = dom.window.Element;
  global.HTMLElement = dom.window.HTMLElement;
  global.getComputedStyle = dom.window.getComputedStyle;
  global.MutationObserver = dom.window.MutationObserver;
  global.requestAnimationFrame = (callback) => setTimeout(callback, 16);
  global.cancelAnimationFrame = (id) => clearTimeout(id);
  global.matchMedia = () => ({
    matches: false,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
  });
  global.ResizeObserver = class ResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
  };

  Object.defineProperty(global, 'navigator', {
    value: dom.window.navigator,
    writable: true,
    configurable: true,
  });

  installedDom = dom;
  return dom;
}

module.exports = {
  installDomEnvironment,
};
