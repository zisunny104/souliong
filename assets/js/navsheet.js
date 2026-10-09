/* 導航選單小頁（api/navsheet.php）：平台過濾、收起（關閉鈕／點背景／Esc／下滑）、通知父頁。
   postMessage 目標 origin 只取自頁面注入的嵌入允許清單，逐一發送，不用 "*"。 */
(function () {
  'use strict';
  var body = document.body;
  var sheet = document.getElementById('nsheet');
  var IS_IOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  var iosItems = document.querySelectorAll('[data-nsheet-platform="ios"]');
  for (var i = 0; i < iosItems.length; i++) if (IS_IOS) iosItems[i].hidden = false;

  var origins = [];
  try { origins = JSON.parse(body.getAttribute('data-nsheet-origins') || '[]'); } catch (e) { origins = []; }

  var closed = false;
  function notifyParent() {
    if (window.parent === window) return;
    var msg = { v: 1, ns: 'souliong', type: 'navsheet-close' };
    origins.forEach(function (o) {
      try { window.parent.postMessage(msg, o); } catch (e) { /* 目標不符時瀏覽器自行丟棄 */ }
    });
  }
  function close() {
    if (closed) return;
    closed = true;
    body.classList.remove('nsheet-in');
    sheet.style.removeProperty('--nsheet-drag');
    setTimeout(notifyParent, 200);
  }

  document.getElementById('nsheetClose').addEventListener('click', close);
  document.getElementById('nsheetBackdrop').addEventListener('click', close);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });

  // 下滑收起：在抓握條或標題列拖曳，超過 80px 或快速下甩就收起，否則彈回
  var drag = null;
  function onDown(ev) {
    if (body.classList.contains('nsheet-embed') && window.matchMedia('(max-height: 520px)').matches) return;
    if (ev.target.closest('.nsheet-close, .nsheet-item')) return;
    if (!ev.target.closest('.nsheet-grab, .nsheet-head') && sheet.scrollTop > 0) return;
    drag = { y: ev.clientY, t: Date.now(), dy: 0, id: ev.pointerId };
  }
  function onMove(ev) {
    if (!drag || ev.pointerId !== drag.id) return;
    drag.dy = Math.max(0, ev.clientY - drag.y);
    if (drag.dy > 4) {
      sheet.classList.add('nsheet-dragging');
      sheet.style.setProperty('--nsheet-drag', drag.dy + 'px');
    }
  }
  function onUp(ev) {
    if (!drag || ev.pointerId !== drag.id) return;
    var d = drag; drag = null;
    sheet.classList.remove('nsheet-dragging');
    var fast = d.dy / Math.max(1, Date.now() - d.t) > 0.5;
    if (d.dy > 80 || (fast && d.dy > 20)) close();
    else sheet.style.removeProperty('--nsheet-drag');
  }
  sheet.addEventListener('pointerdown', onDown);
  window.addEventListener('pointermove', onMove);
  window.addEventListener('pointerup', onUp);
  window.addEventListener('pointercancel', onUp);

  requestAnimationFrame(function () { requestAnimationFrame(function () { body.classList.add('nsheet-in'); }); });
  var first = document.querySelector('.nsheet-item:not([hidden])');
  if (first) first.focus({ preventScroll: true });
})();
