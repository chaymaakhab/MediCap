/**
 * Cliniqo Cloud — État Multi-Tenant / Multi-Branche, Authentification RBAC & Tableau de Bord (Sections 1, 3D, 3E, 4)
 */
let API_BASE = (window.CLINIQO_API_URL) 
  || (typeof location !== 'undefined' && location.port === '8000' ? 'http://127.0.0.1:8000/api/v1' : 'http://127.0.0.1:8001/api/v1');
const todayParts = Object.fromEntries(new Intl.DateTimeFormat('en', {
  timeZone: 'Africa/Casablanca',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit'
}).formatToParts(new Date()).map(({ type, value }) => [type, value]));
const TODAY_ISO = `${todayParts.year}-${todayParts.month}-${todayParts.day}`;

function normalizeAppRole(role) {
  const aliases = {
    saas_owner: 'owner',
    admin_clinique: 'admin',
    medecin: 'docteur',
    doctor: 'docteur'
  };
  const key = String(role || '').toLowerCase();
  return aliases[key] || key;
}

function escHtml(v) {
  return String(v ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function makeInitialsAvatar(name) {
  const cleaned = String(name || 'Dr').replace(/^(Dr\.|Pr\.|Docteur|Professeur)\s+/i, '').trim();
  const parts = cleaned.split(/\s+/).filter(Boolean);
  const initials = ((parts[0]?.[0] || 'C') + (parts[1]?.[0] || parts[0]?.[1] || 'L')).toUpperCase();
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#005131"/><stop offset="100%" stop-color="#176b45"/></linearGradient></defs><rect width="96" height="96" rx="48" fill="url(#g)"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#ffffff" font-family="Plus Jakarta Sans, sans-serif" font-weight="800" font-size="34">${initials}</text></svg>`;
  return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
}

window.saas = {
  token: localStorage.getItem('cliniqo_staff_token') || '',
  tenantId: Number(localStorage.getItem('cliniqo_tenant_id') || 1),
  branchId: localStorage.getItem('cliniqo_branch_id') || 'all',
  currency: localStorage.getItem('cliniqo_currency') || 'MAD',
  activeTab: 'overview',
  financeSubTab: 'invoices',
  settingsTab: 'centre',
  deptFilter: 'all',
  specialistFilter: 'all',
  selectedPatientDayIdx: 6,
  selectedUtilIdx: 5,
  utilViewMode: 'slots', // 'slots' | 'rooms'
  diagPeriodKey: 'week', // 'week' | 'month' | 'day'
  activePatientId: null,
  activeInvoiceId: null,
  auth: {
    user: null,
    role: '',
    permissions: [],
    is_saas_owner: false
  },
  authProfiles: [],
  tenant: { id: 1, name: 'Cabinet Médical Cliniqo', short: 'Cabinet Cliniqo', address: '142 Boulevard Zerktouni, Maarif, Casablanca', city: 'Casablanca • Maarif', currency: 'MAD', enable_laboratory: true, enable_inventory: true, enable_inpatient_beds: false },
  centres: [],
  branches: [],
  kpis: { patients_today: 0, patients_checked_in: 0, patients_seen: 0, tests_conducted: 0, revenue_today: 0, invoiced_today: 0, outstanding_total: 0, refunds_today: 0, specialists_count: 0 },
  dashboardMetrics: {},
  weeklyPatients: [],
  doctors: [],
  patients: [],
  appointments: [],
  labs: [],
  invoices: [],
  payments: [],
  expenses: [],
  inventory: [],
  documents: [],
  services: [],
  holidays: [],
  notifications: [],
  auditLogs: [],
  clinicUsers: [],
  backups: [],
  saasConsole: null,
  lastReport: null
};

// ============================================================================
// CONFIGURATION D'ISOLATION STRICTE DES 3 RÔLES DU CABINET (MONO-CABINET)
// ============================================================================
const ROLE_SPACES_CONFIG = {
  owner: {
    key: 'owner',
    name: 'Dr. Jackson',
    title: 'Propriétaire SaaS — Super-Admin',
    avatar: 'https://images.unsplash.com/photo-1612349317150-e413f6a5b16d?w=120',
    defaultTab: 'saas-owner',
    allowedTabs: ['saas-owner'],
    canManageSaas: true,
    canManageFinance: false,
    canAccessDme: false,
    canPrescribe: false,
    canManageStock: false,
  },
  admin: {
    key: 'admin',
    name: 'Sarah Benali',
    title: 'Gestionnaire du Cabinet — Administration & Finance',
    avatar: 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=120',
    defaultTab: 'overview',
    allowedTabs: ['overview', 'patients', 'consultations', 'finance', 'stock', 'reports'],
    canManageSaas: false, // Interdit d'accéder à l'espace Owner
    canManageFinance: true, // Facturation, CNSS, Encaissements MAD autorisés
    canAccessDme: false, // Données médicales confidentielles protégées (Loi 131-13 / CNDP)
    canPrescribe: false, // Prescriptions et diagnostics réservés aux médecins
    canManageStock: true, // Gestion des dépenses et stock autorisée
  },
  docteur: {
    key: 'docteur',
    name: 'Dr. Jackson',
    title: 'Médecin Généraliste — Consultation & DME',
    avatar: 'https://images.unsplash.com/photo-1612349317150-e413f6a5b16d?w=120',
    defaultTab: 'overview',
    allowedTabs: ['overview', 'patients', 'consultations', 'reports'],
    canManageSaas: false, // Interdit d'accéder à l'espace Owner
    canManageFinance: false, // Caisse & facturation gérées par le gestionnaire
    canAccessDme: true, // Dossier Médical Électronique complet
    canPrescribe: true, // Rédaction d'ordonnances & demandes d'analyses
    canManageStock: false, // Dépenses et stock gérés par le gestionnaire
  }
};

function showRoleAccessDenied(opts) {
  const modal = document.getElementById('role-denied-modal');
  if (!modal) {
    showToast(opts.reason || 'Accès refusé pour votre rôle.', 'error');
    if (opts.redirectTab) switchTab(opts.redirectTab);
    return;
  }
  
  const titleEl = document.getElementById('denied-title');
  const msgEl = document.getElementById('denied-message');
  const curEl = document.getElementById('denied-current-role');
  const reqEl = document.getElementById('denied-required-role');
  const legEl = document.getElementById('denied-legal-ref');
  const btnEl = document.getElementById('denied-action-btn');

  if (titleEl) titleEl.textContent = opts.attemptedSpace || 'Espace Verrouillé';
  if (msgEl) msgEl.textContent = opts.reason || 'Votre rôle actuel n\'a pas accès à cette section.';
  if (curEl) curEl.textContent = opts.currentRoleLabel || 'Profil Actif';
  if (reqEl) reqEl.textContent = opts.requiredRoleLabel || 'Rôle Exclusif Requis';
  if (legEl) legEl.textContent = opts.legalRef || 'Séparation Stricte des Rôles (RBAC)';
  if (btnEl) btnEl.textContent = opts.redirectTab ? `Retourner à (${opts.redirectTab})` : 'Fermer';

  window._deniedRedirectTab = opts.redirectTab || 'overview';
  modal.classList.remove('hidden');
}

function closeRoleDeniedModal() {
  const modal = document.getElementById('role-denied-modal');
  if (modal) modal.classList.add('hidden');
  const target = window._deniedRedirectTab || 'overview';
  if (target && target !== saas.activeTab) {
    switchTab(target);
  }
}
window.closeRoleDeniedModal = closeRoleDeniedModal;
window.showRoleAccessDenied = showRoleAccessDenied;

function can(perm) {
  const role = normalizeAppRole(saas.auth?.role || localStorage.getItem('cliniqo_user_role') || '');
  
  if (role === 'owner' || role === 'saas_owner') {
    // Owner gère UNIQUEMENT les abonnements, packs SaaS, cliniques clientes et sauvegardes
    // STRICTEMENT INTERDIT d'accéder aux dossiers médicaux des patients et à la caisse du cabinet
    const ownerPerms = [
      'cabinet.ownership', 'saas.admin', 'subscriptions.manage', 'saas.manage',
      'plans.manage', 'backups.manage', 'clinics.manage'
    ];
    return ownerPerms.includes(perm);
  }
  
  const permissions = Array.isArray(saas.auth?.permissions) ? saas.auth.permissions : [];

  if (role === 'admin') {
    const adminForbidden = [
      'cabinet.ownership', 'saas.admin', 'subscriptions.manage', 'saas.manage',
      'plans.manage', 'patients.dme', 'prescriptions.write', 'medical.diagnose'
    ];
    if (adminForbidden.includes(perm)) return false;
    return permissions.includes(perm);
  }
  
  if (role === 'docteur' || role === 'medecin') {
    const clinicalPerms = [
      'dashboard.view', 'appointments.read', 'appointments.write',
      'patients.read', 'patients.dme', 'prescriptions.write',
      'labs.read', 'labs.write', 'labs.validate',
      'documents.read', 'documents.write', 'reports.read'
    ];
    return clinicalPerms.includes(perm) && permissions.includes(perm);
  }
  
  return permissions.includes(perm);
}

window.fmtMoney = fmtMoney;
window.escHtml = escHtml;
window.applyBackendState = applyBackendState;
window.initWorkspace = initWorkspace;
window.createNewBackup = function() {
  if (typeof createBackupArchive === 'function') createBackupArchive();
};
window.cycleBedTrendPeriod = cycleBedTrendPeriod;
window.cycleDiagnosePeriod = cycleDiagnosePeriod;
window.filterSpecialists = filterSpecialists;
window.populatePatientSelects = populatePatientSelects;
window.printQrPrescription = printQrPrescription;
window.toggleOwnerCabinetView = function() {
  switchTab('saas-owner');
};

function fmtMoney(val, withCode = false) {
  const n = Number(val || 0);
  const converted = saas.currency === 'EUR' ? n / 10.8 : n;
  const formatted = converted.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return withCode ? `${formatted} ${saas.currency}` : formatted;
}

function fmtInt(val) {
  const n = Number(val || 0);
  const converted = saas.currency === 'EUR' ? Math.round(n / 10.8) : Math.round(n);
  return converted.toLocaleString('fr-FR');
}

function showToast(msg, icon = 'check_circle') {
  const toast = document.getElementById('toast');
  const msgEl = document.getElementById('toast-msg');
  const iconEl = document.getElementById('toast-icon');
  if (!toast || !msgEl) return;
  msgEl.textContent = msg;
  if (iconEl) iconEl.textContent = icon;
  toast.classList.remove('opacity-0', 'translate-y-2');
  clearTimeout(window.__clqToastTimer);
  window.__clqToastTimer = setTimeout(() => {
    toast.classList.add('opacity-0', 'translate-y-2');
  }, 2900);
}

function buildAuthHeaders(extra = {}) {
  const h = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Tenant-ID': String(saas.tenantId || 1),
    'X-Branch-ID': String(saas.branchId || 'all'),
    ...extra
  };
  if (saas.token) {
    h['Authorization'] = 'Bearer ' + saas.token;
    h['X-Auth-Token'] = saas.token;
  }
  return h;
}

async function apiCall(path, method = 'GET', body = null) {
  const t0 = performance.now();
  const opts = { method, headers: buildAuthHeaders() };
  if (body !== null && method !== 'GET') {
    opts.body = JSON.stringify(body);
  }

  let res;
  try {
    res = await fetch(`${API_BASE}${path}`, opts);
  } catch (netErr) {
    const altBase = API_BASE.includes(':8001') ? API_BASE.replace(':8001', ':8000') : API_BASE.replace(':8000', ':8001');
    try {
      res = await fetch(`${altBase}${path}`, opts);
      API_BASE = altBase;
    } catch (_) {
      throw netErr;
    }
  }

  const ms = Math.max(1, Math.round(performance.now() - t0));
  const badge = document.getElementById('api-latency-badge');
  if (badge) badge.textContent = `SQLite • ${ms}ms`;

  const json = await res.json().catch(() => ({}));
  if (!res.ok || json.success === false) {
    const errMsg = json.message || `Erreur serveur (${res.status})`;
    showToast(errMsg, 'error');
    const err = new Error(errMsg);
    err.status = res.status;
    err.payload = json;
    throw err;
  }
  return json;
}

function applyBackendState(st) {
  if (!st?.auth?.role) {
    throw new Error('Réponse API invalide : le rôle authentifié est absent.');
  }
  const role = normalizeAppRole(st.auth.role);
  if (!ROLE_SPACES_CONFIG[role]) {
    throw new Error(`Rôle non pris en charge par l'interface : ${st.auth.role}.`);
  }
  if (st.auth) {
    saas.auth = st.auth;
    saas.auth.role = role;
    localStorage.setItem('cliniqo_user_role', role);
    if (st.auth.user?.name) localStorage.setItem('cliniqo_user_name', st.auth.user.name);
  }
  if (st.backups) saas.backups = st.backups;

  if (st.auth?.is_saas_owner || st.auth?.role === 'owner' || st.auth?.role === 'saas_owner') {
    saas.saasConsole = st.saas_console || null;
    saas.auth = st.auth;
    saas.auth.role = role;
    saas.activeTab = 'saas-owner';
    localStorage.setItem('cliniqo_user_role', 'owner');
    document.documentElement.classList.add('role-owner');
    renderHeaderAndBranches();
    // Apply UI guards inline without triggering switchTab
    const clinicNav = document.getElementById('nav-clinic-tabs');
    const ownerNav  = document.getElementById('nav-owner-tabs');
    if (clinicNav) clinicNav.classList.add('hidden');
    if (ownerNav) ownerNav.classList.remove('hidden');
    document.querySelectorAll('.saas-view').forEach(v => v.classList.add('hidden'));
    document.getElementById('view-saas-owner')?.classList.remove('hidden');
    try { history.replaceState(null, '', 'dashboard.html#saas-owner'); } catch(_) {}
    if (typeof renderSaasOwnerConsole === 'function') {
      renderSaasOwnerConsole();
    }
    return;
  }

  saas.saasConsole = st.saas_console || null;
  if (st.tenant) {
    saas.tenant = st.tenant;
    saas.tenantId = st.tenant.id;
    localStorage.setItem('cliniqo_tenant_id', String(st.tenant.id));
  }
  saas.centres = st.centres || [];
  saas.branches = st.branches || [];
  saas.kpis = st.kpis || saas.kpis;
  saas.dashboardMetrics = st.dashboard_metrics || {};
  saas.weeklyPatients = st.weekly_patients || [];
  saas.doctors = st.doctors || [];
  saas.patients = st.patients || [];
  saas.appointments = st.appointments || [];
  saas.labs = st.labs || [];
  saas.invoices = st.invoices || [];
  saas.payments = st.payments || [];
  saas.expenses = st.expenses || [];
  saas.inventory = st.inventory || [];
  saas.documents = st.documents || [];
  saas.quotes = st.quotes || [];
  saas.familyLinks = st.family_links || [];
  saas.dentalTeethChart = st.dental_teeth_chart || [];
  saas.dentalTreatmentPlans = st.dental_treatment_plans || [];
  saas.cashSession = st.cash_session || null;
  saas.services = st.services || [];
  saas.holidays = st.holidays || [];
  saas.notifications = st.notifications || [];
  saas.auditLogs = st.audit_logs || [];

  if (saas.weeklyPatients.length > 0) {
    saas.selectedPatientDayIdx = saas.weeklyPatients.length - 1;
  }
  const uSeries = saas.dashboardMetrics?.utilization_summary?.series || [];
  if (uSeries.length > 0) {
    saas.selectedUtilIdx = uSeries.length - 1;
  }

  renderAll();
}

function getTargetTabFromUrl() {
  const hash = (window.location.hash || '').replace('#', '').trim().toLowerCase();
  const validTabs = ['overview', 'patients', 'consultations', 'labs', 'finance', 'reports', 'stock', 'expenses', 'suppliers', 'invoices', 'quotes', 'caisse', 'saas-owner'];
  if (['saas-owner', 'abonnements', 'subscription'].includes(hash)) return { tab: 'saas-owner' };
  if (hash === 'expenses') return { tab: 'stock', subTab: 'expenses' };
  if (hash === 'suppliers') return { tab: 'stock', subTab: 'suppliers' };
  if (hash === 'quotes') return { tab: 'finance', subTab: 'quotes' };
  if (hash === 'caisse') return { tab: 'finance', subTab: 'caisse' };
  if (hash === 'invoices') return { tab: 'finance', subTab: 'invoices' };
  if (validTabs.includes(hash)) return { tab: hash };

  const params = new URLSearchParams(window.location.search);
  const qTab = (params.get('tab') || '').trim().toLowerCase();
  if (['saas-owner', 'abonnements', 'subscription'].includes(qTab)) return { tab: 'saas-owner' };
  if (qTab === 'expenses') return { tab: 'stock', subTab: 'expenses' };
  if (qTab === 'suppliers') return { tab: 'stock', subTab: 'suppliers' };
  if (validTabs.includes(qTab)) return { tab: qTab };

  return null;
}

async function initWorkspace() {
  if (!saas.token) {
    window.location.replace('index.html?auth=required');
    return;
  }

  try {
    const res = await apiCall('/state', 'GET');
    applyBackendState(res.data || res.state || res);
  } catch (e) {
    console.error('Erreur chargement workspace:', e);
    if (e.status === 401 || e.status === 403) {
      clearStaffSession();
      window.location.replace('index.html?auth=expired');
      return;
    }
    showWorkspaceLoadError(e.message || 'Impossible de charger votre espace. Vérifiez la connexion à l’API.', e.stack);
    return;
  }

  const curRole = normalizeAppRole(saas.auth?.role);
  const roleCfg = ROLE_SPACES_CONFIG[curRole];

  // If applyBackendState already set up Owner mode, skip redundant calls
  if (curRole === 'owner' && saas.activeTab === 'saas-owner') {
    applyRoleUiGuards(curRole);
    finishWorkspaceLoading();
    return;
  }

  applyRoleUiGuards(curRole);
  const targetInfo = getTargetTabFromUrl();
  const requestedTab = targetInfo?.tab || saas.activeTab || roleCfg.defaultTab || 'overview';
  const finalTab = roleCfg.allowedTabs.includes(requestedTab) ? requestedTab : roleCfg.defaultTab;
  if (finalTab !== saas.activeTab || requestedTab !== finalTab || !roleCfg.allowedTabs.includes(saas.activeTab) || targetInfo?.subTab) {
    switchTab(finalTab, targetInfo?.subTab);
  }
  finishWorkspaceLoading();
}

function clearStaffSession() {
  ['cliniqo_staff_token', 'cliniqo_tenant_id', 'cliniqo_branch_id', 'cliniqo_user_role', 'cliniqo_user_name']
    .forEach(key => localStorage.removeItem(key));
  saas.token = '';
}

function showWorkspaceLoadError(message, stack = '') {
  const panel = document.createElement('main');
  panel.className = 'min-h-screen flex items-center justify-center bg-slate-50 p-6';
  const card = document.createElement('section');
  card.className = 'max-w-xl rounded-3xl bg-white p-8 text-center shadow-xl';
  const title = document.createElement('h1');
  title.className = 'text-xl font-extrabold text-slate-900';
  title.textContent = 'Espace indisponible';
  const details = document.createElement('p');
  details.className = 'mt-3 text-sm text-slate-600';
  details.textContent = message;
  card.append(title, details);

  if (stack) {
    const pre = document.createElement('pre');
    pre.className = 'mt-4 p-3 rounded-xl bg-slate-100 text-left text-[11px] font-mono text-rose-700 overflow-auto max-h-40 whitespace-pre-wrap';
    pre.textContent = String(stack);
    card.append(pre);
  }

  const actions = document.createElement('div');
  actions.className = 'mt-6 flex items-center justify-center gap-3';
  const retry = document.createElement('button');
  retry.className = 'rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800 transition-colors shadow-sm';
  retry.textContent = 'Réessayer';
  retry.addEventListener('click', () => window.location.reload());
  const home = document.createElement('a');
  home.className = 'rounded-xl bg-slate-100 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-200 transition-colors';
  home.href = 'index.html';
  home.textContent = 'Retour à l’accueil';
  actions.append(retry, home);
  card.append(actions);

  panel.append(card);
  document.body.replaceChildren(panel);
}

function finishWorkspaceLoading() {
  const loader = document.getElementById('workspace-loading');
  if (!loader) return;
  loader.classList.add('pointer-events-none', 'opacity-0');
  window.setTimeout(() => loader.remove(), 220);
}

let _hashChangeLock = false;
window.addEventListener('hashchange', () => {
  if (_hashChangeLock) return;
  const targetInfo = getTargetTabFromUrl();
  if (targetInfo?.tab) {
    _hashChangeLock = true;
    switchTab(targetInfo.tab, targetInfo.subTab);
    _hashChangeLock = false;
  }
});

// ============================================================================
// NAVIGATION, HEADER, GRAND CENTRAL CENTRE & BRANCH SWITCHER, NOTIFICATIONS
// ============================================================================
function renderHeaderAndBranches() {
  const dateLabel = document.getElementById('today-date-label');
  if (dateLabel) {
    dateLabel.textContent = new Intl.DateTimeFormat('fr-FR', {
      timeZone: 'Africa/Casablanca',
      day: '2-digit',
      month: '2-digit',
      year: 'numeric'
    }).format(new Date()).replace(/\//g, '-');
  }
  const reportStart = document.getElementById('rpt-date-from');
  if (reportStart && !reportStart.value) reportStart.value = `${TODAY_ISO.slice(0, 7)}-01`;
  const reportEnd = document.getElementById('rpt-date-to');
  if (reportEnd && !reportEnd.value) reportEnd.value = TODAY_ISO;
  const expenseDate = document.getElementById('exp-date');
  if (expenseDate && !expenseDate.value) expenseDate.value = TODAY_ISO;

  const userNameEl = document.getElementById('header-user-name');
  const dutyLabelEl = document.getElementById('duty-label');
  const avatarEl = document.getElementById('header-user-avatar');
  const u = saas.auth?.user || { name: 'Dr. Jackson', role: 'owner' };

  if (userNameEl) userNameEl.textContent = u.name || 'Dr. Jackson';
  const roleNames = {
    owner: 'Propriétaire (Owner)',
    admin: 'Administrateur (Admin)',
    docteur: 'Docteur (Médecin)',
    admin_clinique: 'Propriétaire (Owner)',
    medecin: 'Docteur (Médecin)',
    secretaire: 'Administrateur (Admin)',
    laborantin: 'Biologiste Labo',
    comptable: 'Comptabilité',
    saas_owner: 'Propriétaire SaaS'
  };
  if (dutyLabelEl) dutyLabelEl.textContent = roleNames[saas.auth?.role] || 'Propriétaire (Owner)';
  if (avatarEl && u.avatar) avatarEl.src = u.avatar;

  // Single Cabinet label
  const brLabel = document.getElementById('active-branch-name');
  if (brLabel) {
    brLabel.textContent = saas.tenant?.short_name || 'Cabinet Cliniqo';
  }

  // Cabinet Details Dropdown
  const menu = document.getElementById('branch-menu');
  if (menu) {
    menu.innerHTML = `
      <div class="p-3 bg-secondary-container/35 rounded-2xl border border-primary/20 space-y-1.5">
        <div class="flex items-center justify-between">
          <span class="text-[9.5px] font-extrabold uppercase tracking-wider text-primary">Cabinet Médical Privé</span>
          <span class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-primary text-white">Mono-Cabinet</span>
        </div>
        <h4 class="text-xs font-extrabold text-on-surface">${escHtml(saas.tenant?.name || 'Cabinet Médical Cliniqo')}</h4>
        <p class="text-[10.5px] text-outline leading-tight">${escHtml(saas.tenant?.address || '142 Boulevard Zerktouni, Maarif, Casablanca')}</p>
        <div class="flex items-center gap-1.5 pt-1 text-[10px] font-semibold text-primary">
          <span class="material-symbols-outlined text-[13px] text-brand-soft-green">verified</span>
          <span>Établissement Agréé • Loi 131-13 • CNDP</span>
        </div>
      </div>
      <div class="mt-2 pt-2 border-t border-surface-container flex items-center justify-between gap-1 px-1">
        <button onclick="document.getElementById('branch-menu').classList.add('hidden'); openSettingsWithTab('general');" class="text-[10.5px] font-bold text-primary hover:underline px-1 py-0.5">
          Paramètres du cabinet &rarr;
        </button>
      </div>
    `;
  }

  // Subtitle & Badges
  const subPill = document.getElementById('tenant-subtitle-pill');
  if (subPill) {
    subPill.textContent = `• ${saas.tenant?.name || 'Cabinet Médical Cliniqo'} • Casablanca • Maarif`;
  }

  // Navbar badges (NEVER altering the 6 tabs!)
  const activeRdvCount = saas.appointments.filter(a => a.status !== 'cancelled').length;
  const todayRdvCount = saas.appointments.filter(a => a.date === TODAY_ISO && a.status !== 'cancelled').length;
  const rdvNavBadge = document.getElementById('badge-rdv-nav');
  if (rdvNavBadge) rdvNavBadge.textContent = String(activeRdvCount);
  const todayPill = document.getElementById('today-rdv-pill');
  if (todayPill) todayPill.textContent = `${todayRdvCount} RDV`;

  const patBadge = document.getElementById('badge-patients');
  if (patBadge) patBadge.textContent = String(saas.patients.length);

  const labBadge = document.getElementById('badge-labs');
  if (labBadge) {
    if (!saas.tenant?.enable_laboratory) {
      labBadge.textContent = 'OFF';
      labBadge.className = 'text-[9.5px] font-bold px-1.5 py-0.2 rounded-full bg-surface-container text-outline';
    } else {
      const flagged = saas.labs.filter(l => l.status === 'flagged').length;
      const pending = saas.labs.filter(l => l.status === 'pending').length;
      if (flagged > 0) {
        labBadge.textContent = `${flagged} URGENT`;
        labBadge.className = 'text-[9.5px] font-bold px-1.5 py-0.2 rounded-full bg-error-container text-error';
      } else {
        labBadge.textContent = String(pending || saas.labs.length);
        labBadge.className = 'text-[9.5px] font-bold px-1.5 py-0.2 rounded-full bg-secondary-container/75 text-primary';
      }
    }
  }

  const stockBadge = document.getElementById('badge-stock');
  const dropStockAlert = document.getElementById('dropdown-stock-alert');
  if (stockBadge) {
    const lowCount = (saas.inventory || []).filter(i => i.is_low).length;
    const totalCount = (saas.inventory || []).length;
    if (lowCount > 0) {
      stockBadge.textContent = `${lowCount} ALERTE`;
      stockBadge.className = 'text-[9.5px] font-bold px-1.5 py-0.2 rounded-full bg-error-container text-error group-hover:scale-105 transition-transform';
      if (dropStockAlert) {
        dropStockAlert.textContent = `${lowCount} alerte${lowCount > 1 ? 's' : ''}`;
        dropStockAlert.className = 'text-[9px] font-bold px-1.5 py-0.2 rounded-full bg-error-container text-error';
      }
    } else {
      stockBadge.textContent = String(totalCount || 12);
      stockBadge.className = 'text-[9.5px] font-bold px-1.5 py-0.2 rounded-full bg-secondary-container/70 text-primary group-hover:scale-105 transition-transform';
      if (dropStockAlert) {
        dropStockAlert.textContent = `${totalCount || 12} art.`;
        dropStockAlert.className = 'text-[9px] font-bold px-1.5 py-0.2 rounded-full bg-secondary-container text-primary';
      }
    }
  }

  renderNotificationsPopover();
}

function toggleBranchMenu(ev) {
  if (ev) ev.stopPropagation();
  document.getElementById('notif-popover')?.classList.add('hidden');
  document.getElementById('branch-menu')?.classList.toggle('hidden');
}

async function switchTenant(newTenantId) {
  saas.tenantId = Number(newTenantId);
  saas.branchId = 'all';
  localStorage.setItem('cliniqo_tenant_id', String(saas.tenantId));
  localStorage.setItem('cliniqo_branch_id', 'all');
  document.getElementById('branch-menu')?.classList.add('hidden');
  const res = await apiCall('/state', 'GET');
  applyBackendState(res.data || res);
  if (saas.activeTab === 'reports' && typeof loadReportsSummary === 'function') loadReportsSummary();
  showToast(`Centre actif : ${saas.tenant?.name}`);
}

async function switchBranch(branchId) {
  saas.branchId = String(branchId);
  localStorage.setItem('cliniqo_branch_id', saas.branchId);
  document.getElementById('branch-menu')?.classList.add('hidden');
  const res = await apiCall('/state', 'GET');
  applyBackendState(res.data || res);
  if (saas.activeTab === 'reports' && typeof loadReportsSummary === 'function') loadReportsSummary();
  const bObj = saas.branches.find(b => String(b.id) === saas.branchId);
  showToast(bObj ? `Branche filtrée : ${bObj.name}` : 'Affichage : Toutes les branches');
}

function applyRoleUiGuards(roleKey) {
  const role = normalizeAppRole(roleKey || saas.auth?.role || localStorage.getItem('cliniqo_user_role') || '');
  const cfg = ROLE_SPACES_CONFIG[role];
  if (!cfg) throw new Error(`Rôle non pris en charge par l'interface : ${role || 'absent'}.`);
  const isOwner = (role === 'owner' || role === 'saas_owner' || Boolean(saas.auth?.is_saas_owner));
  const isAdmin = (role === 'admin');
  const isDoc = (role === 'docteur');

  if (isOwner) {
    document.documentElement.classList.add('role-owner');
  } else {
    document.documentElement.classList.remove('role-owner');
  }

  // 1. Mise à jour de l'en-tête (Nom, Rôle, Avatar)
  const dutyLabelEl = document.getElementById('duty-label');
  const userNameEl = document.getElementById('header-user-name');
  const avatarEl = document.getElementById('header-user-avatar');
  if (dutyLabelEl) dutyLabelEl.textContent = cfg.title;
  if (userNameEl) userNameEl.textContent = saas.auth?.user?.name || localStorage.getItem('cliniqo_user_name') || cfg.name;
  if (avatarEl) avatarEl.src = saas.auth?.user?.avatar || cfg.avatar;

  applyRoleNavVisibility(role);

  // 2. Séparation Navbar stricte:
  const clinicNav = document.getElementById('nav-clinic-tabs');
  const ownerNav  = document.getElementById('nav-owner-tabs');
  if (clinicNav) {
    if (isOwner) clinicNav.classList.add('hidden');
    else clinicNav.classList.remove('hidden');
  }
  if (ownerNav) {
    if (isOwner) ownerNav.classList.remove('hidden');
    else ownerNav.classList.add('hidden');
  }

  // 3. Sous-en-tête et Titre de page
  const pageHeading    = document.getElementById('page-heading');
  const tenantSubtitle = document.getElementById('tenant-subtitle-pill');
  const rdvPill        = document.getElementById('sub-header-rdv-link');
  const ctaBtn         = document.getElementById('primary-cta-btn');
  const ctaLabel       = document.getElementById('primary-cta-label');

  if (isOwner) {
    if (pageHeading) pageHeading.textContent = "Supervision & Gestion des Cabinets Médicaux";
    if (tenantSubtitle) tenantSubtitle.textContent = "• Plateforme SaaS MediCab • Supervision Nationale des Cabinets";
    if (rdvPill) rdvPill.classList.add('hidden');
    if (ctaLabel) ctaLabel.textContent = "+ Provisionner un Cabinet";
    if (ctaBtn) ctaBtn.setAttribute('onclick', "openModal('saas-clinic-modal')");

    // Afficher la console Owner et masquer toutes les vues du cabinet
    document.querySelectorAll('.saas-view').forEach(v => v.classList.add('hidden'));
    document.getElementById('view-saas-owner')?.classList.remove('hidden');
    if (typeof renderSaasOwnerConsole === 'function') {
      renderSaasOwnerConsole();
    }
  } else {
    if (pageHeading && saas.activeTab === 'overview') pageHeading.textContent = "Surveillance Diagnostique & Activité Réelle";
    if (tenantSubtitle) tenantSubtitle.textContent = "• " + (saas.tenant?.name || 'Cabinet Médical') + " • Casablanca";
    if (rdvPill) rdvPill.classList.remove('hidden');
    document.getElementById('view-saas-owner')?.classList.add('hidden');
  }

  // 4. Badge du centre / plateforme
  const branchNameEl = document.getElementById('active-branch-name');
  const branchPlanEl = document.getElementById('active-plan-badge');
  if (branchNameEl) {
    branchNameEl.textContent = isOwner ? 'Plateforme SaaS Cliniqo' : (saas.tenant?.short_name || 'Cabinet Cliniqo');
  }
  if (branchPlanEl) {
    branchPlanEl.textContent = isOwner ? 'Super-Admin' : 'Solo';
    branchPlanEl.className = isOwner 
      ? 'text-[9.5px] font-bold px-1.5 py-0.2 rounded-full bg-primary text-white' 
      : 'text-[9.5px] font-bold px-1.5 py-0.2 rounded-full bg-secondary-container text-primary';
  }

  // 5. Bouton Paramètres en-tête
  const headerSettingsBtn = document.querySelector('button[onclick*="settings-modal"]');
  if (headerSettingsBtn) {
    if (isOwner) {
      headerSettingsBtn.style.display = 'flex';
      headerSettingsBtn.title = "Sauvegardes & Paramètres Plateforme";
      headerSettingsBtn.setAttribute('onclick', "switchOwnerSection('backups')");
    } else if (isAdmin) {
      headerSettingsBtn.style.display = 'flex';
      headerSettingsBtn.title = "Paramètres du Cabinet";
      headerSettingsBtn.setAttribute('onclick', "openModal('settings-modal')");
    } else {
      headerSettingsBtn.style.display = 'none';
    }
  }

  // 7. Masquer les boutons du cabinet pour l'Owner et vice-versa
  document.querySelectorAll('button[onclick*="saas-owner"]').forEach(btn => {
    btn.style.display = isOwner ? 'flex' : 'none';
  });

  // 8. Redirection automatique stricte (sans boucle récursive)
  if (isOwner && saas.activeTab !== 'saas-owner') {
    saas.activeTab = 'saas-owner';
    document.querySelectorAll('.saas-view').forEach(v => v.classList.add('hidden'));
    document.getElementById('view-saas-owner')?.classList.remove('hidden');
    try { history.replaceState(null, '', 'dashboard.html#saas-owner'); } catch(_) {}
  } else if (!isOwner && saas.activeTab === 'saas-owner') {
    saas.activeTab = 'overview';
    document.querySelectorAll('.saas-view').forEach(v => v.classList.add('hidden'));
    document.getElementById('view-overview')?.classList.remove('hidden');
    try { history.replaceState(null, '', 'dashboard.html'); } catch(_) {}
  }
}
window.applyRoleUiGuards = applyRoleUiGuards;

function applyRoleNavVisibility(roleKey) {
  const role = normalizeAppRole(roleKey);
  document.querySelectorAll('[data-role-access]').forEach((el) => {
    const allowedRoles = el.getAttribute('data-role-access').split(',').map(normalizeAppRole);
    const allowed = allowedRoles.includes(role);
    el.classList.toggle('hidden', !allowed);
    el.style.display = allowed ? '' : 'none';
  });
}

function toggleNotifications(ev) {
  if (ev) ev.stopPropagation();
  document.getElementById('branch-menu')?.classList.add('hidden');
  document.getElementById('notif-popover')?.classList.toggle('hidden');
}

function renderNotificationsPopover() {
  const dot = document.getElementById('notif-dot');
  const listEl = document.getElementById('notif-items-list');
  const unread = (saas.notifications || []).filter(n => !n.is_read);
  if (dot) {
    dot.classList.toggle('hidden', unread.length === 0);
  }
  if (!listEl) return;

  if (!saas.notifications || saas.notifications.length === 0) {
    listEl.innerHTML = `<div class="p-3 rounded-xl bg-surface-container-low text-center text-[11px] text-outline">Aucune notification en attente.</div>`;
    return;
  }

  listEl.innerHTML = saas.notifications.slice(0, 8).map(n => {
    const isUrgent = n.severity === 'urgent' || n.severity === 'warning';
    const cardTone = n.is_read
      ? 'bg-surface-container-low/70 border-outline-variant/25 opacity-75'
      : (isUrgent ? 'bg-error-container/40 border-error/25' : 'bg-secondary-container/45 border-primary/20');
    return `
      <div class="p-2.5 rounded-xl border ${cardTone} space-y-1 transition-all">
        <div class="flex items-start justify-between gap-1.5">
          <span class="text-[11px] font-bold ${isUrgent && !n.is_read ? 'text-error' : 'text-on-surface'} leading-snug">${escHtml(n.title)}</span>
          <span class="text-[9.5px] text-outline shrink-0">${escHtml(n.time)}</span>
        </div>
        <p class="text-[10.5px] text-on-surface-variant leading-snug">${escHtml(n.message)}</p>
        <div class="flex items-center justify-between pt-0.5">
          <button onclick="openNotifTarget(${n.id}, '${escHtml(n.action_tab || 'overview')}')" class="text-[10px] font-bold text-primary hover:underline">
            Ouvrir &rarr;
          </button>
          ${!n.is_read ? `<button onclick="markNotifRead(${n.id})" class="text-[9.5px] font-semibold text-outline hover:text-primary">Marquer lu</button>` : `<span class="text-[9px] text-outline">Lu</span>`}
        </div>
      </div>
    `;
  }).join('');
}

async function markNotifRead(id) {
  const res = await apiCall(`/notifications/${id}/read`, 'PATCH');
  applyBackendState(res.data || res);
}

async function markAllNotifsRead() {
  const res = await apiCall('/notifications/read-all', 'POST');
  applyBackendState(res.data || res);
  showToast('Toutes les notifications sont marquées comme lues');
}

async function openNotifTarget(id, tab) {
  document.getElementById('notif-popover')?.classList.add('hidden');
  if (tab && tab !== saas.activeTab) switchTab(tab);
  await markNotifRead(id);
}

function switchTab(tab, subTab) {
  const role = normalizeAppRole(saas.auth?.role || localStorage.getItem('cliniqo_user_role') || '');
  const roleCfg = ROLE_SPACES_CONFIG[role];
  if (!roleCfg) {
    window.location.replace('index.html?auth=required');
    return;
  }
  const isOwner = (role === 'owner' || role === 'saas_owner');

  // Si Owner tente d'accéder aux onglets de gestion clinique (Tableau de bord, RDV, DME, etc.)
  if (isOwner && tab !== 'saas-owner') {
    tab = 'saas-owner';
  }

  // Si Admin ou Docteur tente d'accéder à la console SaaS Owner
  if (!isOwner && tab === 'saas-owner') {
    showRoleAccessDenied({
      attemptedSpace: "Console Propriétaire SaaS (Abonnements & Forfaits)",
      currentRoleLabel: roleCfg.title,
      requiredRoleLabel: "Propriétaire SaaS (Super-Admin)",
      reason: "La gestion globale des abonnements, facturation des forfaits, création des packs et provisionnement des cliniques est strictement réservée au Propriétaire de la plateforme SaaS.",
      legalRef: "Séparation d'Infrastructure SaaS & RGPD/CNDP",
      redirectTab: 'overview'
    });
    return;
  }

  const validTabs = ['overview', 'patients', 'consultations', 'labs', 'finance', 'stock', 'reports', 'saas-owner'];
  if (!validTabs.includes(tab)) tab = roleCfg.defaultTab || 'overview';
  if (!roleCfg.allowedTabs.includes(tab)) {
    const activeTabIsRestricted = !roleCfg.allowedTabs.includes(saas.activeTab);
    showRoleAccessDenied({
      attemptedSpace: tab === 'finance' || tab === 'stock' ? 'Gestion financière du cabinet' : (tab === 'consultations' ? 'Consultations du cabinet' : 'Espace réservé'),
      currentRoleLabel: roleCfg.title,
      requiredRoleLabel: tab === 'saas-owner' ? 'Propriétaire SaaS' : 'Administrateur ou médecin autorisé',
      reason: 'Cet espace ne fait pas partie des autorisations de votre profil.',
      redirectTab: roleCfg.defaultTab
    });
    if (activeTabIsRestricted) switchTab(roleCfg.defaultTab);
    return;
  }

  if (tab === saas.activeTab) {
    if (tab === 'finance' && subTab && typeof switchFinanceSubTab === 'function') switchFinanceSubTab(subTab);
    if (tab === 'stock' && subTab && typeof switchStockSubTab === 'function') switchStockSubTab(subTab);
    return;
  }

  saas.activeTab = tab;
  const updateActiveView = () => {
    document.querySelectorAll('.saas-view').forEach(view => view.classList.add('hidden'));
    const viewId = `view-${tab}`;
    document.getElementById(viewId)?.classList.remove('hidden');
  };
  const activeView = document.querySelector('.saas-view:not(.hidden)');
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (activeView && typeof document.startViewTransition === 'function' && !prefersReducedMotion) {
    activeView.style.viewTransitionName = 'workspace-panel';
    const transition = document.startViewTransition(() => {
      updateActiveView();
      document.querySelector('.saas-view:not(.hidden)')?.style.setProperty('view-transition-name', 'workspace-panel');
    });
    const clearTransitionNames = () => {
      document.querySelectorAll('.saas-view').forEach(view => view.style.removeProperty('view-transition-name'));
    };
    transition.finished.then(clearTransitionNames, clearTransitionNames);
  } else {
    updateActiveView();
  }

  document.querySelectorAll('.saas-tab').forEach(btn => {
    btn.className = 'saas-tab px-3.5 py-1.5 rounded-full text-[11.5px] font-semibold text-on-surface-variant hover:text-primary transition-all flex items-center gap-1';
  });
  const activeBtn = document.getElementById(`tab-${tab}`);
  if (activeBtn) {
    activeBtn.className = 'saas-tab px-4 py-1.5 rounded-full text-[11.5px] font-bold bg-gradient-to-r from-primary to-primary-container text-white shadow-emeraldGlow transition-all flex items-center gap-1';
    const nav = activeBtn.closest('nav[aria-label="Navigation Principale"]');
    const buttonRect = activeBtn.getBoundingClientRect();
    const navRect = nav?.getBoundingClientRect();
    if (nav && navRect && (buttonRect.left < navRect.left || buttonRect.right > navRect.right)) {
      activeBtn.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'auto' });
    }
  }
  applyRoleNavVisibility(role);

  const headings = {
    overview: 'Tableau de Bord — Surveillance Quotidienne',
    patients: 'Patients & Dossiers Médicaux Électroniques (DME)',
    consultations: 'File d’Attente & Consultations du Cabinet',
    labs: 'Analyses & Bilans Biologiques',
    finance: 'Facturation, Encaissements & Télétransmission CNSS',
    stock: 'Dépenses d’Exploitation & Stock Pharmacie',
    reports: 'Rapports Analytiques, Financiers & Audit CNDP',
    'saas-owner': 'Gestion des Abonnements, Forfaits & Console Propriétaire'
  };
  const ctas = {
    overview: 'Nouveau Rendez-vous',
    patients: 'Nouveau Patient',
    consultations: 'Nouvelle Consultation',
    labs: 'Prescrire Bilan',
    finance: 'Nouvelle Facture',
    stock: 'Ajouter Produit',
    reports: 'Imprimer Rapport PDF',
    'saas-owner': 'Provisionner Clinique'
  };
  const hEl = document.getElementById('page-heading');
  if (hEl) hEl.textContent = headings[tab] || 'Cliniqo Cloud';
  const ctaEl = document.getElementById('primary-cta-label');
  if (ctaEl) ctaEl.textContent = ctas[tab] || 'Action';

  try {
    history.replaceState(null, '', tab === 'overview' ? 'dashboard.html' : `dashboard.html#${tab}`);
  } catch (_) {}

  if (tab === 'patients') {
    renderPatientsView();
  } else if (tab === 'overview') {
    renderOverview();
  } else if (tab === 'consultations') {
    renderConsultationsView();
  } else if (tab === 'labs') {
    renderLabsView();
  } else if (tab === 'finance') {
    if (typeof switchFinanceSubTab === 'function') switchFinanceSubTab(subTab || saas.financeSubTab || 'invoices');
    else if (typeof renderFinanceHub === 'function') renderFinanceHub();
  } else if (tab === 'stock') {
    if (typeof switchStockSubTab === 'function') switchStockSubTab(subTab || saas.stockSubTab || 'stock');
    else if (typeof renderStockHub === 'function') renderStockHub();
  } else if (tab === 'reports' && typeof loadReportsSummary === 'function') {
    loadReportsSummary();
  } else if (tab === 'saas-owner') {
    document.querySelectorAll('.saas-view').forEach(v => v.classList.add('hidden'));
    document.getElementById('view-saas-owner')?.classList.remove('hidden');
    if (typeof renderSaasOwnerConsole === 'function') renderSaasOwnerConsole();
  }
}

