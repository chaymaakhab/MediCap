/**
 * Cliniqo Cloud — Moteur d'Édition & Impression PDF Officielle (Section 3G & Rapports)
 * Utilise l'identité visuelle, le logo et les mentions légales configurés par chaque centre.
 */
(function () {
  'use strict';

  const DEFAULT_LOGO_SVG = `<svg width="42" height="42" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
    <rect x="36" y="8" width="28" height="84" rx="14" fill="#005131"/>
    <rect x="8" y="36" width="36" height="28" rx="14" fill="#005131"/>
    <rect x="44" y="36" width="48" height="28" rx="14" fill="#176B45"/>
    <circle cx="50" cy="50" r="14" fill="#003820" fill-opacity="0.22"/>
    <path d="M 60 41.5 C 57.5 39 53.5 38 49 38 C 42 38 37 43 37 50 C 37 57 42 62 49 62 C 53.8 62 57.6 61 60 58.5" stroke="#FFFFFF" stroke-width="7.5" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>`;

  function esc(val) {
    return String(val ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtMad(val, currency = 'MAD') {
    const n = Number(val || 0);
    const converted = currency === 'EUR' ? n / 10.8 : n;
    return converted.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + currency;
  }

  function renderLogoHtml(tenant) {
    const url = String(tenant?.logo_url || '').trim();
    if (url && !url.endsWith('assets/logo.svg')) {
      return `<img src="${esc(url)}" alt="${esc(tenant?.name || 'Clinique')}" style="width:46px;height:46px;object-fit:contain;border-radius:10px;border:1px solid #d5e2da;background:#fff;padding:3px;" onerror="this.style.display='none'"/>`;
    }
    return DEFAULT_LOGO_SVG;
  }

  function buildPrintWindowHtml(tenant, badgeLabel, docTitle, reference, dateStr, innerBodyHtml, signatoryLabel) {
    const clinicName = esc(tenant?.name || 'Cabinet Médical Cliniqo');
    const branchInfo = esc(tenant?.branch || tenant?.city || 'Casablanca');
    const address = esc(tenant?.address || 'Casablanca, Maroc');
    const phone = esc(tenant?.phone || '+212 522-000000');
    const email = esc(tenant?.email || 'contact@cliniqo.ma');
    const ice = esc(tenant?.ice || '—');
    const ifTax = esc(tenant?.if_tax || '—');
    const inpe = esc(tenant?.inpe || '—');
    const cnss = esc(tenant?.cnss_affiliation || '—');
    const headerNote = esc(tenant?.doc_header_note || 'Établissement de Santé Agréé — Maroc');
    const footerNote = esc(tenant?.doc_footer_note || 'Document officiel généré par Cliniqo Cloud • Conforme CNDP Loi 09-08.');

    return `<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <title>${esc(reference)} — ${clinicName}</title>
  <style>
    @page { size: A4; margin: 14mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0; padding: 24px;
      font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      color: #1F3329; background: #F5F7F5; font-size: 12.5px; line-height: 1.5;
    }
    .sheet {
      max-width: 780px; margin: 0 auto; background: #FFFFFF;
      border: 1px solid #d5e2da; border-radius: 16px; padding: 28px 32px;
      box-shadow: 0 8px 24px rgba(23, 107, 69, 0.06);
    }
    .toolbar {
      max-width: 780px; margin: 0 auto 14px auto; display: flex; justify-content: space-between; align-items: center;
    }
    .btn {
      background: #176B45; color: #FFFFFF; border: none; border-radius: 999px;
      padding: 8px 18px; font-weight: 700; font-size: 12px; cursor: pointer;
    }
    .btn-sec {
      background: #EAF4EE; color: #176B45; border: 1px solid #c5ddd0; border-radius: 999px;
      padding: 8px 14px; font-weight: 700; font-size: 12px; cursor: pointer;
    }
    .hdr {
      display: flex; justify-content: space-between; align-items: flex-start;
      padding-bottom: 16px; border-bottom: 2px solid #EAF4EE; gap: 16px;
    }
    .brand { display: flex; gap: 12px; align-items: flex-start; }
    .clinic-title { font-size: 17px; font-weight: 800; color: #1F3329; margin: 0; }
    .clinic-sub { font-size: 11px; color: #4e6357; margin: 2px 0 0 0; }
    .doc-box { text-align: right; }
    .badge {
      display: inline-block; background: #EAF4EE; color: #176B45;
      font-weight: 800; font-size: 10.5px; text-transform: uppercase;
      letter-spacing: 0.05em; padding: 4px 10px; border-radius: 999px;
    }
    .ref { font-family: monospace; font-size: 13.5px; font-weight: 800; color: #176B45; margin-top: 6px; }
    .meta-bar {
      display: flex; flex-wrap: wrap; gap: 14px; justify-content: space-between;
      background: #F5F7F5; border-radius: 10px; padding: 9px 14px; margin: 14px 0 18px 0;
      font-size: 11px; color: #3f4942;
    }
    table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 12px; }
    th {
      background: #EAF4EE; color: #176B45; text-align: left; padding: 8px 10px;
      font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.04em;
    }
    td { padding: 8px 10px; border-bottom: 1px solid #edf2ee; vertical-align: top; }
    .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 12px 0 16px 0; }
    .kpi-box { background: #F5F7F5; border: 1px solid #e1e9e4; border-radius: 10px; padding: 10px 12px; }
    .kpi-lbl { font-size: 10px; color: #57615c; text-transform: uppercase; font-weight: 700; display: block; }
    .kpi-val { font-size: 15px; font-weight: 800; color: #1F3329; margin-top: 2px; display: block; }
    .body-pre {
      white-space: pre-wrap; background: #F5F7F5; border-left: 3px solid #176B45;
      padding: 14px 16px; border-radius: 8px; font-size: 12.5px; margin: 12px 0;
    }
    .sig-row {
      display: flex; justify-content: space-between; align-items: flex-end;
      margin-top: 28px; padding-top: 16px; border-top: 1px dashed #c8d6ce;
    }
    .stamp {
      width: 190px; height: 74px; border: 1.5px dashed #8eb8a1; border-radius: 10px;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      color: #176B45; font-size: 10.5px; font-weight: 700; background: #fafdfb;
    }
    .ftr {
      margin-top: 22px; padding-top: 10px; border-top: 1px solid #EAF4EE;
      font-size: 10px; color: #57615c; text-align: center;
    }
    @media print {
      body { background: #FFFFFF; padding: 0; }
      .toolbar { display: none !important; }
      .sheet { box-shadow: none; border: none; padding: 0; max-width: 100%; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <button class="btn-sec" onclick="window.close()">Fermer l’aperçu</button>
    <button class="btn" onclick="window.print()">Imprimer / Enregistrer en PDF</button>
  </div>
  <div class="sheet">
    <div class="hdr">
      <div class="brand">
        ${renderLogoHtml(tenant)}
        <div>
          <h1 class="clinic-title">${clinicName}</h1>
          <p class="clinic-sub">${branchInfo} • ${address}</p>
          <p class="clinic-sub">Tél : ${phone} • Email : ${email}</p>
          <p class="clinic-sub" style="color:#176B45;font-weight:700;">${headerNote}</p>
        </div>
      </div>
      <div class="doc-box">
        <span class="badge">${esc(badgeLabel)}</span>
        <div class="ref">Réf : ${esc(reference)}</div>
        <div style="font-size:11px;color:#57615c;margin-top:2px;">Date : ${esc(dateStr)}</div>
      </div>
    </div>

    <div class="meta-bar">
      <span><strong>ICE :</strong> ${ice}</span>
      <span><strong>IF :</strong> ${ifTax}</span>
      <span><strong>INPE :</strong> ${inpe}</span>
      <span><strong>Affiliation CNSS :</strong> ${cnss}</span>
    </div>

    <h2 style="font-size:15px;font-weight:800;margin:0 0 10px 0;color:#1F3329;">${esc(docTitle)}</h2>
    ${innerBodyHtml}

    <div class="sig-row">
      <div style="font-size:10.5px;color:#57615c;max-width:340px;">
        <strong>Authenticité &amp; Traçabilité :</strong> Réf. unique <code>${esc(reference)}</code> horodatée dans le registre sécurisé de l’établissement.
      </div>
      <div class="stamp">
        <span>${esc(signatoryLabel || 'Cachet & Signature Autorisée')}</span>
        <span style="font-size:9.5px;font-weight:500;color:#57615c;margin-top:3px;">${clinicName}</span>
      </div>
    </div>

    <div class="ftr">${footerNote}</div>
  </div>
</body>
</html>`;
  }

  function launchPrintPopup(htmlContent) {
    const win = window.open('', '_blank', 'width=880,height=920,scrollbars=yes');
    if (win && win.document) {
      win.document.open();
      win.document.write(htmlContent);
      win.document.close();
      return true;
    }
    return false;
  }

  const TYPE_LABELS = {
    invoice: 'Facture Médicale',
    receipt: 'Reçu d’Encaissement',
    refund: 'Avoir / Remboursement',
    quote: 'Devis Médical',
    prescription: 'Ordonnance Médicale',
    certificate: 'Certificat Médical',
    report: 'Compte-Rendu Médical',
    appointment_confirmation: 'Confirmation de Rendez-vous'
  };

  window.CliniqoPdf = {
    printInvoice(tenant, inv, currency = 'MAD') {
      const paymentsRows = (inv.payments || []).map(p => `
        <tr>
          <td style="font-family:monospace;font-weight:700;">${esc(p.receipt_num)}</td>
          <td>${esc(p.paid_date)} ${esc(p.paid_time || '')}</td>
          <td>${p.payment_type === 'refund' ? 'Avoir / Remboursement' : 'Règlement'} (${esc(p.method)})</td>
          <td style="text-align:right;font-weight:700;color:${p.payment_type === 'refund' ? '#ba1a1a' : '#176B45'};">
            ${p.payment_type === 'refund' ? '- ' : ''}${fmtMad(p.amount, currency)}
          </td>
        </tr>
      `).join('');

      const body = `
        <div class="kpi-grid" style="grid-template-columns:repeat(2,1fr);">
          <div class="kpi-box">
            <span class="kpi-lbl">Patient</span>
            <span class="kpi-val" style="font-size:13.5px;">${esc(inv.patient)}</span>
            <span style="font-size:11px;color:#57615c;">Couverture : ${esc(inv.cnss_org || 'Particulier')} (${esc(inv.cnss_status || '—')})</span>
          </div>
          <div class="kpi-box">
            <span class="kpi-lbl">Praticien &amp; Spécialité</span>
            <span class="kpi-val" style="font-size:13.5px;">${esc(inv.doctor_name || 'Équipe Médicale')}</span>
            <span style="font-size:11px;color:#57615c;">${esc(inv.specialty || 'Consultation')}</span>
          </div>
        </div>
        <table>
          <thead>
            <tr>
              <th>Désignation de l’Acte Médical</th>
              <th style="text-align:right;">Total Facturé</th>
              <th style="text-align:right;">Encaissé Net</th>
              <th style="text-align:right;">Reste à Payer</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>${esc(inv.service)}</strong></td>
              <td style="text-align:right;font-weight:700;">${fmtMad(inv.total, currency)}</td>
              <td style="text-align:right;font-weight:700;color:#176B45;">${fmtMad(inv.net_paid ?? inv.paid, currency)}</td>
              <td style="text-align:right;font-weight:800;color:${Number(inv.remaining) > 0 ? '#ba1a1a' : '#176B45'};">${fmtMad(inv.remaining, currency)}</td>
            </tr>
          </tbody>
        </table>
        ${paymentsRows ? `
          <h3 style="font-size:12px;font-weight:800;margin:16px 0 6px 0;color:#176B45;">Historique des Règlements &amp; Avoirs Enregistrés</h3>
          <table>
            <thead><tr><th>Réf. Pièce</th><th>Date &amp; Heure</th><th>Opération / Mode</th><th style="text-align:right;">Montant</th></tr></thead>
            <tbody>${paymentsRows}</tbody>
          </table>
        ` : '<p style="font-size:11.5px;color:#57615c;">Aucun règlement enregistré à ce jour sur cette facture.</p>'}
      `;

      const html = buildPrintWindowHtml(
        tenant,
        Number(inv.remaining) <= 0.01 ? 'FACTURE ACQUITTÉE' : 'FACTURE MÉDICALE',
        `Facture Patient — ${inv.patient}`,
        inv.num,
        inv.date || inv.issued_date,
        body,
        'Service Facturation & Caisse'
      );
      return launchPrintPopup(html);
    },

    printDocument(tenant, doc, currency = 'MAD') {
      const badge = TYPE_LABELS[doc.doc_type] || 'Document Officiel';
      const amountLine = Number(doc.amount_mad || 0) > 0
        ? `<div class="kpi-box" style="margin-top:10px;display:inline-block;min-width:220px;">
             <span class="kpi-lbl">Montant Évalué / Facturable</span>
             <span class="kpi-val" style="color:#176B45;">${fmtMad(doc.amount_mad, currency)}</span>
           </div>`
        : '';

      const body = `
        <div class="kpi-grid" style="grid-template-columns:repeat(2,1fr);">
          <div class="kpi-box">
            <span class="kpi-lbl">Patient Concerné</span>
            <span class="kpi-val" style="font-size:13.5px;">${esc(doc.patient_name || 'Patient')}</span>
            <span style="font-size:11px;color:#57615c;">${doc.patient_code ? 'Dossier #' + esc(doc.patient_code) : ''} ${doc.patient_cin ? '• CIN ' + esc(doc.patient_cin) : ''}</span>
          </div>
          <div class="kpi-box">
            <span class="kpi-lbl">Praticien / Émetteur</span>
            <span class="kpi-val" style="font-size:13.5px;">${esc(doc.doctor_name || doc.created_by || 'Direction Médicale')}</span>
            <span style="font-size:11px;color:#57615c;">Usage interne clinique</span>
          </div>
        </div>
        <div class="body-pre">${esc(doc.body_text || '')}</div>
        ${amountLine}
      `;

      const html = buildPrintWindowHtml(
        tenant,
        badge,
        doc.title || badge,
        doc.reference || 'DOC-2026',
        doc.issued_date || '2026-10-29',
        body,
        doc.doctor_name ? `Signature : ${doc.doctor_name}` : 'Cachet Médical Officiel'
      );
      return launchPrintPopup(html);
    },

    printAppointmentConfirmation(tenant, apt, patientName, currency = 'MAD') {
      const ref = `CONF-${apt.id}-${String(apt.date || '').replace(/-/g, '')}`;
      const body = `
        <div class="kpi-grid" style="grid-template-columns:repeat(2,1fr);">
          <div class="kpi-box">
            <span class="kpi-lbl">Patient</span>
            <span class="kpi-val" style="font-size:14px;">${esc(patientName)}</span>
          </div>
          <div class="kpi-box">
            <span class="kpi-lbl">Date &amp; Horaire</span>
            <span class="kpi-val" style="font-size:14px;color:#176B45;">${esc(apt.date)} à ${esc(apt.time)} (${esc(apt.duration_min || 30)} min)</span>
          </div>
          <div class="kpi-box">
            <span class="kpi-lbl">Praticien &amp; Spécialité</span>
            <span class="kpi-val" style="font-size:13px;">${esc(apt.doctor)} • ${esc(apt.specialty)}</span>
          </div>
          <div class="kpi-box">
            <span class="kpi-lbl">Salle &amp; Tarif indicatif</span>
            <span class="kpi-val" style="font-size:13px;">${esc(apt.room || 'Accueil')} • ${fmtMad(apt.fee_mad || 0, currency)}</span>
          </div>
        </div>
        <div class="body-pre">Acte / Motif : ${esc(apt.service_name || apt.reason || 'Consultation médicale')}\nStatut actuel : ${esc(apt.status)}\nMerci de vous présenter à l’accueil muni(e) de votre CIN et carte mutuelle/CNSS 15 minutes avant l’horaire prévu.</div>
      `;
      const html = buildPrintWindowHtml(
        tenant,
        'CONFIRMATION DE RENDEZ-VOUS',
        'Convocation & Confirmation de Rendez-vous Médical',
        ref,
        apt.date || '2026-10-29',
        body,
        'Accueil & Planification Médicale'
      );
      return launchPrintPopup(html);
    },

    printReport(tenant, report, currency = 'MAD') {
      if (!report) return false;
      const f = report.filters || {};
      const as = report.appointments_summary || {};
      const fs = report.financial_summary || {};
      const ps = report.patients_summary || {};

      const docRows = (report.doctor_activity || []).map(d => `
        <tr>
          <td><strong>${esc(d.doctor)}</strong></td>
          <td>${esc(d.specialty)}</td>
          <td>${d.total_appointments}</td>
          <td style="font-weight:700;color:#176B45;">${d.consultations_completed}</td>
          <td>${d.cancelled} / ${d.no_show}</td>
          <td style="text-align:right;font-weight:700;">${fmtMad(d.scheduled_fees_mad, currency)}</td>
        </tr>
      `).join('');

      const unpaidRows = (report.unpaid_invoices || []).slice(0, 10).map(u => `
        <tr>
          <td><strong style="color:#176B45;">${esc(u.num)}</strong></td>
          <td>${esc(u.patient_name)}</td>
          <td>${esc(u.issued_date)}</td>
          <td style="text-align:right;">${fmtMad(u.total, currency)}</td>
          <td style="text-align:right;color:#176B45;">${fmtMad(u.paid, currency)}</td>
          <td style="text-align:right;font-weight:800;color:#ba1a1a;">${fmtMad(u.remaining, currency)}</td>
        </tr>
      `).join('');

      const reasonsRows = (report.top_reasons || []).slice(0, 5).map(r => `
        <tr>
          <td><strong>${esc(r.reason)}</strong></td>
          <td style="text-align:center;">${r.count}</td>
          <td style="text-align:center;color:#176B45;font-weight:700;">${r.completed}</td>
          <td style="text-align:right;font-weight:700;">${r.share_pct}%</td>
        </tr>
      `).join('');

      const body = `
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:11.5px;color:#176B45;">
          <strong>Période du Rapport :</strong> du <strong>${esc(f.date_from)}</strong> au <strong>${esc(f.date_to)}</strong>
          • <strong>Médecin :</strong> ${esc(f.doctor === 'all' ? 'Tous les médecins' : f.doctor)}
          • <strong>Spécialité :</strong> Médecine Générale
          • <strong>Généré le :</strong> ${new Date().toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
        </div>

        <h3 style="font-size:12.5px;font-weight:800;color:#176B45;margin:12px 0 6px 0;">1. Synthèse de l'Activité Médicale &amp; Patientèle (KPIs)</h3>
        <div class="kpi-grid" style="grid-template-columns: repeat(4, 1fr);">
          <div class="kpi-box"><span class="kpi-lbl">Volume des RDV</span><span class="kpi-val">${as.total || 0} RDV</span></div>
          <div class="kpi-box"><span class="kpi-lbl">RDV Terminés</span><span class="kpi-val" style="color:#176B45;">${as.completed || 0}</span></div>
          <div class="kpi-box"><span class="kpi-lbl">Annulations / No-Show</span><span class="kpi-val" style="color:#ba1a1a;">${as.cancelled || 0} (${as.cancellation_rate_pct || 0}%) / ${as.no_show || 0}</span></div>
          <div class="kpi-box"><span class="kpi-lbl">Taux de Présence</span><span class="kpi-val">${as.attendance_rate_pct || 0}%</span></div>
        </div>
        <div class="kpi-grid" style="grid-template-columns: repeat(2, 1fr); margin-top: 8px;">
          <div class="kpi-box"><span class="kpi-lbl">Nouveaux Patients Enregistrés</span><span class="kpi-val">${ps.new_patients_count || 0} dossiers</span></div>
          <div class="kpi-box"><span class="kpi-lbl">Consultations Réalisées</span><span class="kpi-val" style="color:#176B45;">${as.completed || 0} actes</span></div>
        </div>

        <h3 style="font-size:12.5px;font-weight:800;color:#176B45;margin:16px 0 6px 0;">2. Synthèse Financière &amp; Trésorerie (Cohérence Stricte MAD)</h3>
        <div class="kpi-grid" style="grid-template-columns: repeat(3, 1fr);">
          <div class="kpi-box"><span class="kpi-lbl">Total Facturé</span><span class="kpi-val">${fmtMad(fs.invoiced_amount, currency)}</span></div>
          <div class="kpi-box"><span class="kpi-lbl">Total Encaissé Réel</span><span class="kpi-val" style="color:#176B45;">${fmtMad(fs.revenue_collected, currency)}</span></div>
          <div class="kpi-box"><span class="kpi-lbl">Soldes Impayés Période</span><span class="kpi-val" style="color:#ba1a1a;">${fmtMad(fs.outstanding_period, currency)}</span></div>
        </div>
        <div class="kpi-grid" style="grid-template-columns: repeat(2, 1fr); margin-top: 8px;">
          <div class="kpi-box"><span class="kpi-lbl">Total des Dépenses &amp; Charges</span><span class="kpi-val">${fmtMad(fs.expenses_total, currency)}</span></div>
          <div class="kpi-box"><span class="kpi-lbl">Trésorerie / Solde Net (Encaissé - Dépenses)</span><span class="kpi-val" style="color:${(fs.net_cashflow || 0) >= 0 ? '#176B45' : '#ba1a1a'};">${fmtMad(fs.net_cashflow, currency)}</span></div>
        </div>

        ${reasonsRows ? `
          <h3 style="font-size:12.5px;font-weight:800;color:#176B45;margin:16px 0 6px 0;">3. Motifs de Consultation les Plus Fréquents (Médecine Générale)</h3>
          <table>
            <thead><tr><th>Motif Clinique</th><th style="text-align:center;">Volume</th><th style="text-align:center;">Terminés</th><th style="text-align:right;">Part (%)</th></tr></thead>
            <tbody>${reasonsRows}</tbody>
          </table>
        ` : ''}

        <h3 style="font-size:12.5px;font-weight:800;color:#176B45;margin:16px 0 6px 0;">4. Activité par Praticien</h3>
        <table>
          <thead><tr><th>Praticien</th><th>Discipline</th><th>RDV</th><th>Terminés</th><th>Annulés / Absents</th><th style="text-align:right;">Honoraires MAD</th></tr></thead>
          <tbody>${docRows || '<tr><td colspan="6">Aucune consultation sur la période sélectionnée.</td></tr>'}</tbody>
        </table>

        ${unpaidRows ? `
          <h3 style="font-size:12.5px;font-weight:800;color:#ba1a1a;margin:16px 0 6px 0;">5. Factures Impayées de la Période</h3>
          <table>
            <thead><tr><th>N° Facture</th><th>Patient</th><th>Date</th><th style="text-align:right;">Total</th><th style="text-align:right;">Payé</th><th style="text-align:right;">Reste Dû</th></tr></thead>
            <tbody>${unpaidRows}</tbody>
          </table>
        ` : ''}
      `;

      const ref = `RPT-${String(f.date_from || '').replace(/-/g, '')}-${String(f.date_to || '').slice(-2)}`;
      const html = buildPrintWindowHtml(
        tenant,
        'RAPPORT ANALYTIQUE & FINANCIER',
        'Synthèse d’Activité Clinique, Financière & Opérationnelle',
        ref,
        new Date().toISOString().slice(0, 10),
        body,
        'Direction Administrative & Financière'
      );
      return launchPrintPopup(html);
    },

    printSaasInvoice: function (inv, tenant) {
      inv = inv || { id: 1, num: 'FAC-SAAS-2026-10', date: '29-10-2026', total: 990, status: 'Payé', plan: 'Pack Cabinet Pro' };
      const body = `
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px;margin-bottom:16px;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
              <strong style="color:#005131;font-size:14px;display:block;">Facture d'Abonnement SaaS Logiciel</strong>
              <span style="font-size:11px;color:#6b7280;">Plateforme Médicale Cliniqo Cloud OS • Mono-Cabinet</span>
            </div>
            <span style="background:#005131;color:#fff;font-size:10.5px;font-weight:bold;padding:3px 10px;border-radius:9999px;">ACQUITTÉE</span>
          </div>
        </div>
        <table>
          <thead>
            <tr><th>Description de la prestation</th><th>Période</th><th>Taux TVA</th><th style="text-align:right;">Total TTC</th></tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>Licence ${esc(inv.plan || 'Pack Cabinet Pro')}</strong><br/><span style="font-size:10px;color:#6b7280;">Hébergement HDS, 10 utilisateurs, télétransmission CNSS, module Caisse &amp; Stock</span></td>
              <td>1 Mois</td>
              <td>20% (Incluse)</td>
              <td style="text-align:right;font-weight:bold;">${fmtMad(inv.total || 990)}</td>
            </tr>
          </tbody>
        </table>
        <div style="margin-top:20px;text-align:right;">
          <div style="font-size:16px;font-weight:800;color:#005131;">Total Réglé : ${fmtMad(inv.total || 990)}</div>
          <span style="font-size:11px;color:#6b7280;">Règlement effectué par Prélèvement / Carte Bancaire CMI</span>
        </div>
      `;
      const html = buildPrintWindowHtml(
        tenant,
        'FACTURE SAAS OFFICIELLE',
        `Facture d'Abonnement ${esc(inv.num)}`,
        inv.num,
        inv.date,
        body,
        'Direction Cliniqo Cloud Technologies'
      );
      return launchPrintPopup(html);
    }
  };
})();
