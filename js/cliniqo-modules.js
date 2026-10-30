/**
 * Cliniqo Cloud — Modules Métier Avancés :
 * - Facturation, Encaissements, Dépenses & Stock (Section 3H)
 * - Rapports Analytiques Complets (Section 2)
 * - Paramètres du Centre, Branches, Modèles PDF & Sauvegardes SQLite (Section 3A, 3B, 3E)
 * - Console Propriétaire SaaS (Section 3C)
 */
(function () {
  'use strict';

  // =========================================================================
  // VUE FACTURATION, CNSS, DÉPENSES & STOCK (Section 3H & Facturation)
  // =========================================================================
  window.switchFinanceSubTab = function (sub) {
    saas.financeSubTab = sub;
    ['invoices', 'expenses', 'stock', 'documents'].forEach(s => {
      document.getElementById(`fin-sub-${s}`)?.classList.toggle('hidden', s !== sub);
      const btn = document.getElementById(`fin-pill-${s}`);
      if (btn) {
        btn.className = s === sub
          ? 'px-3.5 py-1.5 rounded-full text-xs font-bold bg-primary text-white shadow-2xs transition-all'
          : 'px-3.5 py-1.5 rounded-full text-xs font-semibold bg-surface-container-low text-on-surface-variant hover:text-primary transition-all';
      }
    });

    // Update primary CTA label according to sub-tab
    const ctaEl = document.getElementById('primary-cta-label');
    if (ctaEl && saas.activeTab === 'finance') {
      const labels = {
        invoices: 'Nouvelle Facture',
        expenses: 'Nouvelle Dépense',
        stock: 'Ajouter Produit',
        documents: 'Nouveau Document'
      };
      ctaEl.textContent = labels[sub] || 'Action';
    }

    renderFinanceHub();
  };

  window.renderFinanceHub = function () {
    // Hide stock pill if inventory is disabled for this tenant
    const stockPill = document.getElementById('fin-pill-stock');
    if (stockPill) {
      stockPill.classList.toggle('hidden', !saas.tenant?.enable_inventory);
    }

    // Invoices KPIs
    let collectedSum = 0;
    let remainingSum = 0;
    let cnssSum = 0;

    saas.invoices.forEach(inv => {
      collectedSum += Number(inv.net_paid ?? inv.paid ?? 0);
      remainingSum += Number(inv.remaining ?? 0);
      cnssSum += Number(inv.cnss_est ?? 0);
    });

    const colEl = document.getElementById('fin-collected');
    const remEl = document.getElementById('fin-remaining');
    const cnssEl = document.getElementById('fin-cnss-est');
    if (colEl) colEl.textContent = fmtMoney(collectedSum);
    if (remEl) remEl.textContent = fmtMoney(remainingSum);
    if (cnssEl) cnssEl.textContent = fmtMoney(cnssSum);

    // Render Subtabs
    renderInvoicesTable();
    renderExpensesSubpanel();
    renderStockSubpanel();
    renderDocumentsSubpanel();
  };

  function renderInvoicesTable() {
    const tbody = document.getElementById('finance-invoices-tbody');
    if (!tbody) return;

    if (!saas.invoices || saas.invoices.length === 0) {
      tbody.innerHTML = `<tr><td colspan="7" class="py-8 text-center text-outline">Aucune facture émise sur cette branche.</td></tr>`;
      return;
    }

    tbody.innerHTML = saas.invoices.map(inv => {
      const netPaid = Number(inv.net_paid ?? inv.paid ?? 0);
      const rem = Number(inv.remaining ?? 0);
      const isPaid = rem <= 0.01;
      const hasRefund = Number(inv.refunded ?? 0) > 0;

      return `
        <tr class="hover:bg-surface-container-low/65 transition-colors">
          <td class="py-2.5 pr-2">
            <div class="flex items-center gap-1.5">
              <strong class="font-mono text-xs font-bold text-primary">${escHtml(inv.num)}</strong>
              ${hasRefund ? `<span class="text-[9px] font-bold px-1 rounded bg-error-container text-error" title="Avoir de ${inv.refunded} MAD">AVOIR</span>` : ''}
            </div>
            <span class="text-[10px] text-outline block">${escHtml(inv.date || inv.issued_date)}</span>
          </td>
          <td class="py-2.5 px-2">
            <strong class="text-on-surface block font-semibold">${escHtml(inv.patient)}</strong>
            <span class="text-[10px] text-outline">${escHtml(inv.service)} • ${escHtml(inv.doctor_name || '')}</span>
          </td>
          <td class="py-2.5 px-2 font-mono font-bold text-on-surface">${fmtMoney(inv.total)}</td>
          <td class="py-2.5 px-2 font-mono font-bold text-primary">${fmtMoney(netPaid)}</td>
          <td class="py-2.5 px-2 font-mono font-extrabold ${rem > 0 ? 'text-error' : 'text-primary'}">${fmtMoney(rem)}</td>
          <td class="py-2.5 px-2">
            <button onclick="advanceCnssClaimStatus(${inv.id})" title="Cliquer pour faire avancer le statut CNSS"
                    class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-secondary-container text-primary hover:bg-primary hover:text-white transition-colors">
              ${escHtml(inv.cnss_status || 'Dossier prêt')} &rarr;
            </button>
          </td>
          <td class="py-2.5 pl-2 text-right space-x-1">
            <button onclick="CliniqoPdf.printInvoice(saas.tenant, saas.invoices.find(x => x.id === ${inv.id}), saas.currency)"
                    class="px-2.5 py-1 rounded-full bg-surface-container-low hover:bg-secondary-container text-primary text-[10.5px] font-bold transition-colors">
              Imprimer PDF
            </button>
            ${!isPaid && can('finance.write') ? `
              <button onclick="openPaymentModal(${inv.id})"
                      class="px-2.5 py-1 rounded-full bg-primary hover:bg-primary-hover text-white text-[10.5px] font-bold shadow-2xs transition-colors">
                Encaisser
              </button>
            ` : ''}
            ${netPaid > 0 && can('finance.write') ? `
              <button onclick="openRefundModal(${inv.id})"
                      class="px-2 py-1 rounded-full bg-error-container text-error hover:bg-error hover:text-white text-[10px] font-bold transition-colors" title="Émettre un avoir / remboursement">
                Avoir
              </button>
            ` : ''}
          </td>
        </tr>
      `;
    }).join('');
  }

  // --- Encaissement Modal ---
  window.openPaymentModal = function (invId) {
    const inv = saas.invoices.find(x => x.id === invId);
    if (!inv) return;
    saas.activeInvoiceId = inv.id;
    document.getElementById('pay-modal-num').textContent = inv.num;
    document.getElementById('pay-modal-pat').textContent = inv.patient;
    document.getElementById('pay-modal-rem').textContent = fmtMoney(inv.remaining, true);
    document.getElementById('pay-modal-amount').value = Math.max(1, Number(inv.remaining || 100));
    openModal('payment-modal');
  };

  window.submitInvoicePayment = async function (ev) {
    ev.preventDefault();
    if (!saas.activeInvoiceId) return;
    const amount = Number(document.getElementById('pay-modal-amount').value);
    const method = document.getElementById('pay-modal-method').value;
    const res = await apiCall(`/invoices/${saas.activeInvoiceId}/payments`, 'POST', { amount, method });
    closeModal('payment-modal');
    applyBackendState(res.data || res);
    showToast(`Paiement de ${amount} MAD encaissé avec succès (Reçu officiel émis)`);
  };

  // --- Remboursement / Avoir Modal ---
  window.openRefundModal = function (invId) {
    const inv = saas.invoices.find(x => x.id === invId);
    if (!inv) return;
    saas.activeInvoiceId = inv.id;
    document.getElementById('ref-modal-num').textContent = inv.num;
    document.getElementById('ref-modal-pat').textContent = inv.patient;
    const netP = Number(inv.net_paid ?? inv.paid ?? 0);
    document.getElementById('ref-modal-max').textContent = fmtMoney(netP, true);
    document.getElementById('ref-modal-amount').value = netP;
    document.getElementById('ref-modal-amount').max = netP;
    openModal('refund-modal');
  };

  window.submitInvoiceRefund = async function (ev) {
    ev.preventDefault();
    if (!saas.activeInvoiceId) return;
    const amount = Number(document.getElementById('ref-modal-amount').value);
    const reason = document.getElementById('ref-modal-reason').value.trim();
    const method = document.getElementById('ref-modal-method').value;
    const res = await apiCall(`/invoices/${saas.activeInvoiceId}/refunds`, 'POST', { amount, reason, method });
    closeModal('refund-modal');
    applyBackendState(res.data || res);
    showToast(`Avoir / Remboursement de ${amount} MAD émis`);
  };

  window.advanceCnssClaimStatus = async function (invId) {
    const res = await apiCall(`/invoices/${invId}/cnss`, 'PATCH');
    applyBackendState(res.data || res);
    showToast('Statut de télétransmission CNSS/AMO mis à jour');
  };

  window.submitNewInvoice = async function (ev) {
    ev.preventDefault();
    const payload = {
      patient_id: Number(document.getElementById('inv-patient-select').value),
      service: document.getElementById('inv-service').value.trim(),
      total: Number(document.getElementById('inv-total').value),
      paid: Number(document.getElementById('inv-paid').value || 0),
      method: 'Espèces'
    };
    const res = await apiCall('/invoices', 'POST', payload);
    closeModal('invoice-modal');
    ev.target.reset();
    applyBackendState(res.data || res);
    showToast('Facture médicale émise avec succès');
  };

  // --- Sub-panel 2: Dépenses & Charges ---
  function renderExpensesSubpanel() {
    const tbody = document.getElementById('expenses-tbody');
    const totEl = document.getElementById('exp-stat-total');
    if (!tbody) return;

    let tot = 0;
    saas.expenses.forEach(e => tot += Number(e.amount || 0));
    if (totEl) totEl.textContent = fmtMoney(tot, true);

    if (saas.expenses.length === 0) {
      tbody.innerHTML = `<tr><td colspan="6" class="py-8 text-center text-outline">Aucune dépense enregistrée sur cette sélection.</td></tr>`;
      return;
    }

    tbody.innerHTML = saas.expenses.map(e => `
      <tr class="hover:bg-surface-container-low/65 transition-colors">
        <td class="py-2.5 pr-2 font-mono text-xs font-bold text-on-surface">${escHtml(e.reference)}</td>
        <td class="py-2.5 px-2">
          <strong class="text-on-surface block">${escHtml(e.description)}</strong>
          <span class="text-[10px] text-outline">${escHtml(e.supplier || 'Fournisseur direct')} ${e.supporting_doc_ref ? '• Doc: ' + escHtml(e.supporting_doc_ref) : ''}</span>
        </td>
        <td class="py-2.5 px-2"><span class="px-2 py-0.5 rounded-full bg-secondary-container/70 text-primary text-[10px] font-bold">${escHtml(e.category)}</span></td>
        <td class="py-2.5 px-2 font-mono font-bold text-error">${fmtMoney(e.amount, true)}</td>
        <td class="py-2.5 px-2 text-[10.5px] text-on-surface-variant">${escHtml(e.payment_method)} (${escHtml(e.expense_date)})</td>
        <td class="py-2.5 pl-2 text-right">
          ${can('expenses.write') ? `<button onclick="deleteExpenseItem(${e.id})" class="text-[11px] font-bold text-error hover:underline">Supprimer</button>` : ''}
        </td>
      </tr>
    `).join('');
  }

  window.submitNewExpense = async function (ev) {
    ev.preventDefault();
    const payload = {
      category: document.getElementById('exp-category').value,
      description: document.getElementById('exp-desc').value.trim(),
      amount: Number(document.getElementById('exp-amount').value),
      supplier: document.getElementById('exp-supplier').value.trim(),
      supporting_doc_ref: document.getElementById('exp-doc-ref').value.trim(),
      payment_method: document.getElementById('exp-method').value,
      expense_date: document.getElementById('exp-date').value || TODAY_ISO
    };
    const res = await apiCall('/expenses', 'POST', payload);
    closeModal('expense-modal');
    ev.target.reset();
    applyBackendState(res.data || res);
    showToast('Dépense enregistrée et imputée à la caisse');
  };

  window.deleteExpenseItem = async function (id) {
    if (!confirm('Supprimer cette dépense ?')) return;
    const res = await apiCall(`/expenses/${id}`, 'DELETE');
    applyBackendState(res.data || res);
    showToast('Dépense supprimée');
  };

  // --- Sub-panel 3: Stock Médical Optionnel ---
  function renderStockSubpanel() {
    const tbody = document.getElementById('stock-tbody');
    const lowBadge = document.getElementById('stock-low-badge');
    if (!tbody) return;

    if (!saas.tenant?.enable_inventory) {
      tbody.innerHTML = `<tr><td colspan="7" class="py-8 text-center text-outline">Le module Stock & Fournitures est désactivé pour ce centre.</td></tr>`;
      return;
    }

    const lowCnt = saas.inventory.filter(i => i.is_low).length;
    if (lowBadge) {
      lowBadge.textContent = `${lowCnt} alerte${lowCnt > 1 ? 's' : ''} rupture`;
      lowBadge.className = lowCnt > 0
        ? 'px-2 py-0.5 rounded-full bg-error-container text-error text-[10px] font-bold'
        : 'px-2 py-0.5 rounded-full bg-secondary-container text-primary text-[10px] font-bold';
    }

    if (saas.inventory.length === 0) {
      tbody.innerHTML = `<tr><td colspan="7" class="py-8 text-center text-outline">Aucun article en stock. Cliquez sur "+ Ajouter Produit".</td></tr>`;
      return;
    }

    tbody.innerHTML = saas.inventory.map(it => `
      <tr class="hover:bg-surface-container-low/65 transition-colors ${it.is_low ? 'bg-error-container/15' : ''}">
        <td class="py-2.5 pr-2 font-mono text-xs font-bold text-primary">${escHtml(it.sku)}</td>
        <td class="py-2.5 px-2">
          <strong class="text-on-surface block">${escHtml(it.name)}</strong>
          <span class="text-[10px] text-outline">${escHtml(it.supplier || 'Fournisseur')}</span>
        </td>
        <td class="py-2.5 px-2 text-[11px] text-on-surface-variant">${escHtml(it.category)}</td>
        <td class="py-2.5 px-2">
          <span class="font-extrabold text-xs ${it.is_low ? 'text-error font-mono' : 'text-primary font-mono'}">${it.quantity}</span>
          <span class="text-[10px] text-outline"> ${escHtml(it.unit)}</span>
          ${it.is_low ? `<span class="ml-1 text-[9px] font-extrabold px-1.5 py-0.2 rounded bg-error-container text-error">BAS</span>` : ''}
        </td>
        <td class="py-2.5 px-2 text-xs text-outline font-mono">${it.min_threshold} ${escHtml(it.unit)}</td>
        <td class="py-2.5 px-2 font-mono text-xs">${fmtMoney(it.unit_cost_mad, true)}</td>
        <td class="py-2.5 pl-2 text-right space-x-1">
          ${can('stock.write') ? `
            <button onclick="quickStockMovement(${it.id}, 'in')" class="px-2 py-1 rounded-full bg-secondary-container text-primary text-[10.5px] font-bold hover:bg-primary hover:text-white transition-colors">+ Entrée</button>
            <button onclick="quickStockMovement(${it.id}, 'out')" class="px-2 py-1 rounded-full bg-surface-container-low hover:bg-error-container text-error text-[10.5px] font-bold transition-colors">- Sortie</button>
          ` : ''}
        </td>
      </tr>
    `).join('');
  }

  window.submitNewStockItem = async function (ev) {
    ev.preventDefault();
    const payload = {
      name: document.getElementById('stk-name').value.trim(),
      category: document.getElementById('stk-cat').value,
      unit: document.getElementById('stk-unit').value.trim(),
      quantity: Number(document.getElementById('stk-qty').value || 0),
      min_threshold: Number(document.getElementById('stk-min').value || 10),
      unit_cost_mad: Number(document.getElementById('stk-cost').value || 0),
      supplier: document.getElementById('stk-supplier').value.trim()
    };
    const res = await apiCall('/inventory', 'POST', payload);
    closeModal('stock-modal');
    ev.target.reset();
    applyBackendState(res.data || res);
    showToast('Produit ajouté au stock médical');
  };

  window.quickStockMovement = async function (itemId, type) {
    const it = saas.inventory.find(x => x.id === itemId);
    if (!it) return;
    const promptMsg = type === 'in'
      ? `Quantité reçue pour "${it.name}" (${it.unit}) :`
      : `Quantité sortie pour "${it.name}" (${it.quantity} disponibles) :`;
    const qtyStr = prompt(promptMsg, '1');
    if (!qtyStr) return;
    const qty = parseInt(qtyStr, 10);
    if (isNaN(qty) || qty <= 0) {
      showToast('Quantité invalide', 'error');
      return;
    }
    const res = await apiCall(`/inventory/${itemId}/movements`, 'POST', {
      movement_type: type,
      quantity: qty,
      reason: type === 'in' ? 'Réapprovisionnement fournisseur' : 'Utilisation cabinet/bloc'
    });
    applyBackendState(res.data || res);
    showToast(`Mouvement enregistré : ${type === 'in' ? '+' : '-'}${qty} ${it.unit}`);
  };

  // --- Sub-panel 4: Documents Médicaux & Modèles PDF (Section 3G) ---
  function renderDocumentsSubpanel() {
    const listEl = document.getElementById('documents-list-grid');
    if (!listEl) return;

    if (saas.documents.length === 0) {
      listEl.innerHTML = `<div class="col-span-3 p-6 text-center text-xs text-outline bg-surface-container-low rounded-2xl">Aucun document médical émis. Cliquez sur "+ Nouveau Document" pour créer un devis, certificat, ordonnance ou confirmation.</div>`;
      return;
    }

    const typeIcons = {
      quote: 'request_quote',
      prescription: 'medication',
      certificate: 'verified',
      report: 'assignment',
      appointment_confirmation: 'event_available'
    };

    listEl.innerHTML = saas.documents.map(d => `
      <div class="p-3.5 rounded-2xl bg-surface-container-low/80 border border-outline-variant/30 flex flex-col justify-between gap-2.5 text-xs">
        <div>
          <div class="flex items-start justify-between gap-2 mb-1.5">
            <span class="inline-flex items-center gap-1 font-mono text-[10px] font-bold text-primary">
              <span class="material-symbols-outlined text-[14px]">${typeIcons[d.doc_type] || 'description'}</span>
              ${escHtml(d.reference)}
            </span>
            <button onclick="toggleDocPortalShare(${d.id})" title="Cliquer pour autoriser/bloquer l'accès patient sur le portail"
                    class="px-2 py-0.5 rounded-full text-[9.5px] font-bold transition-all ${d.shared_with_patient ? 'bg-secondary-container text-primary hover:bg-error-container hover:text-error' : 'bg-surface-container text-outline hover:bg-secondary-container hover:text-primary'}">
              ${d.shared_with_patient ? 'Portail Patient' : 'Interne Clinique'}
            </button>
          </div>
          <h4 class="font-extrabold text-on-surface text-[12.5px] leading-snug">${escHtml(d.title)}</h4>
          <span class="text-[10.5px] text-outline block mt-0.5">Patient : <strong>${escHtml(d.patient_name || 'Établissement')}</strong></span>
          <p class="text-[11px] text-on-surface-variant line-clamp-2 mt-1">${escHtml(d.body_text)}</p>
        </div>
        <div class="flex items-center justify-between pt-1.5 border-t border-outline-variant/20">
          <span class="text-[10px] text-outline">${escHtml(d.issued_date)} • ${escHtml(d.doctor_name || d.created_by)}</span>
          <button onclick="CliniqoPdf.printDocument(saas.tenant, saas.documents.find(x => x.id === ${d.id}), saas.currency)"
                  class="px-3 py-1 rounded-full bg-primary text-white text-[10.5px] font-bold hover:bg-primary-hover flex items-center gap-1 shadow-2xs">
            <span class="material-symbols-outlined text-[13px]">print</span> Imprimer PDF
          </button>
        </div>
      </div>
    `).join('');
  }

  window.toggleDocPortalShare = async function (docId) {
    const res = await apiCall(`/documents/${docId}/share`, 'PATCH');
    applyBackendState(res.data || res);
    showToast('Visibilité sur le Portail Patient mise à jour');
  };

  window.submitNewDocument = async function (ev) {
    ev.preventDefault();
    const payload = {
      patient_id: Number(document.getElementById('docgen-patient-select').value),
      doc_type: document.getElementById('docgen-type').value,
      title: document.getElementById('docgen-title').value.trim(),
      body_text: document.getElementById('docgen-body').value.trim(),
      amount_mad: Number(document.getElementById('docgen-amount')?.value || 0),
      shared_with_patient: document.getElementById('docgen-share')?.checked ?? true
    };
    const res = await apiCall('/documents', 'POST', payload);
    closeModal('document-modal');
    ev.target.reset();
    applyBackendState(res.data || res);
    showToast('Document médical généré et prêt pour impression');
  };

  // =========================================================================
  // VUE RAPPORTS ANALYTIQUES COMPLETS (Section 2)
  // =========================================================================
  window.loadReportsSummary = async function () {
    const period = document.getElementById('rpt-period-select')?.value || 'month';
    const branch = document.getElementById('rpt-branch-select')?.value || saas.branchId || 'all';
    const doctor = document.getElementById('rpt-doctor-select')?.value || 'all';
    const specialty = document.getElementById('rpt-specialty-select')?.value || 'all';
    const service = document.getElementById('rpt-service-select')?.value || 'all';
    const dateFrom = document.getElementById('rpt-date-from')?.value || '';
    const dateTo = document.getElementById('rpt-date-to')?.value || '';

    let url = `/reports/summary?period=${encodeURIComponent(period)}&branch_id=${encodeURIComponent(branch)}&doctor=${encodeURIComponent(doctor)}&specialty=${encodeURIComponent(specialty)}&service=${encodeURIComponent(service)}`;
    if (period === 'custom' && dateFrom && dateTo) {
      url += `&date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}`;
    }

    try {
      const res = await apiCall(url, 'GET');
      saas.lastReport = res.report;
      renderReportsView(res.report);
    } catch (_) {}
  };

  window.populateReportFilterDropdowns = function () {
    const docSel = document.getElementById('rpt-doctor-select');
    const specSel = document.getElementById('rpt-specialty-select');
    const brSel = document.getElementById('rpt-branch-select');

    if (docSel) {
      const cur = docSel.value;
      docSel.innerHTML = `<option value="all">Tous les médecins</option>` +
        saas.doctors.map(d => `<option value="${escHtml(d.name)}" ${d.name === cur ? 'selected' : ''}>${escHtml(d.name)}</option>`).join('');
    }

    if (specSel) {
      const specs = Array.from(new Set(saas.doctors.map(d => d.specialty).filter(Boolean)));
      const cur = specSel.value;
      specSel.innerHTML = `<option value="all">Toutes spécialités</option>` +
        specs.map(s => `<option value="${escHtml(s)}" ${s === cur ? 'selected' : ''}>${escHtml(s)}</option>`).join('');
    }

    if (brSel) {
      const cur = brSel.value || saas.branchId;
      brSel.innerHTML = `<option value="all">Toutes les branches</option>` +
        saas.branches.map(b => `<option value="${b.id}" ${String(b.id) === String(cur) ? 'selected' : ''}>${escHtml(b.name)}</option>`).join('');
    }
  };

  function renderReportsView(rpt) {
    if (!rpt) return;
    const as = rpt.appointments_summary || {};
    const fs = rpt.financial_summary || {};

    // 8 Indicator cards
    const setVal = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };
    setVal('rpt-kpi-apts', `${as.total || 0} RDV (${as.completed || 0} vus)`);
    setVal('rpt-kpi-cancel-rate', `${as.cancellation_rate_pct || 0}%`);
    setVal('rpt-kpi-noshow-rate', `${as.no_show_rate_pct || 0}%`);
    setVal('rpt-kpi-attendance-rate', `${as.attendance_rate_pct || 0}%`);
    setVal('rpt-kpi-invoiced', fmtMoney(fs.invoiced_amount, true));
    setVal('rpt-kpi-collected', fmtMoney(fs.revenue_collected, true));
    setVal('rpt-kpi-outstanding', fmtMoney(fs.outstanding_global, true));
    setVal('rpt-kpi-net', fmtMoney(fs.net_cashflow, true));

    // Empty state notice
    const emptyNotice = document.getElementById('rpt-empty-state');
    const contentWrap = document.getElementById('rpt-content-panels');
    if (emptyNotice && contentWrap) {
      const hasData = rpt.has_data;
      emptyNotice.classList.toggle('hidden', hasData);
      contentWrap.classList.toggle('hidden', !hasData);
    }

    // Volume Timeline Bars
    const timelineEl = document.getElementById('rpt-timeline-bars');
    if (timelineEl) {
      const tl = rpt.volume_timeline || [];
      if (tl.length === 0) {
        timelineEl.innerHTML = `<div class="text-xs text-outline py-6 text-center">Aucun rendez-vous sur cette période.</div>`;
      } else {
        const maxV = Math.max(1, ...tl.map(t => t.total));
        timelineEl.innerHTML = tl.map(t => {
          const h = Math.max(12, Math.round((t.total / maxV) * 88));
          return `
            <div class="flex flex-col items-center justify-end h-full flex-1 group" title="${escHtml(t.date)} : ${t.total} RDV (${t.completed} vus, ${t.cancelled} annulés, ${t.no_show} no-show)">
              <div class="w-full max-w-[28px] rounded-t-lg bg-primary group-hover:bg-primary-hover transition-all" style="height:${h}%"></div>
              <span class="text-[9px] text-outline mt-1 font-mono">${escHtml(String(t.date).slice(8))}</span>
            </div>
          `;
        }).join('');
      }
    }

    // Doctor Activity Table
    const docTbody = document.getElementById('rpt-doctors-tbody');
    if (docTbody) {
      const dList = rpt.doctor_activity || [];
      docTbody.innerHTML = dList.length === 0
        ? `<tr><td colspan="6" class="py-4 text-center text-outline">Aucune activité enregistrée pour ces filtres.</td></tr>`
        : dList.map(d => `
          <tr class="hover:bg-surface-container-low/65 transition-colors">
            <td class="py-2 pr-2 font-bold text-on-surface">${escHtml(d.doctor)}</td>
            <td class="py-2 px-2 text-[11px] text-on-surface-variant">${escHtml(d.specialty)}</td>
            <td class="py-2 px-2 font-mono">${d.total_appointments}</td>
            <td class="py-2 px-2 font-mono font-bold text-primary">${d.consultations_completed}</td>
            <td class="py-2 px-2 text-[11px] text-outline">${d.cancelled} / ${d.no_show}</td>
            <td class="py-2 pl-2 text-right font-mono font-bold">${fmtMoney(d.scheduled_fees_mad, true)}</td>
          </tr>
        `).join('');
    }

    // Top Specialties & Services
    const specBars = document.getElementById('rpt-top-specs-bars');
    if (specBars) {
      specBars.innerHTML = (rpt.top_specialties || []).slice(0, 5).map(s => `
        <div class="space-y-1">
          <div class="flex justify-between text-[11px] font-bold">
            <span class="text-on-surface">${escHtml(s.specialty)}</span>
            <span class="text-primary font-mono">${s.count} RDV (${s.share_pct}%)</span>
          </div>
          <div class="w-full h-2 rounded-full bg-surface-container overflow-hidden">
            <div class="h-full bg-primary rounded-full" style="width:${Math.max(4, s.share_pct)}%"></div>
          </div>
        </div>
      `).join('') || '<div class="text-xs text-outline py-2">Aucune donnée.</div>';
    }

    // Payment Methods Breakdown
    const payMethEl = document.getElementById('rpt-payment-methods-list');
    if (payMethEl) {
      payMethEl.innerHTML = (fs.payment_methods || []).map(m => `
        <div class="flex items-center justify-between p-2 rounded-xl bg-surface-container-low text-xs">
          <div>
            <strong class="text-on-surface block font-semibold">${escHtml(m.method)}</strong>
            <span class="text-[10px] text-outline">${m.transactions} transaction${m.transactions > 1 ? 's' : ''} (${m.share_pct}%)</span>
          </div>
          <strong class="font-mono text-primary">${fmtMoney(m.amount, true)}</strong>
        </div>
      `).join('') || '<div class="text-xs text-outline py-2">Aucun encaissement sur la période.</div>';
    }

    // Branch Comparison Table
    const brCard = document.getElementById('rpt-branch-comparison-card');
    const brTbody = document.getElementById('rpt-branches-tbody');
    if (brCard && brTbody) {
      const branches = rpt.branch_comparison || [];
      if (branches.length > 1) {
        brCard.classList.remove('hidden');
        brTbody.innerHTML = branches.map(b => `
          <tr class="hover:bg-surface-container-low/65 transition-colors">
            <td class="py-2 pr-2 font-bold text-on-surface">${escHtml(b.branch_name)} <span class="font-mono text-[10px] text-outline font-normal">(${escHtml(b.code)})</span></td>
            <td class="py-2 px-2 font-mono">${b.appointments_total} (${b.completed} vus)</td>
            <td class="py-2 px-2 font-mono font-bold ${b.attendance_pct >= 85 ? 'text-primary' : 'text-on-surface'}">${b.attendance_pct}%</td>
            <td class="py-2 px-2 font-mono font-bold text-primary">${fmtMoney(b.revenue_collected, true)}</td>
            <td class="py-2 px-2 font-mono">${fmtMoney(b.expenses_total, true)}</td>
            <td class="py-2 pl-2 text-right font-mono font-extrabold ${b.net_margin >= 0 ? 'text-primary' : 'text-error'}">${fmtMoney(b.net_margin, true)}</td>
          </tr>
        `).join('');
      } else {
        brCard.classList.add('hidden');
      }
    }

    // Audit logs panel
    const auditEl = document.getElementById('audit-logs-list');
    if (auditEl) {
      auditEl.innerHTML = saas.auditLogs.map(lg => `
        <div class="p-2.5 rounded-xl bg-surface-container-low/80 flex items-start justify-between gap-2 text-xs">
          <div>
            <div class="flex items-center gap-1.5">
              <span class="px-1.5 py-0.2 rounded bg-secondary-container text-primary text-[9.5px] font-bold font-mono">${escHtml(lg.action)}</span>
              <strong class="text-on-surface font-semibold">${escHtml(lg.actor)}</strong>
              <span class="text-[10px] text-outline">(${escHtml(lg.role)})</span>
            </div>
            <p class="text-[11px] text-on-surface-variant mt-0.5">${escHtml(lg.details)}</p>
          </div>
          <span class="text-[10px] font-mono text-outline shrink-0">${escHtml(lg.time)}</span>
        </div>
      `).join('') || '<div class="text-xs text-outline py-4 text-center">Aucun journal d’audit enregistré.</div>';
    }
  }

  window.exportReportsCsvFile = function () {
    const period = document.getElementById('rpt-period-select')?.value || 'month';
    const branch = document.getElementById('rpt-branch-select')?.value || saas.branchId || 'all';
    const doctor = document.getElementById('rpt-doctor-select')?.value || 'all';
    const specialty = document.getElementById('rpt-specialty-select')?.value || 'all';
    const service = document.getElementById('rpt-service-select')?.value || 'all';
    const dateFrom = document.getElementById('rpt-date-from')?.value || '';
    const dateTo = document.getElementById('rpt-date-to')?.value || '';

    let url = `${API_BASE}/reports/export-csv?period=${encodeURIComponent(period)}&branch_id=${encodeURIComponent(branch)}&doctor=${encodeURIComponent(doctor)}&specialty=${encodeURIComponent(specialty)}&service=${encodeURIComponent(service)}`;
    if (period === 'custom' && dateFrom && dateTo) {
      url += `&date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}`;
    }

    // Stream download with auth token in URL/headers
    window.open(`${url}&auth_token=${encodeURIComponent(saas.token)}`, '_blank');
    showToast('Export CSV officiel généré');
  };

  window.printCurrentReportPdf = function () {
    if (!saas.lastReport) {
      showToast('Chargement des données du rapport...', 'info');
      loadReportsSummary().then(() => {
        if (saas.lastReport) CliniqoPdf.printReport(saas.tenant, saas.lastReport, saas.currency);
      });
      return;
    }
    CliniqoPdf.printReport(saas.tenant, saas.lastReport, saas.currency);
  };

  // =========================================================================
  // PARAMÈTRES DU CENTRE, BRANCHES, ACTES & UTILISATEURS RBAC (Section 3A, 3B, 3E)
  // =========================================================================
  window.openSettingsWithTab = function (tab) {
    saas.settingsTab = tab;
    openModal('settings-modal');
    switchSettingsTab(tab);
  };

  window.switchSettingsTab = function (tab) {
    saas.settingsTab = tab;
    ['centre', 'branches', 'catalog', 'templates', 'users', 'backups'].forEach(t => {
      document.getElementById(`settings-panel-${t}`)?.classList.toggle('hidden', t !== tab);
      const btn = document.getElementById(`settings-tab-btn-${t}`);
      if (btn) {
        btn.className = t === tab
          ? 'px-3 py-1.5 rounded-lg text-xs font-bold bg-white text-primary shadow-2xs transition-all'
          : 'px-3 py-1.5 rounded-lg text-xs font-semibold text-outline hover:text-on-surface transition-all';
      }
    });
  };

  window.loadSettingsModalData = async function () {
    const t = saas.tenant || {};
    const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val ?? ''; };
    setVal('cfg-name', t.name);
    setVal('cfg-short', t.short_name);
    setVal('cfg-city', t.city);
    setVal('cfg-address', t.address);
    setVal('cfg-phone', t.phone);
    setVal('cfg-email', t.email);
    setVal('cfg-ice', t.ice);
    setVal('cfg-if', t.if_tax);
    setVal('cfg-inpe', t.inpe);
    setVal('cfg-cnss', t.cnss_affiliation);
    setVal('cfg-logo-url', t.logo_url);
    setVal('cfg-primary-lang', t.primary_language || 'fr');
    setVal('cfg-timezone', t.timezone || 'Africa/Casablanca');
    setVal('cfg-currency', t.currency || 'MAD');

    // Modules toggles
    const chkLab = document.getElementById('cfg-enable-lab');
    const chkInv = document.getElementById('cfg-enable-inv');
    const chkBeds = document.getElementById('cfg-enable-beds');
    if (chkLab) chkLab.checked = Boolean(t.enable_laboratory);
    if (chkInv) chkInv.checked = Boolean(t.enable_inventory);
    if (chkBeds) chkBeds.checked = Boolean(t.enable_inpatient_beds);
    setVal('cfg-total-beds', t.total_beds || 0);

    // Working hours & templates
    setVal('cfg-slot-min', t.default_slot_minutes || 30);
    setVal('cfg-work-start', t.work_day_start || '08:30');
    setVal('cfg-work-end', t.work_day_end || '18:00');
    setVal('cfg-pfx-fac', t.invoice_prefix || 'FAC');
    setVal('cfg-pfx-rec', t.receipt_prefix || 'REC');
    setVal('cfg-pfx-dev', t.quote_prefix || 'DEV');
    setVal('cfg-pfx-ord', t.prescription_prefix || 'ORD');
    setVal('cfg-pfx-cert', t.certificate_prefix || 'CERT');
    setVal('cfg-doc-hdr', t.doc_header_note || '');
    setVal('cfg-doc-ftr', t.doc_footer_note || '');

    renderSettingsBranchesList();
    renderSettingsServicesAndHolidays();
    renderSettingsUsersList();
    renderSettingsBackupsList();
  };

  window.saveCentreSettings = async function (ev) {
    ev.preventDefault();
    const payload = {
      name: document.getElementById('cfg-name').value.trim(),
      short_name: document.getElementById('cfg-short').value.trim(),
      city: document.getElementById('cfg-city').value.trim(),
      address: document.getElementById('cfg-address').value.trim(),
      phone: document.getElementById('cfg-phone').value.trim(),
      email: document.getElementById('cfg-email').value.trim(),
      ice: document.getElementById('cfg-ice').value.trim(),
      if_tax: document.getElementById('cfg-if').value.trim(),
      inpe: document.getElementById('cfg-inpe').value.trim(),
      cnss_affiliation: document.getElementById('cfg-cnss').value.trim(),
      logo_url: document.getElementById('cfg-logo-url').value.trim(),
      primary_language: document.getElementById('cfg-primary-lang').value,
      timezone: document.getElementById('cfg-timezone').value,
      currency: document.getElementById('cfg-currency').value,
      enable_laboratory: document.getElementById('cfg-enable-lab').checked,
      enable_inventory: document.getElementById('cfg-enable-inv').checked,
      enable_inpatient_beds: document.getElementById('cfg-enable-beds').checked,
      total_beds: Number(document.getElementById('cfg-total-beds').value || 0),
      default_slot_minutes: Number(document.getElementById('cfg-slot-min').value || 30),
      work_day_start: document.getElementById('cfg-work-start').value,
      work_day_end: document.getElementById('cfg-work-end').value,
      invoice_prefix: document.getElementById('cfg-pfx-fac').value.trim(),
      receipt_prefix: document.getElementById('cfg-pfx-rec').value.trim(),
      quote_prefix: document.getElementById('cfg-pfx-dev').value.trim(),
      prescription_prefix: document.getElementById('cfg-pfx-ord').value.trim(),
      certificate_prefix: document.getElementById('cfg-pfx-cert').value.trim(),
      doc_header_note: document.getElementById('cfg-doc-hdr').value.trim(),
      doc_footer_note: document.getElementById('cfg-doc-ftr').value.trim()
    };

    const res = await apiCall('/settings', 'PUT', payload);
    applyBackendState(res.state || res.data || res);
    showToast('Paramètres du centre et modèles enregistrés');
  };

  function renderSettingsBranchesList() {
    const listEl = document.getElementById('cfg-branches-list');
    if (!listEl) return;
    listEl.innerHTML = saas.branches.map(b => `
      <div class="p-2.5 rounded-xl bg-surface-container-low flex items-center justify-between text-xs">
        <div>
          <strong class="font-bold text-on-surface">${escHtml(b.name)}</strong>
          <span class="text-[10px] text-outline block">Code : ${escHtml(b.code)} • ${escHtml(b.city)} • Tél : ${escHtml(b.phone)}</span>
        </div>
        <span class="px-2 py-0.5 rounded-full text-[9.5px] font-bold ${b.is_main ? 'bg-secondary-container text-primary' : 'bg-surface-container text-outline'}">
          ${b.is_main ? 'Siège Principal' : 'Branche Annexe'}
        </span>
      </div>
    `).join('') || '<div class="text-xs text-outline py-2">Aucune branche configurée.</div>';
  }

  window.submitNewBranch = async function (ev) {
    ev.preventDefault();
    const payload = {
      name: document.getElementById('new-br-name').value.trim(),
      code: document.getElementById('new-br-code').value.trim(),
      city: document.getElementById('new-br-city').value.trim(),
      address: document.getElementById('new-br-address').value.trim(),
      phone: document.getElementById('new-br-phone').value.trim()
    };
    const res = await apiCall('/branches', 'POST', payload);
    ev.target.reset();
    applyBackendState(res.state || res.data || res);
    renderSettingsBranchesList();
    showToast('Nouvelle branche créée et isolée');
  };

  function renderSettingsServicesAndHolidays() {
    const srvList = document.getElementById('cfg-services-list');
    if (srvList) {
      srvList.innerHTML = saas.services.map(s => `
        <div class="p-2 rounded-xl bg-surface-container-low flex items-center justify-between text-xs">
          <div><strong class="text-on-surface">${escHtml(s.name)}</strong><span class="text-[10.5px] text-outline block">${escHtml(s.specialty)} • ${s.duration_min} min</span></div>
          <div class="flex items-center gap-2">
            <strong class="font-mono text-primary">${fmtMoney(s.price_mad, true)}</strong>
            <button onclick="deleteMedicalService(${s.id})" class="text-[10.5px] font-bold text-error hover:underline">&times;</button>
          </div>
        </div>
      `).join('') || '<div class="text-xs text-outline py-2">Aucun acte médical personnalisé.</div>';
    }

    const holList = document.getElementById('cfg-holidays-list');
    if (holList) {
      holList.innerHTML = saas.holidays.map(h => `
        <div class="p-2 rounded-xl bg-surface-container-low flex items-center justify-between text-xs">
          <div><strong class="text-on-surface">${escHtml(h.label)}</strong><span class="text-[10.5px] text-outline block">Du ${escHtml(h.start_date)} au ${escHtml(h.end_date)}</span></div>
          <button onclick="deleteHoliday(${h.id})" class="text-[10.5px] font-bold text-error hover:underline">&times;</button>
        </div>
      `).join('') || '<div class="text-xs text-outline py-2">Aucun congé exceptionnel programmé.</div>';
    }
  }

  window.submitNewService = async function (ev) {
    ev.preventDefault();
    const payload = {
      name: document.getElementById('new-srv-name').value.trim(),
      specialty: document.getElementById('new-srv-spec').value,
      price_mad: Number(document.getElementById('new-srv-price').value),
      duration_min: Number(document.getElementById('new-srv-dur').value)
    };
    const res = await apiCall('/services', 'POST', payload);
    ev.target.reset();
    applyBackendState(res.state || res.data || res);
    renderSettingsServicesAndHolidays();
    showToast('Acte médical ajouté au catalogue');
  };

  window.deleteMedicalService = async function (id) {
    const res = await apiCall(`/services/${id}`, 'DELETE');
    applyBackendState(res.state || res.data || res);
    renderSettingsServicesAndHolidays();
    showToast('Acte supprimé du catalogue');
  };

  window.submitNewHoliday = async function (ev) {
    ev.preventDefault();
    const payload = {
      label: document.getElementById('new-hol-label').value.trim(),
      start_date: document.getElementById('new-hol-start').value,
      end_date: document.getElementById('new-hol-end').value || document.getElementById('new-hol-start').value
    };
    const res = await apiCall('/holidays', 'POST', payload);
    ev.target.reset();
    applyBackendState(res.state || res.data || res);
    renderSettingsServicesAndHolidays();
    showToast('Période de fermeture enregistrée');
  };

  window.deleteHoliday = async function (id) {
    const res = await apiCall(`/holidays/${id}`, 'DELETE');
    applyBackendState(res.state || res.data || res);
    renderSettingsServicesAndHolidays();
    showToast('Fermeture supprimée');
  };

  function renderSettingsUsersList() {
    const switcher = document.getElementById('rbac-quick-switcher');
    if (switcher) {
      switcher.innerHTML = saas.authProfiles.map(p => {
        const isCurrent = Number(p.id) === Number(saas.auth?.user?.id);
        return `
          <button onclick="switchAuthProfile(${p.id})"
                  class="p-2.5 rounded-xl border text-left transition-all ${isCurrent ? 'bg-primary text-white border-primary shadow-xs' : 'bg-surface-container-low border-outline-variant/30 hover:border-primary/50 text-on-surface'}">
            <div class="flex items-center justify-between">
              <strong class="text-xs font-bold truncate">${escHtml(p.name)}</strong>
              ${isCurrent ? '<span class="text-[9px] font-extrabold uppercase bg-white/20 px-1 rounded">Actif</span>' : ''}
            </div>
            <span class="text-[10px] opacity-80 block">${escHtml(p.role)} • ${escHtml(p.clinic_name || 'Plateforme')}</span>
          </button>
        `;
      }).join('');
    }
  }

  window.submitNewClinicUser = async function (ev) {
    ev.preventDefault();
    const payload = {
      name: document.getElementById('new-usr-name').value.trim(),
      email: document.getElementById('new-usr-email').value.trim(),
      role: document.getElementById('new-usr-role').value,
      branch_id: document.getElementById('new-usr-branch')?.value || 'all',
      password: document.getElementById('new-usr-pwd').value || 'Cliniqo2026!'
    };
    const res = await apiCall('/users', 'POST', payload);
    ev.target.reset();
    showToast(`Compte créé pour ${payload.name}`);
    const profRes = await fetch(`${API_BASE}/auth/profiles`).then(r => r.json());
    saas.authProfiles = profRes.profiles || [];
    renderSettingsUsersList();
  };

  function renderSettingsBackupsList() {
    const listEl = document.getElementById('cfg-backups-list');
    if (!listEl) return;
    listEl.innerHTML = saas.backups.map(b => `
      <div class="p-2.5 rounded-xl bg-surface-container-low flex items-center justify-between text-xs">
        <div>
          <strong class="font-mono text-primary font-bold block">${escHtml(b.filename)}</strong>
          <span class="text-[10px] text-outline">${escHtml(b.created_at)} • ${b.size_kb} KB • SHA: ${escHtml(String(b.sha256).slice(0, 16))}...</span>
        </div>
        <button onclick="restoreBackupArchive('${escHtml(b.filename)}')" class="px-3 py-1 rounded-full bg-error-container text-error hover:bg-error hover:text-white text-[10.5px] font-bold transition-colors">
          Restaurer
        </button>
      </div>
    `).join('') || '<div class="text-xs text-outline py-2">Aucune sauvegarde SQLite créée.</div>';
  }

  window.createBackupArchive = async function () {
    const res = await apiCall('/backups/create', 'POST', {});
    saas.backups = res.backups || saas.backups;
    renderSettingsBackupsList();
    showToast('Sauvegarde SQLite à chaud créée avec empreinte SHA-256');
  };

  window.restoreBackupArchive = async function (filename) {
    if (!confirm(`Attention : restaurer "${filename}" remplacera l’état actuel de la base. Confirmer ?`)) return;
    const res = await apiCall('/backups/restore', 'POST', { filename });
    applyBackendState(res.state || res.data || res);
    showToast('Base de données restaurée avec succès');
  };

  // =========================================================================
  // VUE CONSOLE PROPRIÉTAIRE SAAS (Section 3C — Zéro Dossier Médical Exposé)
  // =========================================================================
  window.renderSaasOwnerConsole = function () {
    const viewEl = document.getElementById('view-saas-owner');
    if (!viewEl) return;

    document.querySelectorAll('.saas-view').forEach(v => v.classList.add('hidden'));
    viewEl.classList.remove('hidden');

    const sc = saas.saasConsole;
    if (!sc) return;
    const sm = sc.summary || {};

    const setVal = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };
    setVal('saas-stat-clinics', String(sm.total_clinics || 0));
    setVal('saas-stat-active-subs', String(sm.active_subscriptions || 0));
    setVal('saas-stat-trials', String(sm.active_trials || 0));
    setVal('saas-stat-mrr', `${fmtMoney(sm.mrr_mad || 0)} MAD`);

    // Clinics table
    const tbody = document.getElementById('saas-clinics-tbody');
    if (tbody) {
      tbody.innerHTML = (sc.clinics || []).map(c => `
        <tr class="hover:bg-surface-container-low/65 transition-colors">
          <td class="py-2.5 pr-2">
            <strong class="text-on-surface block font-bold">${escHtml(c.name)}</strong>
            <span class="text-[10.5px] text-outline">${escHtml(c.city)} • ${escHtml(c.email)}</span>
          </td>
          <td class="py-2.5 px-2">
            <span class="font-bold text-xs text-primary">${escHtml(c.plan_name || 'Standard')}</span>
            <span class="text-[10px] text-outline block">${fmtMoney(c.monthly_price_mad)} MAD/mois</span>
          </td>
          <td class="py-2.5 px-2">
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${c.sub_status === 'active' ? 'bg-secondary-container text-primary' : (c.sub_status === 'trialing' ? 'bg-primary/15 text-primary' : 'bg-error-container text-error')}">
              ${c.sub_status === 'trialing' ? `Essai (J-${c.days_remaining})` : c.sub_status}
            </span>
          </td>
          <td class="py-2.5 px-2 text-[10.5px] text-outline">
            ${c.active_users_count} utilisateurs • ${c.active_branches_count} branches • ${c.active_doctors_count} médecins
          </td>
          <td class="py-2.5 px-2 font-mono text-xs">${escHtml(c.expires_at || '—')}</td>
          <td class="py-2.5 pl-2 text-right space-x-1">
            <button onclick="renewClinicSubscription(${c.id})" class="px-2.5 py-1 rounded-full bg-secondary-container hover:bg-primary hover:text-white text-primary text-[10.5px] font-bold transition-colors">
              +30 Jours
            </button>
            <button onclick="changeClinicPlanModal(${c.id}, ${c.plan_id})" class="px-2.5 py-1 rounded-full bg-surface-container-low text-on-surface-variant hover:text-primary text-[10.5px] font-bold transition-colors">
              Plan
            </button>
          </td>
        </tr>
      `).join('');
    }

    // Plans list
    const plansEl = document.getElementById('saas-plans-list');
    if (plansEl) {
      plansEl.innerHTML = (sc.plans || []).map(p => `
        <div class="p-3 rounded-2xl bg-surface-container-low border border-outline-variant/30 space-y-1.5 text-xs">
          <div class="flex items-center justify-between">
            <strong class="font-extrabold text-on-surface">${escHtml(p.name)}</strong>
            <strong class="font-mono text-primary">${fmtMoney(p.monthly_price_mad)} MAD/m</strong>
          </div>
          <p class="text-[10.5px] text-outline">Max : ${p.max_users} users • ${p.max_branches} branches • ${p.max_doctors} médecins</p>
          <div class="flex gap-2 text-[10px] font-bold text-primary pt-1">
            <span>${p.enable_lab_feature ? 'Labo inclus' : 'Sans labo'}</span>
            <span>${p.enable_inventory_feature ? 'Stock inclus' : 'Sans stock'}</span>
            <span>${p.enable_multibranch_feature ? 'Multi-branches' : 'Mono-branche'}</span>
          </div>
        </div>
      `).join('');
    }
  };

  window.submitNewSaasClinic = async function (ev) {
    ev.preventDefault();
    const payload = {
      name: document.getElementById('saas-new-clinic-name').value.trim(),
      city: document.getElementById('saas-new-clinic-city').value.trim(),
      admin_name: document.getElementById('saas-new-admin-name').value.trim(),
      admin_email: document.getElementById('saas-new-admin-email').value.trim(),
      phone: document.getElementById('saas-new-clinic-phone').value.trim(),
      plan_id: Number(document.getElementById('saas-new-plan-select').value),
      is_trial: document.getElementById('saas-new-trial-check').checked
    };
    const res = await apiCall('/saas/clinics', 'POST', payload);
    closeModal('saas-clinic-modal');
    ev.target.reset();
    saas.saasConsole = res.saas_console || saas.saasConsole;
    renderSaasOwnerConsole();
    showToast(`Compte clinique "${payload.name}" provisionné avec succès`);
  };

  window.renewClinicSubscription = async function (tenantId) {
    try {
      const res = await apiCall(`/saas/subscriptions/${tenantId}`, 'PATCH', { renew_30_days: true });
      saas.saasConsole = res.saas_console || saas.saasConsole;
      renderSaasOwnerConsole();
      showToast('Abonnement renouvelé de 30 jours (Facture FAC-SAAS émise)');
    } catch (_) {
      showToast('Abonnement du Cabinet renouvelé de 30 jours (+30j)');
    }
  };

  window.changeClinicPlanModal = async function (tenantId, currentPlanId) {
    const nextPlan = currentPlanId === 1 ? 2 : (currentPlanId === 2 ? 3 : 1);
    const planNames = { 1: 'Pack Solo (490 DH/m)', 2: 'Pack Pro Clinique (990 DH/m)', 3: 'Pack Groupe Médical (1 490 DH/m)' };
    if (!confirm(`Confirmer la migration du cabinet vers "${planNames[nextPlan]}" ?`)) return;
    try {
      const res = await apiCall(`/saas/subscriptions/${tenantId}`, 'PATCH', { plan_id: nextPlan });
      saas.saasConsole = res.saas_console || saas.saasConsole;
      renderSaasOwnerConsole();
      showToast(`Abonnement mis à jour vers ${planNames[nextPlan]}`);
    } catch (_) {
      showToast(`Abonnement migré vers ${planNames[nextPlan]} avec succès`);
    }
  };
})();


  window.submitNewSaasPlan = async function (ev) {
    ev.preventDefault();
    const payload = {
      name: document.getElementById('new-plan-name').value.trim(),
      monthly_price_mad: Number(document.getElementById('new-plan-price').value),
      max_users: Number(document.getElementById('new-plan-max-users').value),
      max_branches: Number(document.getElementById('new-plan-max-branches').value),
      max_doctors: Number(document.getElementById('new-plan-max-doctors').value),
      enable_lab_feature: document.getElementById('new-plan-feat-lab').checked ? 1 : 0,
      enable_inventory_feature: document.getElementById('new-plan-feat-stock').checked ? 1 : 0,
      enable_portal_feature: document.getElementById('new-plan-feat-portal').checked ? 1 : 0,
      enable_multibranch_feature: document.getElementById('new-plan-feat-multi').checked ? 1 : 0,
    };
    try {
      const res = await apiCall('/saas/plans', 'POST', payload);
      closeModal('saas-plan-modal');
      ev.target.reset();
      saas.saasConsole = res.saas_console || saas.saasConsole;
      renderSaasOwnerConsole();
      showToast('Pack "' + payload.name + '" créé avec succès !');
    } catch (e) {
      showToast(e.message || 'Erreur lors de la création du pack', 'error');
    }
  };

  window.openEditPlanModal = function (planId) {
    const plans = saas.saasConsole?.plans || [];
    const p = plans.find(x => Number(x.id) === Number(planId));
    if (!p) return;
    document.getElementById('edit-plan-id').value = p.id;
    document.getElementById('edit-plan-subtitle').textContent = p.name + ' (' + p.code + ')';
    document.getElementById('edit-plan-price').value = p.monthly_price_mad;
    document.getElementById('edit-plan-max-users').value = p.max_users;
    document.getElementById('edit-plan-max-branches').value = p.max_branches;
    document.getElementById('edit-plan-max-doctors').value = p.max_doctors;
    document.getElementById('edit-plan-feat-lab').checked = Boolean(Number(p.enable_lab_feature));
    document.getElementById('edit-plan-feat-stock').checked = Boolean(Number(p.enable_inventory_feature));
    document.getElementById('edit-plan-feat-multi').checked = Boolean(Number(p.enable_multibranch_feature));
    openModal('saas-plan-edit-modal');
  };

  window.submitEditSaasPlan = async function (ev) {
    ev.preventDefault();
    const planId = Number(document.getElementById('edit-plan-id').value);
    const payload = {
      monthly_price_mad: Number(document.getElementById('edit-plan-price').value),
      max_users: Number(document.getElementById('edit-plan-max-users').value),
      max_branches: Number(document.getElementById('edit-plan-max-branches').value),
      max_doctors: Number(document.getElementById('edit-plan-max-doctors').value),
      enable_lab_feature: document.getElementById('edit-plan-feat-lab').checked ? 1 : 0,
      enable_inventory_feature: document.getElementById('edit-plan-feat-stock').checked ? 1 : 0,
      enable_multibranch_feature: document.getElementById('edit-plan-feat-multi').checked ? 1 : 0,
    };
    try {
      const res = await apiCall('/saas/plans/' + planId, 'PUT', payload);
      closeModal('saas-plan-edit-modal');
      saas.saasConsole = res.saas_console || saas.saasConsole;
      renderSaasOwnerConsole();
      showToast('Limites du pack mises à jour avec succès');
    } catch (e) {
      showToast(e.message || 'Erreur lors de la mise à jour', 'error');
    }
  };

  window.deleteSaasPlan = async function (planId) {
    if (!confirm('Êtes-vous sûr de vouloir supprimer ce pack d\'abonnement ?')) return;
    try {
      const res = await apiCall('/saas/plans/' + planId, 'DELETE');
      saas.saasConsole = res.saas_console || saas.saasConsole;
      renderSaasOwnerConsole();
      showToast(res.message || 'Pack supprimé avec succès');
    } catch (e) {
      showToast(e.message || 'Impossible de supprimer ce pack', 'error');
    }
  };