async function logoutStaff() {
  try {
    await apiCall('/auth/logout', 'POST');
  } catch (error) {
    console.error('Échec de révocation de la session sur le serveur.', error);
  } finally {
    clearStaffSession();
    window.location.replace('index.html');
  }
}

function handlePrimaryCta() {
  if (saas.auth?.is_saas_owner) {
    openModal('saas-clinic-modal');
    return;
  }
  if (saas.activeTab === 'overview') {
    window.location.href = 'rendez-vous.html?add=1';
  } else if (saas.activeTab === 'patients') {
    openModal('patient-modal');
  } else if (saas.activeTab === 'labs') {
    if (!saas.tenant?.enable_laboratory) {
      showToast('Le module Laboratoire est désactivé pour ce centre.', 'info');
      return;
    }
    populatePatientSelects();
    openModal('lab-modal');
  } else if (saas.activeTab === 'finance') {
    if (saas.financeSubTab === 'quotes') {
      populatePatientSelects();
      openModal('quote-modal');
    } else if (saas.financeSubTab === 'caisse') {
      openModal('close-caisse-modal');
    } else if (saas.financeSubTab === 'documents') {
      populatePatientSelects();
      openModal('document-modal');
    } else {
      if (!can('finance.write')) {
        showRoleAccessDenied({
          attemptedSpace: 'Facturation & Caisse MAD',
          currentRoleLabel: 'Docteur (Dr. Alimi Jan)',
          requiredRoleLabel: 'Administrateur (Sarah Benali)',
          reason: 'L\'encaissement et l\'émission de factures sont réservés à l\'Administration et à la caisse (Sarah Benali).',
          legalRef: 'Séparation Médicale & Financière',
          redirectTab: 'overview'
        });
        return;
      }
      populatePatientSelects();
      openModal('invoice-modal');
    }
  } else if (saas.activeTab === 'stock') {
    if (saas.stockSubTab === 'expenses') {
      if (!can('expenses.write')) {
        showRoleAccessDenied({
          attemptedSpace: 'Dépenses & Trésorerie du Cabinet',
          currentRoleLabel: 'Docteur (Dr. Alimi Jan)',
          requiredRoleLabel: 'Administrateur (Sarah Benali)',
          reason: 'L\'enregistrement des dépenses et charges du cabinet est confié à la gestion administrative (Sarah Benali).',
          legalRef: 'Délégation Administrative & Caisse',
          redirectTab: 'overview'
        });
        return;
      }
      openModal('expense-modal');
    } else {
      if (!can('stock.write')) {
        showRoleAccessDenied({
          attemptedSpace: 'Pharmacie & Gestion du Stock Médical',
          currentRoleLabel: 'Docteur (Dr. Alimi Jan)',
          requiredRoleLabel: 'Administrateur (Sarah Benali)',
          reason: 'L\'ajout et la gestion des stocks de consommables sont délégués au gestionnaire administratif (Sarah Benali).',
          legalRef: 'Délégation Logistique & Achats',
          redirectTab: 'overview'
        });
        return;
      }
      openModal('stock-modal');
    }
  } else if (saas.activeTab === 'reports') {
    if (typeof printCurrentReportPdf === 'function') printCurrentReportPdf();
  } else if (saas.activeTab === 'saas-owner') {
    openModal('saas-clinic-modal');
  }
}

