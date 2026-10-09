/* Shared marker rendering colors; all CSS input is limited to six-digit hex. */
(function (root) {
  const hex = (value, fallback) => typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value) ? value.toLowerCase() : fallback;
  function spot(meta, item) {
    const custom = hex(item.markerColor, null);
    if (custom) return custom;
    const category = hex((meta.categoryColors || {})[item.cat || ''], null);
    if (category) return category;
    if (!item.cat || item.cat === 'new') return '#7a7f87';
    return hex((meta.categoryColors || {})[item.cat], hex(item.color, '#7a7f87'));
  }
  function badge(meta, person) {
    return hex(person, hex(meta.badgeColor, '#c0392b'));
  }
  const colors = { hex, spot, badge };
  if (typeof module === 'object' && module.exports) module.exports = colors;
  else root.SouliongMarkerColors = colors;
})(typeof globalThis !== 'undefined' ? globalThis : this);
