/* วาดเกียรติบัตรบน <canvas> แล้วบันทึกเป็น PDF (ทำงานในเบราว์เซอร์ ไม่ต้องต่ออินเทอร์เน็ต) */
window.CQCert = (function () {
  var FONT = 'CQSarabun';

  function loadImage(src) {
    return new Promise(function (ok, fail) {
      var img = new Image();
      img.onload = function () { ok(img); };
      img.onerror = function () { fail(new Error('โหลดภาพพื้นหลังไม่ได้: ' + src)); };
      img.src = src;
    });
  }

  function loadFonts() {
    if (!document.fonts || !document.fonts.load) return Promise.resolve();
    return Promise.all([
      document.fonts.load('700 48px ' + FONT, 'กขค ABC 123'),
      document.fonts.load('400 48px ' + FONT, 'กขค ABC 123')
    ]).catch(function () {});
  }

  function prepare(cfg) {
    return Promise.all([loadImage(cfg.bg), loadFonts()]).then(function (r) { return r[0]; });
  }

  function draw(canvas, img, cfg, name, dateText, maxW, extra) {
    var sc = (maxW && img.naturalWidth > maxW) ? maxW / img.naturalWidth : 1;   // ย่อขนาดได้เมื่อสร้างหลายใบ
    var W = Math.round(img.naturalWidth * sc), H = Math.round(img.naturalHeight * sc);
    canvas.width = W; canvas.height = H;
    var c = canvas.getContext('2d');
    c.drawImage(img, 0, 0, W, H);
    function put(text, x, y, size, color, weight, align) {
      if (!text) return;
      var fs = size / 100 * H;
      var setFont = function () { c.font = weight + ' ' + fs + 'px ' + FONT + ', "TH Sarabun New", Tahoma, sans-serif'; };
      setFont();
      while (c.measureText(text).width > W * 0.84 && fs > 8) { fs *= 0.95; setFont(); } // ชื่อยาวให้ย่อพอดีกรอบ
      c.fillStyle = color || '#1f3864';
      c.textAlign = align || 'center';
      c.textBaseline = 'alphabetic';
      c.fillText(text, x / 100 * W, y / 100 * H);
    }
    put(name, cfg.name_x, cfg.name_y, cfg.name_size, cfg.name_color, 700);
    put((cfg.date_prefix || '') + dateText, cfg.date_x, cfg.date_y, cfg.date_size, cfg.date_color, 400);
    if (cfg.course_title) {
      put(cfg.course_title, cfg.course_x || 50, cfg.course_y || 70.1, cfg.course_size || 3.5, cfg.course_color || '#1f3864', 700);
    }
    if (cfg.org_name) {
      put(cfg.org_name, cfg.org_x || 50, cfg.org_y || 83.5, cfg.org_size || 2.9, cfg.org_color || '#1f3864', 700);
    }
    var ex = extra || cfg;
    if (ex.cert_code) {
      put('เลขที่เกียรติบัตร: ' + ex.cert_code, 7.2, 85.8, 1.35, '#475569', 600, 'left');
      put('ออกโดยระบบอิเล็กทรอนิกส์ (Electronic Certificate)', 7.2, 87.8, 1.15, '#64748b', 400, 'left');
    }
    if (ex.verify_url && typeof window.qrcode === 'function') {
      try {
        var qr = window.qrcode(0, 'M');
        qr.addData(ex.verify_url);
        qr.make();
        var n = qr.getModuleCount();
        var qSize = Math.round(H * 0.082);
        var pad = Math.max(4, Math.round(qSize * 0.06));
        var box = qSize + pad * 2;
        var bx = Math.round(W * 0.89 - box / 2);
        var by = Math.round(H * 0.768);
        c.fillStyle = '#ffffff';
        c.fillRect(bx, by, box, box);
        c.strokeStyle = '#cbd5e1';
        c.lineWidth = Math.max(1, Math.round(H * 0.0008));
        c.strokeRect(bx, by, box, box);
        var cell = qSize / n;
        c.fillStyle = '#1f3864';
        for (var r = 0; r < n; r++) {
          for (var col = 0; col < n; col++) {
            if (qr.isDark(r, col)) {
              c.fillRect(bx + pad + col * cell, by + pad + r * cell, Math.ceil(cell), Math.ceil(cell));
            }
          }
        }
        put('สแกนตรวจสอบ', 89.0, 87.8, 1.15, '#475569', 600, 'center');
      } catch (e) {}
    }
  }

  function pageSize(canvas) {
    var W = canvas.width, H = canvas.height, land = W >= H;
    var pw = land ? 297 : 210;                              // A4 (หรือสัดส่วนตามภาพพื้นหลัง)
    return { land: land, w: pw, h: pw * H / W };
  }
  function newDoc(canvas) {
    var ps = pageSize(canvas);
    return new window.jspdf.jsPDF({ orientation: ps.land ? 'landscape' : 'portrait', unit: 'mm', format: [ps.w, ps.h], compress: true });
  }
  /** วางภาพจาก canvas ลงเอกสาร (หน้าแรกใช้หน้าที่มีอยู่ หน้าถัดไปเพิ่มหน้าใหม่) */
  function addCanvas(doc, canvas, isFirst, quality) {
    var ps = pageSize(canvas);
    if (!isFirst) doc.addPage([ps.w, ps.h], ps.land ? 'landscape' : 'portrait');
    doc.addImage(canvas.toDataURL('image/jpeg', quality || 0.92), 'JPEG', 0, 0, ps.w, ps.h, undefined, 'FAST');
  }
  function savePdf(canvas, filename) {
    var doc = newDoc(canvas);
    addCanvas(doc, canvas, true, 0.92);
    doc.save(filename);
  }

  return { prepare: prepare, draw: draw, savePdf: savePdf, newDoc: newDoc, addCanvas: addCanvas };
})();