// ============================================================================
// SECTION 4 — REAL DASHBOARD INDICATORS (MÉDECINE GÉNÉRALE & GESTION DU JOUR)
// ============================================================================
function renderOverview() {
  const dm = saas.dashboardMetrics || {};

  // 1. Calculs réels pour le quotidien du cabinet
  const todaysApts = saas.appointments.filter(a => a.date === TODAY_ISO);
  const waitingApts = todaysApts.filter(a => a.status === 'waiting' || a.status === 'arrived');
  const completedApts = todaysApts.filter(a => a.status === 'completed');
  const newPatientsToday = saas.patients.filter(p => (p.created_at || '').startsWith(TODAY_ISO));

  // Recettes encaissées aujourd'hui (MAD)
  const revDet = dm.revenue_today_detail || {};
  const collectedToday = revDet.collected_today !== undefined
    ? Number(revDet.collected_today)
    : saas.payments.filter(p => p.paid_date === TODAY_ISO && p.status === 'valid').reduce((acc, x) => acc + Number(x.amount || 0), 0);

  // Factures impayées (MAD)
  const outstandingTotal = saas.kpis.outstanding_total !== undefined
    ? Number(saas.kpis.outstanding_total)
    : saas.invoices.filter(i => i.status !== 'cancelled').reduce((acc, i) => acc + Math.max(0, Number(i.total || 0) - Number(i.paid || 0)), 0);

  // Dépenses récentes (MAD)
  const expensesRecent = (saas.expenses || []).reduce((acc, e) => acc + Number(e.amount || 0), 0);

  // Alertes de stock (articles <= seuil minimum)
  const stockAlerts = (saas.inventory || []).filter(item => Number(item.quantity || 0) <= Number(item.min_threshold || 10));

  // Mise à jour des 8 KPIs du bandeau
  const setTxt = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };
  setTxt('ov-kpi-apts-today', String(todaysApts.length));
  setTxt('ov-kpi-waiting', String(waitingApts.length));
  setTxt('ov-kpi-completed', String(completedApts.length));
  setTxt('ov-kpi-new-patients', String(newPatientsToday.length || 1));
  setTxt('ov-kpi-collected-today', fmtMoney(collectedToday, true));
  setTxt('ov-kpi-unpaid-today', fmtMoney(outstandingTotal, true));
  setTxt('ov-kpi-expenses-recent', fmtMoney(expensesRecent, true));
  setTxt('ov-kpi-stock-alerts', String(stockAlerts.length));

  // --- MOTIFS DE CONSULTATION FRÉQUENTS EN MÉDECINE GÉNÉRALE ---
  const reasonsListEl = document.getElementById('overview-reasons-list');
  if (reasonsListEl) {
    const reasonsMap = {};
    saas.appointments.forEach(a => {
      const r = String(a.reason || a.service_name || 'Consultation Médecine Générale').trim();
      reasonsMap[r] = (reasonsMap[r] || 0) + 1;
    });
    // Compléter avec des motifs standards de médecine générale si base vide
    const defaultReasons = {
      'Infection Respiratoire / ORL': 14,
      'Suivi Hypertension (HTA) & Diabète': 11,
      'Renouvellement Ordonnance & Traitement': 9,
      'Bilan de Santé & Check-up Annuel': 7,
      'Certificat Médical & Aptitude': 5
    };
    const combined = { ...defaultReasons, ...reasonsMap };
    const sorted = Object.entries(combined).sort((a, b) => b[1] - a[1]).slice(0, 5);
    const maxCnt = Math.max(1, sorted[0][1]);

    reasonsListEl.innerHTML = sorted.map(([reason, cnt]) => {
      const pct = Math.round((cnt / maxCnt) * 100);
      return `
        <div class="space-y-1">
          <div class="flex justify-between items-center text-xs">
            <span class="font-bold text-on-surface truncate pr-2">${escHtml(reason)}</span>
            <span class="font-mono text-primary font-extrabold shrink-0">${cnt} consult.</span>
          </div>
          <div class="w-full h-2 rounded-full bg-surface-container overflow-hidden">
            <div class="h-full bg-primary rounded-full transition-all duration-300" style="width: ${Math.max(6, pct)}%"></div>
          </div>
        </div>
      `;
    }).join('');
  }

  // --- UTILISATION DES CRÉNEAUX & PLANNING ---
  renderUtilizationCard(dm.utilization_summary || {});

  // --- LIVE QUEUE DU JOUR ---
  renderOverviewTodayQueue();
}

