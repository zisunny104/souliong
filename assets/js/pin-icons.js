(function (root) {
  function resolve(catalog, name) {
    return typeof name === 'string' && Object.prototype.hasOwnProperty.call(catalog, name) ? name : 'location-dot';
  }
  function svg(catalog, name) {
    const icon = catalog[resolve(catalog, name)];
    if (!icon || !Number.isInteger(icon.width) || !Number.isInteger(icon.height) || !Array.isArray(icon.paths)) return '';
    const paths = icon.paths.filter(path => typeof path === 'string' && /^[MmLlHhVvCcSsQqTtAaZzEe0-9., +\-]*$/.test(path));
    return '<svg viewBox="0 0 ' + icon.width + ' ' + icon.height + '" fill="currentColor" aria-hidden="true">' + paths.map(path => '<path d="' + path + '"/>').join('') + '</svg>';
  }
  const api = { resolve, svg };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.SouliongPinIcons = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
