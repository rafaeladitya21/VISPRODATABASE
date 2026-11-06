/* =========================================================
   Tampilan Invoice & Perhitungan Awal untuk satu transaksi,
   plus cetak PDF (window.print + CSS print).
   ========================================================= */
(function () {
  'use strict';
  var esc = MH.esc, rp = MH.rupiah;
  var TRANSPORT = (window.MH_DATA && MH_DATA.transport) || {};
  var TIER_LABEL = { normal: 'Normal', d30: 'Diskon 30%', d50: 'Diskon 50%', d70: 'Diskon 70%' };

  function eventName(tx) { return tx.event_name || 'Event belum diberi nama'; }

  /** Badge status transaksi & termin */
  MH.statusBadges = function (tx) {
    var solid = tx.status === 'Confirmed' || tx.status === 'Selesai';
    var lunas = tx.termin_status === 'Lunas';
    var termin = tx.termin_status === 'Termin Berjalan';   // masih ada tagihan berjalan → merah
    return '<span class="badge' + (solid ? ' badge--solid' : '') + '">' + esc(tx.status) + '</span>' +
           '<span class="badge' + (lunas ? ' badge--solid' : termin ? ' badge--danger' : '') + '">' + esc(tx.termin_status) + '</span>';
  };

  /** Tab "Invoice" */
  MH.invoiceHtml = function (tx, customer) {
    var c = customer || tx.customer || {};
    return '' +
      '<article class="invoice">' +
        '<header class="invoice__head">' +
          '<div><p class="invoice__brand">Visual Production.id</p></div>' +
          '<div class="invoice__no"><p class="invoice__label">INVOICE</p><p class="invoice__num num">' + esc(tx.invoice_no) + '</p></div>' +
        '</header>' +
        '<div class="invoice__parties">' +
          '<div><p class="invoice__label">Ditagihkan kepada</p>' +
            '<p class="invoice__strong">' + esc(c.name || '—') + '</p>' +
            '<p class="invoice__muted">' + esc(c.vendor || '—') + '</p></div>' +
          '<div><p class="invoice__label">Event</p>' +
            '<p class="invoice__strong">' + esc(eventName(tx)) + '</p>' +
            '<p class="invoice__muted">' + esc(MH.date(tx.event_date)) + (tx.location ? ' · ' + esc(tx.location) : '') + '</p></div>' +
        '</div>' +
        '<table class="invoice__table">' +
          '<thead><tr><th>Deskripsi</th><th class="r">Jumlah</th></tr></thead>' +
          '<tbody><tr><td>Jasa produksi visual — ' + esc(eventName(tx)) + '</td><td class="r">' + rp(tx.revenue) + '</td></tr></tbody>' +
        '</table>' +
        '<dl class="invoice__sum">' +
          '<div><dt>Subtotal</dt><dd class="num">' + rp(tx.revenue) + '</dd></div>' +
          '<div><dt>DP / Uang masuk' + (MH.payLabel(tx.payment_method) ? ' (' + esc(MH.payLabel(tx.payment_method)) + ')' : '') + '</dt><dd class="num">− ' + rp(tx.dp) + '</dd></div>' +
          '<div class="invoice__due"><dt>Sisa Tagihan</dt><dd class="num">' + rp(tx.totals.sisa) + '</dd></div>' +
        '</dl>' +
      '</article>';
  };

  function section(title, rows, total) {
    var body = rows.length
      ? rows.map(function (r) {
          return '<div class="calc__row"><span>' + r[0] + '</span><span class="num">' + rp(r[1]) + '</span></div>';
        }).join('') +
        '<div class="calc__row calc__row--total"><span>Total</span><span class="num">' + rp(total) + '</span></div>'
      : '<p class="calc__empty">Belum ada rincian.</p>';
    return '<section class="calc__sec"><h4 class="calc__title">' + title + '</h4>' + body + '</section>';
  }

  /** Tab "Perhitungan Awal" */
  MH.calcHtml = function (tx) {
    var tRows = Object.keys(TRANSPORT).filter(function (k) { return tx.transport[k] > 0; })
      .map(function (k) { return [esc(TRANSPORT[k]), tx.transport[k]]; });
    var cRows = tx.crew.map(function (c) {
      return [esc(c.name || 'Tanpa nama') + (c.role ? ' <small>· ' + esc(c.role) + '</small>' : ''), c.honor];
    });
    var eRows = tx.equipment.map(function (e) {
      return [esc(e.equipment_name || '—') + ' <small>× ' + e.qty + ' × ' + e.days + ' hari' +
              (e.tier !== 'normal' ? ' · ' + TIER_LABEL[e.tier] : '') + '</small>', e.total];
    });
    var t = tx.totals;
    return '' +
      '<div class="calc">' +
        section('Biaya Transport', tRows, t.transport) +
        section('Gaji Crew', cRows, t.crew) +
        section('Sewa Alat', eRows, t.equipment) +
        '<section class="calc__sec calc__sec--sum">' +
          '<div class="calc__row"><span>Revenue</span><span class="num">' + rp(tx.revenue) + '</span></div>' +
          '<div class="calc__row"><span>Total Operasional</span><span class="num">' + rp(t.operational) + '</span></div>' +
          '<div class="calc__net"><span>Net Profit</span><strong class="num">' + rp(t.net_profit) + '</strong></div>' +
          '<div class="calc__row calc__row--muted"><span>Margin ' + MH.pct(t.net_profit, tx.revenue, 1) +
            ' · Operasional ' + MH.pct(t.operational, tx.revenue, 2) + ' dari revenue</span></div>' +
        '</section>' +
      '</div>';
  };

  /** Cetak invoice → user memilih "Simpan sebagai PDF" di dialog print */
  MH.printInvoice = function (tx, customer) {
    var area = document.getElementById('print-area');
    if (area.parentNode !== document.body) document.body.appendChild(area); // harus di luar .app
    area.innerHTML = MH.invoiceHtml(tx, customer);
    var oldTitle = document.title;
    document.title = tx.invoice_no;          // jadi nama file PDF
    document.body.classList.add('is-printing');
    var done = function () {
      document.body.classList.remove('is-printing');
      document.title = oldTitle;
      area.innerHTML = '';
      window.removeEventListener('afterprint', done);
    };
    window.addEventListener('afterprint', done);
    window.print();
  };
})();