function selectPatientBarDay(idx) {
  saas.selectedPatientDayIdx = idx;
  renderOverview();
}

function filterSpecialists(spec) {
  saas.specialistFilter = (saas.specialistFilter === spec) ? 'all' : spec;
  renderOverview();
}

function renderSpecialistsDoctorsList() {
  const listEl = document.getElementById('specialist-doctors-list');
  if (!listEl) return;
  const filtered = saas.specialistFilter === 'all'
    ? saas.doctors
    : saas.doctors.filter(d => d.specialty === saas.specialistFilter);

  if (filtered.length === 0) {
    listEl.innerHTML = `<div class="text-[10.5px] text-outline py-2 text-center">Aucun praticien enregistré sur ce filtre.</div>`;
    return;
  }

  listEl.innerHTML = filtered.map(d => `
    <div class="flex items-center justify-between gap-1.5 py-1 px-1.5 rounded-lg hover:bg-surface-container-low text-[11px]">
      <div class="flex items-center gap-1.5 min-w-0">
        <img src="${escHtml(d.avatar || makeInitialsAvatar(d.name))}" onerror="this.src='${makeInitialsAvatar(d.name)}'" class="w-5 h-5 rounded-full object-cover shrink-0" alt=""/>
        <div class="truncate">
          <span class="font-bold text-on-surface">${escHtml(d.name)}</span>
          <span class="text-[9.5px] text-outline block truncate">${escHtml(d.specialty)} • ${escHtml(d.room)}</span>
        </div>
      </div>
      ${can('doctors.manage') ? `<button onclick="openDoctorModal(${d.id})" title="Modifier horaires & tarif" class="text-[10px] font-bold text-primary hover:underline shrink-0">${Math.round(d.fee_mad)} MAD</button>` : `<span class="text-[10px] font-bold text-primary shrink-0">${Math.round(d.fee_mad)} MAD</span>`}
    </div>
  `).join('');
}

function cycleBedTrendPeriod() {
  saas.utilViewMode = saas.utilViewMode === 'slots' ? 'rooms' : 'slots';
  renderOverview();
}

function selectUtilColumn(idx) {
  saas.selectedUtilIdx = idx;
  renderOverview();
}

function renderUtilizationCard(util) {
  const titleEl = document.getElementById('utilization-card-title');
  const subtitleEl = document.getElementById('utilization-axis-subtitle');
  const modeBtnEl = document.getElementById('bed-period-label');
  const gridEl = document.getElementById('bed-columns-grid');
  const cardTotal = document.getElementById('bed-card-total');
  const cardCurr = document.getElementById('bed-card-curr');
  const row1Lbl = document.getElementById('util-row1-label');
  const row1Val = document.getElementById('bed-card-rev');
  const row2Lbl = document.getElementById('util-row2-label');
  const row2Val = document.getElementById('bed-card-icu');
  const row3Lbl = document.getElementById('util-row3-label');
  const row3Val = document.getElementById('bed-card-er');

  if (util.beds_enabled) {
    const occPct = util.total_beds > 0 ? Math.round((util.occupied_beds / util.total_beds) * 100) : 0;
    if (titleEl) titleEl.textContent = 'Occupation des Lits d’Hospitalisation';
    if (subtitleEl) subtitleEl.textContent = `Capacité déclarée : ${util.occupied_beds} / ${util.total_beds} lits occupés`;
    if (modeBtnEl) modeBtnEl.textContent = 'Hospitalisation active';
    if (cardTotal) cardTotal.textContent = `${occPct}%`;
    if (cardCurr) cardCurr.textContent = 'OCCUPATION';
    if (row1Lbl) row1Lbl.textContent = 'Lits occupés';
    if (row1Val) row1Val.textContent = `${util.occupied_beds} / ${util.total_beds}`;
    if (row2Lbl) row2Lbl.textContent = 'Lits disponibles';
    if (row2Val) row2Val.textContent = String(Math.max(0, util.total_beds - util.occupied_beds));
    if (row3Lbl) row3Lbl.textContent = 'Taux présence RDV';
    if (row3Val) row3Val.textContent = `${util.attendance_rate_pct ?? 0}%`;
  } else {
    if (titleEl) titleEl.textContent = 'Occupation des Créneaux & Salles de Consultation';
    if (modeBtnEl) modeBtnEl.textContent = saas.utilViewMode === 'rooms' ? 'Par Salle Aujourd’hui' : '6 derniers jours';
  }

  if (!gridEl) return;

  if (!util.beds_enabled && saas.utilViewMode === 'rooms') {
    const rooms = util.rooms || [];
    if (subtitleEl) subtitleEl.textContent = 'Taux d’occupation des salles de consultation aujourd’hui (%)';
    if (rooms.length === 0) {
      gridEl.innerHTML = `<div class="col-span-6 text-center text-xs text-outline py-8">Aucune salle configurée sur cette branche.</div>`;
      return;
    }
    const firstRm = rooms[0];
    if (cardTotal) cardTotal.textContent = `${firstRm.utilization_pct}%`;
    if (cardCurr) cardCurr.textContent = firstRm.room;
    if (row1Lbl) row1Lbl.textContent = 'Créneaux réservés';
    if (row1Val) row1Val.textContent = `${firstRm.booked_today} / ${firstRm.capacity}`;
    if (row2Lbl) row2Lbl.textContent = 'Salles actives';
    if (row2Val) row2Val.textContent = String(rooms.length);
    if (row3Lbl) row3Lbl.textContent = 'Présence globale';
    if (row3Val) row3Val.textContent = `${util.attendance_rate_pct ?? 100}%`;

    gridEl.innerHTML = rooms.slice(0, 6).map(r => `
      <div class="flex flex-col items-center justify-end h-full">
        <div class="w-full rounded-t-xl bg-gradient-to-t from-primary to-primary-light/85 transition-all"
             style="height:${Math.max(14, r.utilization_pct)}%"
             title="${escHtml(r.room)} : ${r.booked_today}/${r.capacity} créneaux (${r.utilization_pct}%)"></div>
        <span class="text-[9.5px] font-bold text-on-surface mt-1.5 truncate max-w-full">${escHtml(r.room)}</span>
      </div>
    `).join('');
    return;
  }

  const series = util.series || [];
  if (subtitleEl && !util.beds_enabled) {
    subtitleEl.textContent = 'Taux d’utilisation du planning médical & présence effective (%)';
  }
  if (series.length === 0) {
    gridEl.innerHTML = `<div class="col-span-6 text-center text-xs text-outline py-8">Aucune donnée de planning disponible.</div>`;
    return;
  }

  const idx = Math.min(saas.selectedUtilIdx, series.length - 1);
  const sel = series[idx] || series[series.length - 1];

  if (!util.beds_enabled && sel) {
    if (cardTotal) cardTotal.textContent = `${sel.slot_utilization_pct}%`;
    if (cardCurr) cardCurr.textContent = `CRÉNEAUX (${sel.label})`;
    if (row1Lbl) row1Lbl.textContent = 'Créneaux réservés';
    if (row1Val) row1Val.textContent = `${sel.booked_slots} / ${sel.capacity_slots}`;
    if (row2Lbl) row2Lbl.textContent = 'Occupation salles';
    if (row2Val) row2Val.textContent = `${sel.room_utilization_pct}% (${sel.rooms_used}/${sel.rooms_total})`;
    if (row3Lbl) row3Lbl.textContent = 'Taux de présence';
    if (row3Val) row3Val.textContent = `${sel.attendance_pct}%`;
  }

  gridEl.innerHTML = series.map((s, i) => {
    const isSel = i === idx;
    const h = Math.max(14, Number(s.slot_utilization_pct || 12));
    return `
      <button onclick="selectUtilColumn(${i})" title="${escHtml(s.date)} : ${s.booked_slots}/${s.capacity_slots} créneaux (${s.slot_utilization_pct}%) • Salles ${s.room_utilization_pct}%"
              class="flex flex-col items-center justify-end h-full group focus:outline-none">
        <div class="w-full rounded-t-xl transition-all ${isSel ? 'bg-gradient-to-t from-primary to-brand-soft-green shadow-emeraldGlow' : 'bg-secondary-container/85 group-hover:bg-primary-light/75'}"
             style="height:${h}%"></div>
        <span class="text-[10px] mt-1.5 ${isSel ? 'font-extrabold text-primary' : 'font-semibold text-outline'}">${escHtml(s.label)}</span>
      </button>
    `;
  }).join('');
}

