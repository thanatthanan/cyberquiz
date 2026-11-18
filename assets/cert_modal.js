/* ป๊อปอัป (modal) ดูเกียรติบัตรสำหรับหน้าผู้ดูแล
 * ใช้กับปุ่ม: <button data-cert-name="..." data-cert-date="...">ดู</button>
 * ต้องกำหนด window.CQ_CERT_CFG (ค่าจาก cert_js_config) และโหลด jspdf + cert.js ก่อน */
(function () {
  var modal, cv, st, title, dl, current = null, imgPromise = null;

  function build() {
    modal = document.createElement('div');
    modal.className = 'cqmodal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.hidden = true;
    modal.innerHTML =
      '<div class="cqmodal-box">' +
      '  <div class="cqmodal-head"><b class="cqmodal-title">เกียรติบัตร</b>' +
      '    <button type="button" class="cqmodal-x" aria-label="ปิด">×</button></div>' +
      '  <canvas></canvas>' +
      '  <p class="muted cqmodal-status"></p>' +
      '  <div class="cqmodal-foot"><button type="button" class="btn primary cqmodal-dl" disabled>ดาวน์โหลด PDF</button>' +
      '    <button type="button" class="btn cqmodal-close">ปิด</button></div>' +
      '</div>';
    document.body.appendChild(modal);
    cv = modal.querySelector('canvas');
    st = modal.querySelector('.cqmodal-status');
    title = modal.querySelector('.cqmodal-title');
    dl = modal.querySelector('.cqmodal-dl');
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    modal.querySelector('.cqmodal-x').onclick = close;
    modal.querySelector('.cqmodal-close').onclick = close;
    dl.onclick = function () { if (current) CQCert.savePdf(cv, current.file); };
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
  }

  function safe(s) { return String(s).replace(/[\\\/:*?"<>|\s]+/g, '_'); }

  function open(name, date, code, verifyUrl) {
    if (!modal) build();
    var cfg = window.CQ_CERT_CFG;
    current = { name: name, date: date, file: 'เกียรติบัตร_' + safe(name) + '.pdf' };
    title.textContent = 'เกียรติบัตร — ' + name;
    st.textContent = 'กำลังสร้างเกียรติบัตร…';
    dl.disabled = true;
    cv.getContext('2d').clearRect(0, 0, cv.width, cv.height);
    modal.hidden = false;
    document.body.classList.add('cqmodal-open');
    if (!imgPromise) imgPromise = CQCert.prepare(cfg);
    var mine = current;
    imgPromise.then(function (img) {
      if (current !== mine) return;
      var extra = code ? { cert_code: code, verify_url: verifyUrl || '' } : null;
      CQCert.draw(cv, img, cfg, name, date, null, extra);
      st.textContent = 'ให้ไว้ ณ วันที่ ' + date + (code ? ' · เลขที่ ' + code : '');
      dl.disabled = false;
      dl.focus();
    }).catch(function (e) { st.textContent = e.message; imgPromise = null; });
  }

  function close() {
    modal.hidden = true;
    current = null;
    document.body.classList.remove('cqmodal-open');
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-cert-name]') : null;
    if (!b) return;
    e.preventDefault();
    open(b.getAttribute('data-cert-name'), b.getAttribute('data-cert-date'), b.getAttribute('data-cert-code') || '', b.getAttribute('data-cert-verify') || '');
  });

  window.CQCertModal = { open: open, close: close };
})();