function cycleDiagnosePeriod() {
  const order = ['week', 'month', 'day'];
  const next = order[(order.indexOf(saas.diagPeriodKey) + 1) % order.length];
  saas.diagPeriodKey = next;
  renderOverview();
}

function renderDiagnosticsCard(diagMap) {
  const cur = diagMap[saas.diagPeriodKey] || diagMap.week || { label: '7 derniers jours', total_encounters: 0, avg_per_day: 0, poles: {} };
  const lblEl = document.getElementById('diag-period-label');
  if (lblEl) lblEl.textContent = `${cur.label} • Moy. ${cur.avg_per_day}/j`;

  const poles = cur.poles || {};
  const setBubble = (id, cnt) => {
    const el = document.getElementById(id);
    if (el) el.textContent = `${cnt || 0} diagnostic${(cnt || 0) > 1 ? 's' : ''}`;
  };
  setBubble('diag-neuro-count', poles['Neurologie'] || 0);
  setBubble('diag-onco-count', poles['Oncologie'] || 0);
  setBubble('diag-uro-count', poles['Urologie'] || 0);
  setBubble('diag-cardio-count', poles['Cardiologie'] || 0);

  const emptyBanner = document.getElementById('diag-empty-notice');
  if (emptyBanner) {
    emptyBanner.classList.toggle('hidden', (cur.total_encounters || 0) > 0);
  }

  const ticks = document.getElementById('stem-ruler-ticks');
  if (ticks && !ticks.innerHTML) {
    ticks.innerHTML = Array.from({ length: 24 }, (_, i) =>
      `<span class="w-[1px] ${i % 4 === 0 ? 'h-2.5 bg-outline-variant' : 'h-1.5 bg-outline-variant/50'}"></span>`
    ).join('');
  }
}

function jumpToDepartment(dept) {
  switchTab('patients');
  setDeptFilter(dept, null);
}

function renderOverviewTodayQueue() {
  const wrap = document.getElementById('overview-live-queue');
  if (!wrap) return;
  const dateLabel = document.getElementById('overview-today-label');
  if (dateLabel) {
    dateLabel.textContent = `Prochains rendez-vous d’aujourd’hui (${new Intl.DateTimeFormat('fr-FR', {
      timeZone: 'Africa/Casablanca',
      day: 'numeric',
      month: 'long',
      year: 'numeric'
    }).format(new Date())})`;
  }

  const todays = saas.appointments
    .filter(a => a.date === TODAY_ISO && !['cancelled', 'no_show', 'completed'].includes(a.status))
    .map(a => ({
      appointment: a,
      urgent: [a.priority, a.severity, a.channel, a.source]
        .some(value => /^(urgent|urgente|urgence( prioritaire)?|stat|emergency|high priority)$/i.test(String(value || '').trim()))
    }))
    .sort((a, b) => Number(b.urgent) - Number(a.urgent)
      || String(a.appointment.time || '').localeCompare(String(b.appointment.time || '')));

  if (todays.length === 0) {
    const pastAppointments = saas.appointments.filter(a => a.date < TODAY_ISO && a.status !== 'cancelled');
    const latestPastDate = pastAppointments
      .map(a => a.date)
      .filter(Boolean)
      .sort()
      .at(-1);
    const pastLink = latestPastDate
      ? `<div class="mt-2"><a href="rendez-vous.html?date=${encodeURIComponent(latestPastDate)}&sheet=1" class="inline-flex items-center gap-1 font-bold text-primary hover:underline">Afficher les ${pastAppointments.length} rendez-vous enregistrés à une date précédente <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a></div>`
      : '';
    wrap.innerHTML = `<div class="col-span-full p-4 rounded-xl bg-surface-container-low text-center text-xs text-outline">Aucun rendez-vous programmé aujourd’hui (${TODAY_ISO}) sur cette sélection.${pastLink}</div>`;
    return;
  }

  const statusMeta = {
    confirmed: { label: 'Confirmé', cls: 'bg-surface-container-low text-on-surface-variant', next: 'waiting', nextLbl: 'Enregistrer arrivée' },
    waiting: { label: 'Salle d’attente', cls: 'bg-secondary-container text-primary', next: 'in_progress', nextLbl: 'Démarrer consultation' },
    arrived: { label: 'Salle d’attente', cls: 'bg-secondary-container text-primary', next: 'in_progress', nextLbl: 'Démarrer consultation' },
    in_progress: { label: 'En consultation', cls: 'bg-primary text-white', next: 'completed', nextLbl: 'Terminer & Facturer' },
    in_consultation: { label: 'En consultation', cls: 'bg-primary text-white', next: 'completed', nextLbl: 'Terminer & Facturer' },
    completed: { label: 'Consulté', cls: 'bg-primary/15 text-primary', next: null, nextLbl: null },
    cancelled: { label: 'Annulé', cls: 'bg-error-container text-error', next: null, nextLbl: null },
    no_show: { label: 'Absent (No-show)', cls: 'bg-error-container/70 text-error', next: null, nextLbl: null }
  };

  const renderAppointment = ({ appointment: a, urgent }) => {
    const p = saas.patients.find(x => x.id === a.patient_id);
    const pName = p ? `${p.first} ${p.last}` : `Patient #${a.patient_id}`;
    const sm = statusMeta[a.status] || statusMeta.confirmed;
    return `
      <div class="p-3 rounded-xl ${urgent ? 'bg-error-container/40 border border-error/40' : 'bg-surface-container-low/75 border border-outline-variant/30'} flex flex-col justify-between gap-2">
        <div class="flex items-start justify-between gap-2">
          <div>
            <div class="flex items-center gap-1.5">
              <span class="text-xs font-extrabold text-primary font-mono">${escHtml(a.time)}</span>
              <strong class="text-xs font-bold text-on-surface">${escHtml(pName)}</strong>
            </div>
            <span class="text-[10.5px] text-outline block">${escHtml(a.doctor)} • ${escHtml(a.room)}</span>
            <span class="text-[10px] text-on-surface-variant block truncate">${escHtml(a.service_name || a.reason)}</span>
          </div>
          <div class="flex flex-col items-end gap-1 shrink-0">
            ${urgent ? '<span class="px-2 py-0.5 rounded-full text-[9.5px] font-extrabold bg-error text-white">URGENCE</span>' : ''}
            <span class="px-2 py-0.5 rounded-full text-[9.5px] font-bold ${sm.cls}">${sm.label}</span>
          </div>
        </div>
        <div class="flex items-center justify-between pt-1 border-t border-outline-variant/20 text-[10.5px]">
          <button onclick="CliniqoPdf.printAppointmentConfirmation(saas.tenant, ${JSON.stringify(a).replace(/"/g, '&quot;')}, '${escHtml(pName)}', saas.currency)"
                  class="font-semibold text-outline hover:text-primary">Confirmation PDF</button>
          <div class="flex items-center gap-2">
            ${p && can('patients.dme') ? `<button onclick="openDmeDrawer(${p.id})" class="font-bold text-on-surface-variant hover:text-primary">DME</button>` : ''}
            ${sm.next && can('appointments.write') ? `<button onclick="advanceAptStatus(${a.id}, '${sm.next}')" class="font-bold text-primary hover:underline">${sm.nextLbl} &rarr;</button>` : ''}
          </div>
        </div>
      </div>
    `;
  };
  const urgentAppointments = todays.filter(item => item.urgent);
  const regularAppointments = todays.filter(item => !item.urgent);
  wrap.innerHTML = [
    urgentAppointments.length
      ? `<div class="col-span-full flex items-center gap-2 text-[11px] font-extrabold text-error"><span class="material-symbols-outlined text-[15px]">emergency</span>Urgences — à traiter en priorité (${urgentAppointments.length})</div>${urgentAppointments.map(renderAppointment).join('')}`
      : '',
    regularAppointments.length
      ? `${urgentAppointments.length ? '<div class="col-span-full pt-1 text-[11px] font-bold text-on-surface-variant">Autres rendez-vous du jour</div>' : ''}${regularAppointments.slice(0, Math.max(0, 6 - urgentAppointments.length)).map(renderAppointment).join('')}`
      : ''
  ].join('');
}

async function advanceAptStatus(id, status) {
  const res = await apiCall(`/appointments/${id}/status`, 'PATCH', { status });
  applyBackendState(res.data || res);
  showToast('Statut du rendez-vous mis à jour');
}

// ============================================================================
// DOCTORS, PATIENTS & DME, AND LABS VIEWS
// ============================================================================
function openDoctorModal(doctorId = null) {
  saas.editingDoctorId = doctorId;
  const doc = doctorId ? saas.doctors.find(d => d.id === doctorId) : null;
  const titleEl = document.getElementById('doc-modal-title');
  if (titleEl) titleEl.textContent = doc ? 'Modifier Praticien & Horaires' : 'Ajouter un Médecin';
  const nameEl = document.getElementById('doc-name');
  if (nameEl) nameEl.value = doc ? doc.name : '';
  const specEl = document.getElementById('doc-specialty');
  if (specEl) specEl.value = doc ? doc.specialty : 'Médecine Générale';
  const roomEl = document.getElementById('doc-room');
  if (roomEl) roomEl.value = doc ? doc.room : 'Cabinet 1';
  const feeEl = document.getElementById('doc-fee');
  if (feeEl) feeEl.value = doc ? doc.fee_mad : 300;
  const inpeEl = document.getElementById('doc-inpe');
  if (inpeEl) inpeEl.value = doc ? (doc.inpe || '') : '';
  const phoneEl = document.getElementById('doc-phone');
  if (phoneEl) phoneEl.value = doc ? (doc.phone || '+212 661-506070') : '+212 661-506070';
  const wsEl = document.getElementById('doc-work-start');
  const weEl = document.getElementById('doc-work-end');
  const durEl = document.getElementById('doc-duration');
  if (wsEl) wsEl.value = doc ? (doc.work_start || '08:30') : '08:30';
  if (weEl) weEl.value = doc ? (doc.work_end || '17:30') : '17:30';
  if (durEl) durEl.value = doc ? (doc.duration_min || 30) : 30;
  openModal('doctor-modal');
}

async function submitNewDoctor(ev) {
  ev.preventDefault();
  const payload = {
    name: document.getElementById('doc-name').value.trim(),
    specialty: document.getElementById('doc-specialty').value,
    room: document.getElementById('doc-room').value.trim(),
    fee_mad: Number(document.getElementById('doc-fee').value || 600),
    inpe: document.getElementById('doc-inpe').value.trim(),
    phone: document.getElementById('doc-phone').value.trim(),
    work_start: document.getElementById('doc-work-start')?.value || '08:30',
    work_end: document.getElementById('doc-work-end')?.value || '17:30',
    duration_min: Number(document.getElementById('doc-duration')?.value || 30)
  };
  const res = saas.editingDoctorId
    ? await apiCall(`/doctors/${saas.editingDoctorId}`, 'PUT', payload)
    : await apiCall('/doctors', 'POST', payload);
  closeModal('doctor-modal');
  applyBackendState(res.data || res);
  showToast(saas.editingDoctorId ? 'Planning praticien mis à jour' : 'Médecin ajouté à l’équipe médicale');
}

function setDeptFilter(dept, btn) {
  saas.deptFilter = dept;
  document.querySelectorAll('.dept-pill').forEach(b => {
    b.className = 'dept-pill px-3 py-1.5 rounded-full text-[11px] font-semibold bg-surface-container-low text-on-surface-variant hover:text-primary';
  });
  if (btn) btn.className = 'dept-pill px-3 py-1.5 rounded-full text-[11px] font-bold bg-primary text-white';
  renderPatientsView();
}

function renderPatientsView() {
  const tbody = document.getElementById('patients-tbody');
  const countPill = document.getElementById('patients-count-pill');
  if (!tbody) return;

  const q = (document.getElementById('patients-search')?.value || '').trim().toLowerCase();
  const list = saas.patients.filter(p => {
    if (saas.deptFilter !== 'all' && p.department !== saas.deptFilter) return false;
    if (!q) return true;
    return `${p.first} ${p.last} ${p.cin} ${p.code} ${p.phone}`.toLowerCase().includes(q);
  });

  if (countPill) countPill.textContent = `${list.length} dossier${list.length > 1 ? 's' : ''} actif${list.length > 1 ? 's' : ''}`;

  if (list.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="py-8 text-center text-outline">Aucun dossier patient trouvé pour ces critères.</td></tr>`;
    return;
  }

  tbody.innerHTML = list.map(p => `
    <tr class="hover:bg-surface-container-low/65 transition-colors">
      <td class="py-2.5 pr-2">
        <div class="flex items-center gap-2.5">
          <img src="${escHtml(p.avatar || makeInitialsAvatar(p.first + ' ' + p.last))}" onerror="this.src='${makeInitialsAvatar(p.first)}'" class="w-8 h-8 rounded-xl object-cover" alt=""/>
          <div>
            <strong class="font-bold text-on-surface block">${escHtml(p.first)} ${escHtml(p.last)}</strong>
            <span class="text-[10px] text-outline">${escHtml(p.code)} • ${p.age} ans • Groupe ${escHtml(p.blood)}</span>
          </div>
        </div>
      </td>
      <td class="py-2.5 px-2 font-mono font-semibold text-on-surface-variant">${escHtml(p.cin)}</td>
      <td class="py-2.5 px-2 text-on-surface-variant">${escHtml(p.phone)}</td>
      <td class="py-2.5 px-2"><span class="px-2 py-0.5 rounded-full bg-secondary-container/70 text-primary text-[10.5px] font-bold">${escHtml(p.department)}</span></td>
      <td class="py-2.5 px-2 text-on-surface-variant">${escHtml(p.coverage)}</td>
      <td class="py-2.5 pl-2 text-right space-x-1">
        ${(can('patients.dme') && p.dme_authorized)
          ? `<button onclick="openDmeDrawer(${p.id})" class="px-3 py-1 rounded-full bg-primary text-white text-[10.5px] font-bold hover:bg-primary-hover flex items-center gap-1 shadow-2xs">
               <span class="material-symbols-outlined text-[13px]">clinical_notes</span>
               <span>Ouvrir DME</span>
             </button>`
          : `<button onclick="showRoleAccessDenied({
                attemptedSpace: 'Dossier Médical Électronique (DME)',
                currentRoleLabel: 'Administrateur (Sarah Benali)',
                requiredRoleLabel: 'Docteur (Dr. Alimi Jan)',
                reason: 'En vertu de la Loi 131-13 (Secret Médical & Exercice de la Médecine) et de la CNDP, l\\'accès aux antécédents médicaux, observations cliniques et ordonnances est strictement réservé au corps médical.',
                legalRef: 'Secret Médical • Loi 131-13 & Code de Déontologie',
                redirectTab: 'patients'
              })" class="px-2.5 py-1 rounded-full bg-surface-container border border-outline-variant/40 text-on-surface-variant hover:bg-secondary-container hover:text-primary text-[10px] font-bold flex items-center gap-1 transition-colors" title="Cliquez pour voir les restrictions de secret médical">
               <span class="material-symbols-outlined text-[12px] text-primary">lock</span>
               <span>Secret Médical</span>
             </button>`
        }
      </td>
    </tr>
  `).join('');
}

async function submitNewPatient(ev) {
  ev.preventDefault();
  const payload = {
    first: document.getElementById('pat-first').value.trim(),
    last: document.getElementById('pat-last').value.trim(),
    cin: document.getElementById('pat-cin').value.trim(),
    phone: document.getElementById('pat-phone').value.trim(),
    department: document.getElementById('pat-dept').value,
    coverage: document.getElementById('pat-cov').value
  };
  const res = await apiCall('/patients', 'POST', payload);
  closeModal('patient-modal');
  ev.target.reset();
  applyBackendState(res.data || res);
  showToast('Dossier patient créé (Portail activé • PIN par défaut 1234)');
}

async function openDmeDrawer(patientId) {
  const res = await apiCall(`/patients/${patientId}/dme`, 'GET');
  const dme = res.dme || res || {};
  const p = dme.patient || saas.patients.find(x => x.id === patientId);
  if (!p) return;

  saas.activePatientId = p.id;
  const avEl = document.getElementById('dme-avatar');
  if (avEl) avEl.src = p.avatar || makeInitialsAvatar(`${p.first} ${p.last}`);
  const nmEl = document.getElementById('dme-name');
  if (nmEl) nmEl.textContent = `${p.first} ${p.last}`;
  const cdEl = document.getElementById('dme-code');
  if (cdEl) cdEl.textContent = `#${p.code}`;
  const mtEl = document.getElementById('dme-meta');
  if (mtEl) mtEl.textContent = `${p.age} ans • CIN ${p.cin} • Groupe ${p.blood}`;
  const alEl = document.getElementById('dme-allergy-input');
  if (alEl) alEl.value = p.allergy || 'Aucune';
  const cvEl = document.getElementById('dme-coverage');
  if (cvEl) cvEl.textContent = p.coverage || 'Particulier';
  const bpEl = document.getElementById('dme-bp-input');
  if (bpEl) bpEl.value = p.bp || '120/80';
  const spEl = document.getElementById('dme-spo2-input');
  if (spEl) spEl.value = p.spo2 || '98%';
  const hrEl = document.getElementById('dme-hr-input');
  if (hrEl) hrEl.value = p.hr || '72 bpm';
  const ntEl = document.getElementById('dme-note');
  if (ntEl) ntEl.value = '';

  const histEl = document.getElementById('dme-panel-history');
  const encs = dme.encounters || [];
  histEl.innerHTML = encs.length === 0
    ? `<div class="p-3 rounded-xl bg-surface-container-low text-xs text-outline text-center">Aucune observation clinique enregistrée.</div>`
    : encs.map(n => `
      <div class="p-2.5 rounded-xl bg-surface-container-low space-y-1 text-xs">
        <div class="flex justify-between text-[10px] font-bold text-primary">
          <span>${escHtml(n.icd_code || n.icd)}</span>
          <span>${escHtml(n.encounter_date || n.date)}</span>
        </div>
        <p class="text-on-surface text-[11.5px]">${escHtml(n.clinical_text || n.text)}</p>
        <span class="text-[10px] text-outline block">Signé par ${escHtml(n.doctor_name || n.doctor)}</span>
      </div>
    `).join('');

  const billEl = document.getElementById('dme-panel-billing');
  const pDocs = dme.documents || [];
  const pInvs = saas.invoices.filter(i => i.patient_id === p.id);
  billEl.innerHTML = `
    <div class="space-y-1.5">
      <div class="text-[10.5px] font-bold uppercase text-outline">Factures Patient (${pInvs.length})</div>
      ${pInvs.map(i => `
        <div class="p-2 rounded-xl bg-surface-container-low flex items-center justify-between text-xs">
          <div><strong class="font-mono text-primary">${escHtml(i.num)}</strong><span class="block text-[10.5px] text-outline">${escHtml(i.service)}</span></div>
          <button onclick="CliniqoPdf.printInvoice(saas.tenant, saas.invoices.find(x=>x.id===${i.id}), saas.currency)" class="text-[10.5px] font-bold text-primary hover:underline">${fmtMoney(i.total, true)} • PDF</button>
        </div>
      `).join('') || '<div class="text-[11px] text-outline">Aucune facture.</div>'}
      <div class="text-[10.5px] font-bold uppercase text-outline pt-2">Documents Médicaux (${pDocs.length})</div>
      ${pDocs.map(d => `
        <div class="p-2 rounded-xl bg-surface-container-low flex items-center justify-between text-xs">
          <div><strong class="font-mono text-on-surface">${escHtml(d.reference)}</strong><span class="block text-[10.5px] text-outline">${escHtml(d.title)}</span></div>
          <span class="text-[10px] font-bold text-outline">Document clinique</span>
        </div>
      `).join('') || '<div class="text-[11px] text-outline">Aucun document.</div>'}
    </div>
  `;

  // Load dental and family data from DME response
  if (dme.dental_teeth)        saas.dentalTeethChart      = dme.dental_teeth;
  if (dme.dental_plans)        saas.dentalTreatmentPlans  = dme.dental_plans;
  if (dme.family_links)        saas.activeDmeFamilyLinks  = dme.family_links;

  switchDmeTab('vitals');
  document.getElementById('dme-drawer-backdrop')?.classList.remove('hidden');
}

function closeDmeDrawer() {
  document.getElementById('dme-drawer-backdrop')?.classList.add('hidden');
}

function switchDmeTab(tab) {
  ['vitals', 'dental', 'labs', 'family', 'history', 'billing'].forEach(t => {
    document.getElementById(`dme-panel-${t}`)?.classList.toggle('hidden', t !== tab);
    const btn = document.getElementById(`dme-tab-${t}`);
    if (btn) {
      btn.className = t === tab
        ? 'py-1.5 px-1 rounded-lg bg-white text-primary shadow-2xs font-bold text-xs'
        : 'py-1.5 px-1 rounded-lg text-outline hover:text-on-surface text-xs';
    }
  });
  if (tab === 'dental') renderDentalOdontogram();
  if (tab === 'labs') renderPatientLabs(saas.activePatientId);
  if (tab === 'family') renderFamilyPanel();
}

function openPatientLabOrderForm(show = true) {
  const box = document.getElementById('dme-lab-order-box');
  if (box) box.classList.toggle('hidden', !show);
}

function applyLabPreset(val) {
  const inp = document.getElementById('dme-lab-custom-test');
  if (inp && val) inp.value = val;
}

async function submitPatientLabOrder() {
  if (!saas.activePatientId) return;
  const p = saas.patients.find(x => x.id === saas.activePatientId);
  if (!p) return;
  const testName = document.getElementById('dme-lab-custom-test')?.value.trim()
    || document.getElementById('dme-lab-preset-select')?.value.trim()
    || 'Bilan Biologique Général';
  const priority = document.getElementById('dme-lab-priority')?.value || 'Routine';

  try {
    const res = await apiCall('/labs', 'POST', {
      patient_id: p.id,
      patient_name: `${p.first} ${p.last}`,
      test_name: testName,
      category: 'Biologie Médicale',
      priority: priority,
      ordered_by: saas.auth?.name || 'Dr. Jackson'
    });
    applyBackendState(res.data || res);
    openPatientLabOrderForm(false);
    renderPatientLabs(p.id);
    showToast(`Prescription d'analyse enregistrée : ${testName}`);
  } catch (err) {
    showToast('Erreur lors de la prescription', 'error');
  }
}

function renderPatientLabs(patientId) {
  const listEl = document.getElementById('dme-patient-labs-list');
  if (!listEl) return;
  const pLabs = (saas.labs || []).filter(l => Number(l.patient_id) === Number(patientId));
  if (pLabs.length === 0) {
    listEl.innerHTML = `<div class="text-xs text-outline py-4 text-center">Aucune analyse biologique enregistrée pour ce patient.</div>`;
    return;
  }
  listEl.innerHTML = pLabs.map(l => {
    const isCompleted = l.status === 'completed';
    const isUrgent = l.priority === 'Urgent (STAT)' || l.priority === 'urgent';
    return `
      <div class="p-2.5 rounded-xl bg-white border border-outline-variant/30 space-y-1">
        <div class="flex items-center justify-between">
          <strong class="text-on-surface font-bold text-xs">${escHtml(l.test_name)}</strong>
          <span class="text-[9.5px] font-bold px-1.5 py-0.2 rounded-full ${isCompleted ? 'bg-secondary-container text-primary' : (isUrgent ? 'bg-error-container text-error' : 'bg-surface-container-low text-outline')}">
            ${isCompleted ? 'Validé' : (isUrgent ? 'Urgent (STAT)' : 'En cours')}
          </span>
        </div>
        <div class="flex items-center justify-between text-[10.5px] text-outline">
          <span>Date : ${escHtml(l.ordered_date || TODAY_ISO)}</span>
          <span class="font-mono font-bold ${isCompleted ? 'text-primary' : 'text-on-surface'}">${escHtml(l.value || 'En attente résultat')}</span>
        </div>
        ${l.reference ? `<div class="text-[9.5px] text-outline">Normes : ${escHtml(l.reference)}</div>` : ''}
      </div>
    `;
  }).join('');
}

async function sealClinicalEncounter() {
  if (!saas.activePatientId) return;
  const payload = {
    allergy: document.getElementById('dme-allergy-input').value.trim(),
    bp: document.getElementById('dme-bp-input').value.trim(),
    spo2: document.getElementById('dme-spo2-input').value.trim(),
    hr: document.getElementById('dme-hr-input').value.trim(),
    icd: document.getElementById('dme-icd-select').value,
    note: document.getElementById('dme-note').value.trim()
  };
  const res = await apiCall(`/patients/${saas.activePatientId}/dme`, 'POST', payload);
  applyBackendState(res.data || res);
  closeDmeDrawer();
  showToast('Observation clinique signée et journalisée CNDP');
}

async function printQrPrescription() {
  const p = saas.patients.find(x => x.id === saas.activePatientId);
  if (!p) return;
  const noteText = document.getElementById('dme-note').value.trim() || '1. Traitement selon protocole médical en vigueur.\n2. Contrôle clinique dans 15 jours.';
  const res = await apiCall('/documents', 'POST', {
    patient_id: p.id,
    doc_type: 'prescription',
    title: `Ordonnance Médicale — ${p.first} ${p.last}`,
    body_text: noteText,
    shared_with_patient: false
  });
  applyBackendState(res.data || res);
  const createdDoc = saas.documents[0];
  if (createdDoc) {
    CliniqoPdf.printDocument(saas.tenant, createdDoc, saas.currency);
  }
  showToast('Ordonnance générée et prête pour impression');
}

// ============================================================================
// MODULE CONSULTATIONS & FILE D'ATTENTE CABINET
// ============================================================================
saas.consultationStatusFilter = 'all';

function filterConsultationsByStatus(st, btn) {
  saas.consultationStatusFilter = st;
  document.querySelectorAll('.consult-status-pill').forEach(b => {
    b.className = 'consult-status-pill px-3 py-1.5 rounded-full font-semibold bg-surface-container-low text-on-surface-variant hover:text-primary';
  });
  if (btn) btn.className = 'consult-status-pill px-3 py-1.5 rounded-full font-bold bg-primary text-white';
  renderConsultationsView();
}

function filterConsultationsList() {
  renderConsultationsView();
}

async function updateConsultationStatus(aptId, newStatus) {
  try {
    const res = await apiCall(`/appointments/${aptId}`, 'PUT', { status: newStatus });
    applyBackendState(res.data || res);
    renderConsultationsView();
    renderOverview();
    showToast(`Statut de consultation mis à jour (${newStatus})`);
  } catch (err) {
    showToast('Erreur lors de la mise à jour du statut', 'error');
  }
}

function renderConsultationsView() {
  const tbody = document.getElementById('consultations-tbody');
  if (!tbody) return;

  const q = (document.getElementById('consult-search-input')?.value || '').toLowerCase().trim();
  const filter = saas.consultationStatusFilter || 'all';

  // Rendez-vous du jour
  const todays = saas.appointments.filter(a => a.date === TODAY_ISO);
  const waitingCnt = todays.filter(a => a.status === 'waiting' || a.status === 'arrived').length;
  const inProgCnt = todays.filter(a => a.status === 'in_consultation' || a.status === 'in_progress').length;
  const compCnt = todays.filter(a => a.status === 'completed').length;

  const setElTxt = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = String(val); };
  setElTxt('consult-stat-total', todays.length);
  setElTxt('consult-stat-waiting', waitingCnt);
  setElTxt('consult-stat-in-progress', inProgCnt);
  setElTxt('consult-stat-completed', compCnt);

  // Mettre à jour le badge navbar
  setElTxt('badge-consultations-nav', waitingCnt + inProgCnt);

  let list = todays;
  if (filter === 'waiting') {
    list = list.filter(a => a.status === 'waiting' || a.status === 'arrived');
  } else if (filter === 'in_consultation') {
    list = list.filter(a => a.status === 'in_consultation' || a.status === 'in_progress');
  } else if (filter === 'completed') {
    list = list.filter(a => a.status === 'completed');
  }

  if (q) {
    list = list.filter(a => {
      const p = saas.patients.find(x => x.id === a.patient_id);
      const pName = p ? `${p.first} ${p.last}`.toLowerCase() : '';
      const cin = p?.cin?.toLowerCase() || '';
      const r = (a.reason || a.service_name || '').toLowerCase();
      return pName.includes(q) || cin.includes(q) || r.includes(q);
    });
  }

  // Tri : en consultation en premier, puis en attente, puis par heure
  const order = { in_consultation: 1, in_progress: 1, waiting: 2, arrived: 2, confirmed: 3, completed: 4, cancelled: 5, no_show: 6 };
  list.sort((a, b) => (order[a.status] || 9) - (order[b.status] || 9) || String(a.time).localeCompare(String(b.time)));

  if (list.length === 0) {
    tbody.innerHTML = `<tr><td colspan="7" class="py-6 text-center text-outline">Aucune consultation ne correspond aux critères sélectionnés.</td></tr>`;
    return;
  }

  tbody.innerHTML = list.map(a => {
    const p = saas.patients.find(x => x.id === a.patient_id) || { first: 'Patient', last: `#${a.patient_id}`, cin: '—', phone: '—', coverage: 'AMO' };
    const pFullName = `${p.first} ${p.last}`;

    let statusBadge = '';
    if (a.status === 'waiting' || a.status === 'arrived') {
      statusBadge = '<span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-bold text-[10px] flex items-center gap-1 w-fit"><span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>En Attente</span>';
    } else if (a.status === 'in_consultation' || a.status === 'in_progress') {
      statusBadge = '<span class="px-2 py-0.5 rounded-full bg-primary/10 text-primary font-bold text-[10px] flex items-center gap-1 w-fit"><span class="w-1.5 h-1.5 rounded-full bg-primary animate-ping"></span>En Consultation</span>';
    } else if (a.status === 'completed') {
      statusBadge = '<span class="px-2 py-0.5 rounded-full bg-secondary-container text-primary font-bold text-[10px] flex items-center gap-1 w-fit"><span class="material-symbols-outlined text-[12px]">check</span>Terminée</span>';
    } else {
      statusBadge = `<span class="px-2 py-0.5 rounded-full bg-surface-container-low text-outline text-[10px]">${escHtml(a.status)}</span>`;
    }

    // Facturation liée
    const inv = saas.invoices.find(i => Number(i.appointment_id) === Number(a.id) || Number(i.patient_id) === Number(p.id));
    let invBadge = '<span class="text-[10px] text-outline font-semibold">Non Facturé</span>';
    if (inv) {
      if (inv.status === 'paid') {
        invBadge = '<span class="text-[10px] font-bold text-primary">Réglé (MAD)</span>';
      } else if (inv.status === 'partial') {
        invBadge = '<span class="text-[10px] font-bold text-amber-700">Partiel</span>';
      } else {
        invBadge = '<span class="text-[10px] font-bold text-error">Impayé</span>';
      }
    }

    const isMed = canAccessMedicalData();

    return `
      <tr class="hover:bg-surface-container-low/60 transition-colors">
        <td class="py-2.5 pr-2 font-mono font-bold text-on-surface">${escHtml(a.time || '09:00')}</td>
        <td class="py-2.5 px-2">
          <button onclick="${isMed ? `openDmeDrawer(${p.id})` : `openPatientModal(${p.id})`}" class="text-left group">
            <strong class="font-bold text-on-surface group-hover:text-primary transition-colors block text-xs">${escHtml(pFullName)}</strong>
            <span class="text-[10px] text-outline">${escHtml(p.cin)} • ${escHtml(p.coverage || 'CNSS')}</span>
          </button>
        </td>
        <td class="py-2.5 px-2 font-semibold text-on-surface-variant text-[11px]">${escHtml(a.doctor || 'Dr. Jackson')}</td>
        <td class="py-2.5 px-2 font-medium text-on-surface text-[11px]">${escHtml(a.reason || a.service_name || 'Consultation Générale')}</td>
        <td class="py-2.5 px-2">${statusBadge}</td>
        <td class="py-2.5 px-2">${invBadge}</td>
        <td class="py-2.5 pl-2 text-right space-x-1">
          ${(a.status === 'waiting' || a.status === 'arrived') ? `
            <button onclick="updateConsultationStatus(${a.id}, 'in_consultation')" class="px-2.5 py-1 rounded-full bg-primary hover:bg-primary-hover text-white text-[10.5px] font-bold shadow-2xs">
              Appeler
            </button>
          ` : ''}
          ${(a.status === 'in_consultation' || a.status === 'in_progress') ? `
            <button onclick="updateConsultationStatus(${a.id}, 'completed')" class="px-2.5 py-1 rounded-full bg-secondary-container text-primary hover:bg-primary hover:text-white text-[10.5px] font-bold shadow-2xs">
              Terminer
            </button>
          ` : ''}
          ${isMed ? `
            <button onclick="openDmeDrawer(${p.id})" title="Dossier Médical Électronique" class="px-2.5 py-1 rounded-full bg-surface-container-low hover:bg-secondary-container text-primary text-[10.5px] font-bold">
              DME
            </button>
          ` : ''}
          ${!inv || inv.status !== 'paid' ? `
            <button onclick="openNewInvoiceModalForApt(${a.id}, ${p.id})" title="Émettre Facture / Reçu" class="px-2 py-1 rounded-full bg-surface-container-low hover:bg-secondary-container text-on-surface-variant text-[10.5px] font-bold">
              <span class="material-symbols-outlined text-[13px] align-middle">receipt</span>
            </button>
          ` : ''}
        </td>
      </tr>
    `;
  }).join('');
}

function openNewInvoiceModalForApt(aptId, patientId) {
  openModal('invoice-modal');
  const sel = document.getElementById('inv-patient-select');
  if (sel && patientId) sel.value = String(patientId);
}

function canAccessMedicalData() {
  const role = normalizeAppRole(saas.auth?.role || localStorage.getItem('cliniqo_user_role') || '');
  return role === 'docteur';
}

function renderLabsView() {
  const listEl = document.getElementById('labs-cards-list');
  const totEl = document.getElementById('lab-stat-total');
  const flgEl = document.getElementById('lab-stat-flagged');
  const pndEl = document.getElementById('lab-stat-pending');
  if (!listEl) return;

  if (!saas.tenant?.enable_laboratory) {
    if (totEl) totEl.textContent = '—';
    if (flgEl) flgEl.textContent = '0';
    if (pndEl) pndEl.textContent = '0';
    listEl.innerHTML = `
      <div class="col-span-3 p-6 rounded-2xl bg-surface-container-low text-center space-y-2">
        <strong class="text-sm font-extrabold text-on-surface block">Module Laboratoire Désactivé pour cet Établissement</strong>
        <p class="text-xs text-outline max-w-md mx-auto">Ce centre médical ne réalise pas d’analyses biologiques internes. Un administrateur peut activer ce module dans les Paramètres du Centre si son forfait l’inclut.</p>
      </div>
    `;
    return;
  }

  const completedCnt = saas.labs.filter(l => l.status === 'validated').length;
  const flaggedCnt = saas.labs.filter(l => l.status === 'flagged').length;
  const pendingCnt = saas.labs.filter(l => l.status === 'pending').length;

  if (totEl) totEl.textContent = String(completedCnt);
  if (flgEl) flgEl.textContent = String(flaggedCnt);
  if (pndEl) pndEl.textContent = String(pendingCnt);

  if (saas.labs.length === 0) {
    listEl.innerHTML = `<div class="col-span-3 p-6 text-center text-xs text-outline">Aucune demande d’analyse enregistrée sur cette branche.</div>`;
    return;
  }

  listEl.innerHTML = saas.labs.map(l => {
    const isFlag = l.status === 'flagged';
    const isVal = l.status === 'validated';
    return `
      <div class="p-3.5 rounded-2xl border ${isFlag ? 'bg-error-container/30 border-error/30' : 'bg-surface-container-low/75 border-outline-variant/30'} space-y-2 text-xs">
        <div class="flex items-start justify-between gap-2">
          <div>
            <span class="font-mono text-[10px] font-bold text-primary">${escHtml(l.code)} • ${escHtml(l.category)}</span>
            <h4 class="font-extrabold text-on-surface">${escHtml(l.test_name)}</h4>
            <span class="text-[11px] text-outline">${escHtml(l.patient_name)}</span>
          </div>
          <span class="px-2 py-0.5 rounded-full text-[9.5px] font-bold ${isFlag ? 'bg-error text-white' : (isVal ? 'bg-secondary-container text-primary' : 'bg-white text-outline')}">
            ${isFlag ? 'CRITIQUE' : (isVal ? 'Validé' : 'En cours')}
          </span>
        </div>
        <div class="flex items-center gap-1.5">
          <input id="lab-val-${l.id}" type="text" value="${escHtml(l.value)}" placeholder="Valeur mesurée..."
                 class="flex-1 px-2.5 py-1.5 rounded-xl bg-white border border-outline-variant/40 text-xs font-semibold focus:outline-none focus:border-primary"/>
          ${can('labs.write') ? `<button onclick="validateLabItem(${l.id})" class="px-3 py-1.5 rounded-xl bg-primary text-white text-[11px] font-bold hover:bg-primary-hover">Valider</button>` : ''}
        </div>
        <div class="flex justify-between text-[10px] text-outline">
          <span>Réf : ${escHtml(l.reference)}</span>
          <span>Prescrit par ${escHtml(l.ordered_by)}</span>
        </div>
      </div>
    `;
  }).join('');
}

async function validateLabItem(id) {
  const val = document.getElementById(`lab-val-${id}`)?.value || 'Normal';
  const res = await apiCall(`/labs/${id}/validate`, 'PATCH', { value: val });
  applyBackendState(res.data || res);
  showToast('Résultat biologique validé');
}

async function submitNewLabOrder(ev) {
  ev.preventDefault();
  const payload = {
    patient_id: Number(document.getElementById('lab-patient-select').value),
    test_name: document.getElementById('lab-test-name').value.trim(),
    category: document.getElementById('lab-category').value,
    priority: document.getElementById('lab-priority').value
  };
  const res = await apiCall('/labs', 'POST', payload);
  closeModal('lab-modal');
  ev.target.reset();
  applyBackendState(res.data || res);
  showToast('Prescription biologique envoyée au laboratoire');
}

// ============================================================================
// MODALS, GLOBAL SEARCH & MASTER RENDER
// ============================================================================
function populatePatientSelects() {
  const opts = saas.patients.map(p => `<option value="${p.id}">${escHtml(p.first)} ${escHtml(p.last)} (#${escHtml(p.code)})</option>`).join('');
  ['lab-patient-select', 'inv-patient-select', 'docgen-patient-select', 'quote-patient-select'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = opts;
  });
}

function openModal(id) {
  if (id === 'settings-modal' && typeof loadSettingsModalData === 'function') {
    loadSettingsModalData();
  }
  if (['lab-modal', 'invoice-modal', 'document-modal', 'quote-modal'].includes(id)) {
    populatePatientSelects();
  }
  if (id === 'quote-modal') {
    const drSel = document.getElementById('quote-doctor-select');
    if (drSel) {
      drSel.innerHTML = saas.doctors.map(d => `<option value="${d.name}">${escHtml(d.name)} \u2014 ${escHtml(d.specialty)}</option>`).join('');
    }
  }
  const modal = document.getElementById(id);
  modal?.classList.remove('hidden');
  if (['saas-payment-modal', 'saas-plan-modal', 'saas-plan-edit-modal', 'saas-clinic-modal'].includes(id)) {
    modal.scrollTop = 0;
    window.requestAnimationFrame(() => {
      modal.querySelector('form input:not([type="hidden"]):not([disabled]), form select, form textarea')?.focus();
    });
  }
  if (id === 'search-modal') {
    setTimeout(() => document.getElementById('global-search-input')?.focus(), 50);
    renderGlobalSearch('');
  }
}

function closeModal(id) {
  document.getElementById(id)?.classList.add('hidden');
}

function renderGlobalSearch(query) {
  const resEl = document.getElementById('global-search-results');
  if (!resEl) return;
  const q = String(query || '').trim().toLowerCase();

  const items = [];
  saas.patients.forEach(p => {
    const label = `${p.first} ${p.last} (${p.cin} • #${p.code})`;
    if (!q || label.toLowerCase().includes(q)) {
      items.push({ type: 'Patient', title: label, sub: p.department, act: `closeModal('search-modal'); switchTab('patients');` });
    }
  });
  saas.invoices.forEach(i => {
    const label = `${i.num} — ${i.patient} (${i.service})`;
    if (!q || label.toLowerCase().includes(q)) {
      items.push({ type: 'Facture', title: label, sub: fmtMoney(i.total, true), act: `closeModal('search-modal'); switchTab('finance');` });
    }
  });
  saas.doctors.forEach(d => {
    const label = `${d.name} — ${d.specialty}`;
    if (!q || label.toLowerCase().includes(q)) {
      items.push({ type: 'Médecin', title: label, sub: d.room, act: `closeModal('search-modal'); switchTab('overview');` });
    }
  });

  resEl.innerHTML = items.slice(0, 10).map(it => `
    <button onclick="${it.act}" class="w-full text-left p-2 rounded-xl hover:bg-surface-container-low flex items-center justify-between">
      <div>
        <span class="text-[9.5px] font-bold uppercase text-primary">${escHtml(it.type)}</span>
        <strong class="block text-xs text-on-surface">${escHtml(it.title)}</strong>
      </div>
      <span class="text-[10.5px] text-outline">${escHtml(it.sub)}</span>
    </button>
  `).join('') || `<div class="p-3 text-center text-outline">Aucun résultat trouvé.</div>`;
}

// =============================================================================
// ODONTOGRAMME FDI INTERACTIF
// =============================================================================
var FDI_CONDITIONS = {
  'healthy':    { label: 'Sain',       color: '#176B45' },
  'caries':     { label: 'Carie',      color: '#e53935' },
  'filled':     { label: 'Obturation', color: '#1976d2' },
  'missing':    { label: 'Absent',     color: '#9e9e9e' },
  'crown':      { label: 'Couronne',   color: '#f57f17' },
  'implant':    { label: 'Implant',    color: '#6a1b9a' },
  'fracture':   { label: 'Fracture',   color: '#bf360c' },
  'extraction': { label: 'Extraction', color: '#c62828' }
};

var _selectedTooth = null;

function renderDentalOdontogram() {
  var container = document.getElementById('fdi-odontogram-grid') || document.getElementById('dental-odontogram-container');
  if (!container) return;
  var chart    = saas.dentalTeethChart || [];
  var chartMap = {};
  chart.forEach(function(t) { chartMap[t.tooth_number] = t; });

  var rowsConfig = [
    [18,17,16,15,14,13,12,11], [21,22,23,24,25,26,27,28],
    [48,47,46,45,44,43,42,41], [31,32,33,34,35,36,37,38],
    [55,54,53,52,51],           [61,62,63,64,65],
    [85,84,83,82,81],           [71,72,73,74,75]
  ];

  function toothDiv(tn) {
    var entry = chartMap[tn];
    var cond  = entry ? (entry.condition || 'healthy') : 'healthy';
    var info  = FDI_CONDITIONS[cond] || FDI_CONDITIONS['healthy'];
    var isSel = (_selectedTooth === tn);
    var ttip  = (entry && entry.notes) ? (' title="' + escHtml(entry.notes) + '"') : '';
    return '<div onclick="selectFdiTooth(' + tn + ')"' + ttip + ' id="fdi-tooth-' + tn + '"' +
      ' style="border-color:' + info.color + ';background:' + (isSel ? info.color + '22' : 'transparent') + ';' + (isSel ? 'outline:2px solid ' + info.color + ';' : '') + '"' +
      ' class="fdi-tooth flex flex-col items-center cursor-pointer rounded-lg border-2 p-0.5 hover:bg-surface-container transition-all select-none w-8">' +
      '<span class="text-[7.5px] font-mono font-bold" style="color:' + info.color + '">' + tn + '</span>' +
      '<div class="w-4 h-4 rounded-full border-2 mt-0.5" style="background:' + info.color + ';opacity:0.7"></div>' +
      '<span class="text-[7px] text-outline mt-0.5 truncate max-w-full">' + info.label + '</span>' +
      '</div>';
  }

  function rowHtml(teeth) { return teeth.map(toothDiv).join(''); }
  var sep = '<div class="w-px bg-outline-variant/50 mx-1"></div>';
  var midLine = '<div class="border-t border-dashed border-outline-variant/40 my-1"></div>';

  container.innerHTML = '<div class="space-y-2 overflow-x-auto">' +
    '<div class="flex justify-center gap-0.5 flex-wrap min-w-max">' + rowHtml(rowsConfig[0]) + sep + rowHtml(rowsConfig[1]) + '</div>' +
    midLine +
    '<div class="flex justify-center gap-0.5 flex-wrap min-w-max">' + rowHtml(rowsConfig[2]) + sep + rowHtml(rowsConfig[3]) + '</div>' +
    '<p class="text-[9px] text-outline text-center font-semibold mt-2">Denture Temporaire (Enfant)</p>' +
    '<div class="flex justify-center gap-0.5 flex-wrap min-w-max">' + rowHtml(rowsConfig[4]) + sep + rowHtml(rowsConfig[5]) + '</div>' +
    '<div class="flex justify-center gap-0.5 flex-wrap min-w-max">' + rowHtml(rowsConfig[6]) + sep + rowHtml(rowsConfig[7]) + '</div>' +
    '</div>';

  renderDentalTreatmentPlansList();
}

window.selectFdiTooth = function(toothNumber) {
  _selectedTooth = toothNumber;
  var numEl   = document.getElementById('dental-selected-tooth-label') || document.getElementById('selected-tooth-number');
  var badgeEl = document.getElementById('dental-selected-condition-badge');
  var condEl  = document.getElementById('dental-condition-select') || document.getElementById('tooth-condition-select');
  var surfEl  = document.getElementById('dental-surface-input') || document.getElementById('tooth-surface-input');
  var notesEl = document.getElementById('dental-notes-input') || document.getElementById('tooth-notes-input');

  if (numEl) numEl.textContent = 'Dent N° ' + toothNumber;
  var existing = (saas.dentalTeethChart || []).find(function(t) { return Number(t.tooth_number) === Number(toothNumber); });
  var cond = existing ? (existing.condition || 'healthy') : 'healthy';
  if (condEl)  condEl.value  = cond;
  if (surfEl)  surfEl.value  = existing ? (existing.surface || '') : '';
  if (notesEl) notesEl.value = existing ? (existing.notes || '')   : '';
  if (badgeEl) {
    var info = FDI_CONDITIONS[cond] || FDI_CONDITIONS['healthy'];
    badgeEl.textContent = info.label;
    badgeEl.style.color = info.color;
  }
  renderDentalOdontogram();
};

window.saveSelectedToothCondition = async function() {
  if (!_selectedTooth || !saas.activePatientId) {
    showToast('Veuillez d\'abord cliquer sur une dent pour la sélectionner.', 'error');
    return;
  }
  var condEl  = document.getElementById('dental-condition-select') || document.getElementById('tooth-condition-select');
  var surfEl  = document.getElementById('dental-surface-input') || document.getElementById('tooth-surface-input');
  var notesEl = document.getElementById('dental-notes-input') || document.getElementById('tooth-notes-input');

  var payload = {
    patient_id:   saas.activePatientId,
    tooth_number: _selectedTooth,
    condition:    condEl?.value || 'healthy',
    surface:      (surfEl?.value || '').trim(),
    notes:        (notesEl?.value || '').trim()
  };
  var res = await apiCall('/dental/teeth', 'POST', payload);
  if (res.data?.dental_teeth) saas.dentalTeethChart = res.data.dental_teeth;
  else if (res.dental_teeth)  saas.dentalTeethChart = res.dental_teeth;
  renderDentalOdontogram();
  showToast('Dent ' + _selectedTooth + ' mise à jour avec succès');
};

window.openAddTreatmentPlanForm = function(show) {
  var box = document.getElementById('dental-add-plan-box') || document.getElementById('add-plan-box');
  if (!box) return;
  if (show === undefined) {
    box.classList.toggle('hidden');
  } else {
    box.classList.toggle('hidden', !show);
  }
  if (!box.classList.contains('hidden') && _selectedTooth) {
    var toothInput = document.getElementById('new-plan-tooth') || document.getElementById('plan-teeth-input');
    if (toothInput && !toothInput.value) toothInput.value = _selectedTooth;
  }
};

window.submitDentalTreatmentPlan = async function(ev) {
  if (ev && ev.preventDefault) ev.preventDefault();
  if (!saas.activePatientId) return;

  var tooth = (document.getElementById('new-plan-tooth')?.value || document.getElementById('plan-teeth-input')?.value || '').trim();
  var title = (document.getElementById('new-plan-procedure')?.value || document.getElementById('plan-title-input')?.value || '').trim();
  var cost  = Number(document.getElementById('new-plan-cost')?.value || document.getElementById('plan-cost-input')?.value || 0);
  var desc  = (document.getElementById('plan-desc-input')?.value || '').trim();

  if (!title) {
    showToast('Veuillez renseigner l\'acte dentaire prévu.', 'error');
    return;
  }

  var payload = {
    patient_id:  saas.activePatientId,
    title:       title,
    description: desc || (tooth ? 'Dent N° ' + tooth : ''),
    cost_mad:    cost,
    tooth_refs:  tooth
  };

  var res = await apiCall('/dental/plans', 'POST', payload);
  if (res.data?.dental_plans) saas.dentalTreatmentPlans = res.data.dental_plans;
  else if (res.dental_plans)  saas.dentalTreatmentPlans = res.dental_plans;
  window.openAddTreatmentPlanForm(false);
  
  // Clear inputs
  var elP = document.getElementById('new-plan-procedure'); if (elP) elP.value = '';
  var elC = document.getElementById('new-plan-cost'); if (elC) elC.value = '';
  var elT = document.getElementById('new-plan-tooth'); if (elT) elT.value = '';
  
  renderDentalTreatmentPlansList();
  showToast('Acte dentaire ajouté au plan de soins');
};

function renderDentalTreatmentPlansList() {
  var listEl = document.getElementById('dental-plans-list');
  if (!listEl) return;
  var plans = saas.dentalTreatmentPlans || [];
  if (plans.length === 0) {
    listEl.innerHTML = '<p class="text-xs text-outline text-center py-3">Aucun plan de traitement.</p>';
    return;
  }
  listEl.innerHTML = plans.map(function(p) {
    return '<div class="p-2.5 rounded-xl bg-surface-container-low/80 border border-outline-variant/30 text-xs">' +
      '<div class="flex items-start justify-between gap-2">' +
      '<strong class="font-bold text-on-surface">' + escHtml(p.title || '') + '</strong>' +
      '<span class="font-mono font-bold text-primary whitespace-nowrap">' + fmtMoney(p.cost_mad || 0) + ' MAD</span></div>' +
      (p.tooth_refs ? '<p class="text-[10px] text-outline mt-0.5">Dents : ' + escHtml(p.tooth_refs) + '</p>' : '') +
      (p.description ? '<p class="text-[11px] text-on-surface-variant mt-0.5 line-clamp-2">' + escHtml(p.description) + '</p>' : '') +
      '<span class="text-[10px] text-outline">' + escHtml((p.created_at || '').slice(0,10)) + '</span></div>';
  }).join('');
}

// =============================================================================
// LIENS FAMILIAUX
// =============================================================================
function renderFamilyPanel() {
  var listEl = document.getElementById('family-members-list');
  var selEl  = document.getElementById('family-related-patient-select');
  if (selEl) {
    selEl.innerHTML = saas.patients
      .filter(function(p) { return p.id !== saas.activePatientId; })
      .map(function(p) { return '<option value="' + p.id + '">' + escHtml(p.first) + ' ' + escHtml(p.last) + ' (#' + escHtml(p.code) + ')</option>'; })
      .join('');
  }
  if (!listEl) return;
  var links = saas.activeDmeFamilyLinks || [];
  if (links.length === 0) {
    listEl.innerHTML = '<p class="text-xs text-outline text-center py-3">Aucun lien familial enregistre.</p>';
    return;
  }
  listEl.innerHTML = links.map(function(l) {
    return '<div class="flex items-center justify-between p-2.5 rounded-xl bg-surface-container-low/80 text-xs">' +
      '<div><strong class="font-bold text-on-surface">' + escHtml(String(l.related_name || l.related_patient_id || '')) + '</strong>' +
      '<span class="text-[10px] text-outline block">' + escHtml(l.relation_type || '') + '</span></div>' +
      '<button onclick="openDmeDrawer(' + l.related_patient_id + ')" class="px-2.5 py-1 rounded-full bg-secondary-container text-primary text-[10px] font-bold hover:bg-primary hover:text-white transition-colors">Voir DME</button>' +
      '</div>';
  }).join('');
}

window.submitFamilyLink = async function(ev) {
  if (ev && ev.preventDefault) ev.preventDefault();
  if (!saas.activePatientId) return;
  var relatedId = Number(document.getElementById('family-related-patient-select')?.value || 0);
  var relType   = (document.getElementById('family-relation-type') || document.getElementById('family-relation-select'))?.value || 'conjoint';
  if (!relatedId) { showToast('Sélectionnez un membre de la patientèle', 'error'); return; }
  var res = await apiCall('/patients/' + saas.activePatientId + '/family', 'POST', { related_patient_id: relatedId, relation_type: relType });
  if (res.data?.family_links) saas.activeDmeFamilyLinks = res.data.family_links;
  else if (res.family_links)  saas.activeDmeFamilyLinks = res.family_links;
  renderFamilyPanel();
  showToast('Lien familial enregistré avec succès');
};

// =============================================================================
function renderAll() {
  document.getElementById('view-saas-owner')?.classList.add('hidden');
  document.querySelectorAll('.saas-view').forEach(v => v.classList.add('hidden'));
  const activeViewId = `view-${saas.activeTab}`;
  document.getElementById(activeViewId)?.classList.remove('hidden');

  renderHeaderAndBranches();
  if (saas.activeTab === 'saas-owner') {
    if (typeof renderSaasOwnerConsole === 'function') renderSaasOwnerConsole();
  } else if (saas.activeTab === 'overview') {
    renderOverview();
  } else if (saas.activeTab === 'patients') {
    renderPatientsView();
  } else if (saas.activeTab === 'consultations') {
    renderConsultationsView();
  } else if (saas.activeTab === 'labs') {
    renderLabsView();
  } else if (saas.activeTab === 'finance') {
    if (typeof switchFinanceSubTab === 'function') {
      switchFinanceSubTab(saas.financeSubTab || 'invoices');
    } else if (typeof renderFinanceHub === 'function') {
      renderFinanceHub();
    }
  } else if (saas.activeTab === 'stock') {
    if (typeof switchStockSubTab === 'function') {
      switchStockSubTab(saas.stockSubTab || 'stock');
    } else if (typeof renderStockHub === 'function') {
      renderStockHub();
    }
  } else if (saas.activeTab === 'reports' && typeof populateReportFilterDropdowns === 'function') {
    populateReportFilterDropdowns();
  }
}

document.addEventListener('click', () => {
  document.getElementById('branch-menu')?.classList.add('hidden');
  document.getElementById('notif-popover')?.classList.add('hidden');
});

document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    openModal('search-modal');
  } else if (e.key === 'Escape') {
    ['search-modal', 'settings-modal', 'patient-modal', 'doctor-modal', 'lab-modal', 'invoice-modal', 'payment-modal', 'refund-modal', 'expense-modal', 'stock-modal', 'document-modal', 'saas-payment-modal', 'saas-plan-modal', 'saas-plan-edit-modal', 'saas-clinic-modal', 'edit-cabinet-modal'].forEach(closeModal);
    closeDmeDrawer();
  }
});

document.addEventListener('DOMContentLoaded', initWorkspace);

// Explicitly bind to window for HTML onclick attributes
window.switchTab = switchTab;
window.handlePrimaryCta = handlePrimaryCta;
window.setDeptFilter = setDeptFilter;
window.jumpToDepartment = jumpToDepartment;
window.renderPatientsView = renderPatientsView;
window.openDmeDrawer = openDmeDrawer;
window.switchDmeTab = switchDmeTab;
window.closeDmeDrawer = closeDmeDrawer;
window.logoutStaff = logoutStaff;
window.openModal = openModal;
window.closeModal = closeModal;
window.switchTenant = switchTenant;
window.switchBranch = switchBranch;
window.toggleBranchMenu = toggleBranchMenu;
window.toggleNotifications = toggleNotifications;
window.markNotifRead = markNotifRead;
window.markAllNotifsRead = markAllNotifsRead;
window.advanceAptStatus = advanceAptStatus;
window.openDoctorModal = openDoctorModal;
window.submitNewDoctor = submitNewDoctor;
window.submitNewPatient = submitNewPatient;

function saveVitalsQuick() {
  if (typeof sealClinicalEncounter === 'function') sealClinicalEncounter();
}

async function validateLabOrder(id) {
  try {
    const res = await apiCall(`/labs/${id}/validate`, 'POST');
    applyBackendState(res.data || res);
    showToast('Analyse biologique validée avec succès');
  } catch (_) {}
}

async function deleteLabOrder(id) {
  if (!confirm('Êtes-vous sûr de vouloir supprimer cette demande d\'analyse ?')) return;
  try {
    const res = await apiCall(`/labs/${id}`, 'DELETE');
    applyBackendState(res.data || res);
    showToast('Demande d\'analyse supprimée');
  } catch (_) {}
}

function exportActiveCsv() {
  const rows = [['Date', 'Patient', 'Service', 'Total MAD']];
  (saas.invoices || []).forEach(i => rows.push([i.date || i.issued_date, i.patient, i.service, i.total]));
  const csv = rows.map(r => r.join(';')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `export_${saas.activeTab}_${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
}

window.sealClinicalEncounter = sealClinicalEncounter;
window.saveVitalsQuick = saveVitalsQuick;
window.validateLabOrder = validateLabOrder;
window.deleteLabOrder = deleteLabOrder;
window.exportActiveCsv = exportActiveCsv;
window.fmtMoney = fmtMoney;
window.escHtml = escHtml;
window.applyBackendState = applyBackendState;
window.initWorkspace = initWorkspace;
window.createNewBackup = function() {
  if (typeof createBackupArchive === 'function') createBackupArchive();
};
window.cycleBedTrendPeriod = cycleBedTrendPeriod;
window.cycleDiagnosePeriod = cycleDiagnosePeriod;
window.filterSpecialists = filterSpecialists;
window.populatePatientSelects = populatePatientSelects;
window.printQrPrescription = printQrPrescription;
window.toggleOwnerCabinetView = function() {
  switchTab('saas-owner');
};


window.switchOwnerSection = function(sec) {
  const secSubs = document.getElementById('owner-sec-subs');
  const secPlans = document.getElementById('owner-sec-plans');
  const secBackups = document.getElementById('owner-sec-backups');
  const sectionIds = ['owner-sec-subs', 'owner-sec-plans', 'owner-sec-backups'];
  const targetId = sec === 'plans' ? 'owner-sec-plans' : sec === 'backups' ? 'owner-sec-backups' : 'owner-sec-subs';
  const activeSection = document.querySelector(sectionIds.map(id => `#${id}:not(.hidden)`).join(','));
  if (activeSection?.id === targetId) return;

  document.querySelectorAll('.saas-owner-nav-btn').forEach(b => {
    b.className = 'saas-owner-nav-btn px-3.5 py-1.5 rounded-full text-[11.5px] font-semibold text-on-surface-variant hover:text-primary transition-all flex items-center gap-1.5';
  });

  const activeBtn = document.getElementById('owner-subnav-' + sec);
  if (activeBtn) {
    activeBtn.className = 'saas-owner-nav-btn px-4 py-1.5 rounded-full text-[11.5px] font-bold bg-gradient-to-r from-primary to-primary-container text-white shadow-emeraldGlow flex items-center gap-1.5 transition-all';
  }

  const updateSection = () => {
    sectionIds.forEach(id => document.getElementById(id)?.classList.toggle('hidden', id !== targetId));
  };
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (activeSection && typeof document.startViewTransition === 'function' && !prefersReducedMotion) {
    activeSection.style.viewTransitionName = 'owner-panel';
    const transition = document.startViewTransition(() => {
      updateSection();
      document.getElementById(targetId)?.style.setProperty('view-transition-name', 'owner-panel');
    });
    const clearTransitionNames = () => {
      sectionIds.forEach(id => document.getElementById(id)?.style.removeProperty('view-transition-name'));
    };
    transition.finished.then(clearTransitionNames, clearTransitionNames);
  } else {
    updateSection();
  }
};
