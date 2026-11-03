/**
 * Cliniqo Cloud — État Multi-Tenant / Multi-Branche, Authentification RBAC & Tableau de Bord (Sections 1, 3D, 3E, 4)
 */
let API_BASE = (window.CLINIQO_API_URL) 
  || (typeof location !== 'undefined' && location.port === '8000' ? 'http://127.0.0.1:8000/api/v1' : 'http://127.0.0.1:8001/api/v1');
const TODAY_ISO = '2026-10-29';

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
    user: { id: 1, name: 'Dr. Jackson', role: 'owner', email: 'owner@cabinet-cliniqo.ma', title: 'Médecin Fondateur & Propriétaire' },
    role: 'owner',
    permissions: [
      'dashboard.view', 'appointments.read', 'appointments.write', 'doctors.write',
      'patients.read', 'patients.write', 'patients.dme',
      'labs.read', 'labs.write', 'labs.validate',
      'finance.read', 'finance.write', 'expenses.read', 'expenses.write',
      'stock.read', 'stock.write', 'documents.read', 'documents.write',
      'reports.read', 'reports.export', 'settings.manage', 'users.manage',
      'branches.manage', 'backups.manage', 'audit.read', 'cabinet.ownership'
    ],
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
    title: 'Administrateur (Admin) — Gestionnaire',
    avatar: 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=120',
    defaultTab: 'overview',
    allowedTabs: ['overview', 'finance', 'stock', 'reports', 'patients', 'labs'],
    canManageSaas: false, // Interdit d'accéder à l'espace Owner
    canManageFinance: true, // Caisse MAD & facturation autorisées
    canAccessDme: false, // Secret médical (DME confidentiel réservé aux médecins)
    canPrescribe: false, // Prescription réservée aux médecins
    canManageStock: true, // Gestion des stocks & dépenses autorisée
  },
  docteur: {
    key: 'docteur',
    name: 'Dr. Alimi Jan',
    title: 'Docteur (Médecin) — Cardiologue',
    avatar: 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120',
    defaultTab: 'overview',
    allowedTabs: ['overview', 'patients', 'labs', 'reports'],
    canManageSaas: false, // Interdit d'accéder à l'espace Owner
    canManageFinance: false, // Caisse & facturation gérées par l'admin
    canAccessDme: true, // Dossier Médical Électronique complet
    canPrescribe: true, // Rédaction d'ordonnances QR code
    canManageStock: false, // Dépenses et stock gérés par l'admin
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
  const role = saas.auth?.role || localStorage.getItem('cliniqo_user_role') || 'owner';
  
  if (role === 'owner' || role === 'saas_owner') {
    // Owner gère UNIQUEMENT les abonnements, packs SaaS, cliniques clientes et sauvegardes
    // STRICTEMENT INTERDIT d'accéder aux dossiers médicaux des patients et à la caisse du cabinet
    const ownerPerms = [
      'cabinet.ownership', 'saas.admin', 'subscriptions.manage', 'saas.manage',
      'plans.manage', 'backups.manage', 'clinics.manage'
    ];
    return ownerPerms.includes(perm);
  }
  
  if (role === 'admin') {
    const adminForbidden = [
      'cabinet.ownership', 'saas.admin', 'subscriptions.manage', 'saas.manage',
      'plans.manage', 'patients.dme', 'prescriptions.write', 'medical.diagnose'
    ];
    if (adminForbidden.includes(perm)) return false;
    return true;
  }
  
  if (role === 'docteur' || role === 'medecin') {
    const clinicalPerms = [
      'dashboard.view', 'appointments.read', 'appointments.write',
      'patients.read', 'patients.dme', 'prescriptions.write',
      'labs.read', 'labs.write', 'labs.validate',
      'documents.read', 'documents.write', 'reports.read'
    ];
    return clinicalPerms.includes(perm);
  }
  
  return Array.isArray(saas.auth?.permissions) && saas.auth.permissions.includes(perm);
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
  const curRole = localStorage.getItem('cliniqo_user_role') || saas.auth?.role || 'owner';
  const h = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Tenant-ID': String(saas.tenantId || 1),
    'X-Branch-ID': String(saas.branchId || 'all'),
    'X-User-Role': curRole,
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
  if (!st) return;
  if (st.auth) saas.auth = st.auth;
  if (st.backups) saas.backups = st.backups;

  if (st.auth?.is_saas_owner || st.auth?.role === 'owner' || st.auth?.role === 'saas_owner') {
    saas.saasConsole = st.saas_console || null;
    saas.auth = st.auth;
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
  const validTabs = ['overview', 'patients', 'labs', 'finance', 'reports', 'stock', 'expenses', 'saas-owner'];
  if (['saas-owner', 'abonnements', 'subscription'].includes(hash)) return 'saas-owner';
  if (validTabs.includes(hash)) return (hash === 'expenses' ? 'stock' : hash);

  const params = new URLSearchParams(window.location.search);
  const qTab = (params.get('tab') || '').trim().toLowerCase();
  if (['saas-owner', 'abonnements', 'subscription'].includes(qTab)) return 'saas-owner';
  if (validTabs.includes(qTab)) return (qTab === 'expenses' ? 'stock' : qTab);

  return null;
}

async function initWorkspace() {
  const initialTab = getTargetTabFromUrl();
  if (initialTab) {
    saas.activeTab = initialTab;
  }

  try {
    let profRes = await fetch(`${API_BASE}/auth/profiles`, { headers: { Accept: 'application/json' } }).then(r => r.json()).catch(() => null);
    if (!profRes) {
      const altBase = API_BASE.includes(':8001') ? API_BASE.replace(':8001', ':8000') : API_BASE.replace(':8000', ':8001');
      profRes = await fetch(`${altBase}/auth/profiles`, { headers: { Accept: 'application/json' } }).then(r => r.json()).catch(() => ({}));
      if (profRes?.profiles) API_BASE = altBase;
    }
    saas.authProfiles = profRes.profiles || [];
  } catch (_) {}

  try {
    const res = await apiCall('/state', 'GET');
    applyBackendState(res.data || res.state || res);
  } catch (e) {
    if (e.status === 401 || e.status === 403) {
      localStorage.removeItem('cliniqo_staff_token');
      saas.token = '';
      saas.branchId = 'all';
      localStorage.setItem('cliniqo_branch_id', 'all');
      const retry = await apiCall('/state', 'GET');
      applyBackendState(retry.data || retry.state || retry);
    }
  }

  const curRole = localStorage.getItem('cliniqo_user_role') || saas.auth?.role || 'owner';
  const roleCfg = ROLE_SPACES_CONFIG[curRole] || ROLE_SPACES_CONFIG.owner;

  // If applyBackendState already set up Owner mode, skip redundant calls
  if (curRole === 'owner' && saas.activeTab === 'saas-owner') {
    applyRoleUiGuards(curRole);
    return;
  }

  applyRoleUiGuards(curRole);
  const finalTab = getTargetTabFromUrl() || (saas.activeTab || roleCfg.defaultTab || 'overview');
  if (finalTab !== saas.activeTab) {
    switchTab(finalTab);
  }
}

let _hashChangeLock = false;
window.addEventListener('hashchange', () => {
  if (_hashChangeLock) return;
  const t = getTargetTabFromUrl();
  if (t && t !== saas.activeTab) {
    _hashChangeLock = true;
    switchTab(t);
    _hashChangeLock = false;
  }
});

// ============================================================================
// NAVIGATION, HEADER, GRAND CENTRAL CENTRE & BRANCH SWITCHER, NOTIFICATIONS
// ============================================================================
function renderHeaderAndBranches() {
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
        <a href="espace-patient.html" target="_blank" class="text-[10.5px] font-bold text-on-surface-variant hover:text-primary px-1 py-0.5">Portail Patient &nearr;</a>
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

async function switchAuthProfile(userId) {
  const res = await apiCall('/auth/login', 'POST', { user_id: Number(userId) });
  saas.token = res.token || '';
  localStorage.setItem('cliniqo_staff_token', saas.token);
  if (res.user?.tenant_id) {
    saas.tenantId = Number(res.user.tenant_id);
    localStorage.setItem('cliniqo_tenant_id', String(saas.tenantId));
  }
  saas.branchId = 'all';
  localStorage.setItem('cliniqo_branch_id', 'all');
  closeModal('settings-modal');
  applyBackendState(res.state);
  showToast(`Connecté en tant que ${res.user?.name} (${res.user?.role})`);
}

function toggleRoleSelector(ev) {
  if (ev) ev.stopPropagation();
  document.getElementById('branch-menu')?.classList.add('hidden');
  document.getElementById('notif-popover')?.classList.add('hidden');
  document.getElementById('quick-role-menu')?.classList.toggle('hidden');
}

function applyRoleUiGuards(roleKey) {
  const role = roleKey || saas.auth?.role || localStorage.getItem('cliniqo_user_role') || 'owner';
  const cfg = ROLE_SPACES_CONFIG[role] || ROLE_SPACES_CONFIG.owner;
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
  if (userNameEl) userNameEl.textContent = cfg.name;
  if (avatarEl && cfg.avatar) avatarEl.src = cfg.avatar;

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

  // 5. Masquer le portail patient pour l'Owner
  const portalLink = document.querySelector('a[href="espace-patient.html"]');
  if (portalLink) portalLink.style.display = isOwner ? 'none' : 'flex';

  // 6. Bouton Paramètres en-tête
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

async function switchRoleFast(roleKey) {
  document.getElementById('quick-role-menu')?.classList.add('hidden');
  const roleMap = { owner: 1, admin: 2, docteur: 3 };
  let targetId = roleMap[roleKey] || 1;
  if (Array.isArray(saas.authProfiles) && saas.authProfiles.length > 0) {
    const found = saas.authProfiles.find(p => p.role === roleKey);
    if (found) targetId = Number(found.id);
  }
  await switchAuthProfile(targetId);
  localStorage.setItem('cliniqo_user_role', roleKey);
  
  applyRoleUiGuards(roleKey);

  const roleCfg = ROLE_SPACES_CONFIG[roleKey] || ROLE_SPACES_CONFIG.owner;
  // Redirection automatique si l'onglet en cours est interdit au nouveau rôle
  if (!roleCfg.allowedTabs.includes(saas.activeTab)) {
    switchTab(roleCfg.defaultTab);
  } else if (saas.activeTab === 'patients') {
    renderPatientsView();
  }

  const roleLabels = {
    owner: 'Propriétaire (Owner) — Espace Abonnements, Packs & Supervision',
    admin: 'Administrateur (Admin) — Gestion Complète du Cabinet & Caisse',
    docteur: 'Docteur (Médecin) — Dossier Médical Électronique & Consultations'
  };
  showToast(`Rôle activé : ${roleLabels[roleKey] || roleKey}`);
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

function switchTab(tab) {
  const role = saas.auth?.role || localStorage.getItem('cliniqo_user_role') || 'owner';
  const roleCfg = ROLE_SPACES_CONFIG[role] || ROLE_SPACES_CONFIG.owner;
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

  const validTabs = ['overview', 'patients', 'labs', 'finance', 'stock', 'reports', 'saas-owner'];
  if (!validTabs.includes(tab)) tab = roleCfg.defaultTab || 'overview';

  saas.activeTab = tab;
  document.querySelectorAll('.saas-view').forEach(s => s.classList.add('hidden'));
  if (tab === 'stock') {
    document.getElementById('view-finance')?.classList.remove('hidden');
  } else if (tab === 'saas-owner') {
    document.getElementById('view-saas-owner')?.classList.remove('hidden');
  } else {
    document.getElementById(`view-${tab}`)?.classList.remove('hidden');
  }

  document.querySelectorAll('.saas-tab').forEach(btn => {
    btn.className = 'saas-tab px-3.5 py-1.5 rounded-full text-[11.5px] font-semibold text-on-surface-variant hover:text-primary transition-all flex items-center gap-1';
  });
  const activeBtn = document.getElementById(`tab-${tab}`);
  if (activeBtn) {
    activeBtn.className = 'saas-tab px-4 py-1.5 rounded-full text-[11.5px] font-bold bg-gradient-to-r from-primary to-primary-container text-white shadow-emeraldGlow transition-all flex items-center gap-1';
  }

  const headings = {
    overview: 'Surveillance Diagnostique & Activité Réelle',
    patients: 'Patients & Dossiers Médicaux Électroniques (DME)',
    labs: saas.tenant?.enable_laboratory ? 'Laboratoire & Biologie Médicale' : 'Laboratoire (Module Désactivé)',
    finance: 'Facturation & Télétransmission CNSS',
    stock: 'Dépenses, Charges & Stock Pharmacie',
    reports: 'Rapports Analytiques, Financiers & Audit CNDP',
    'saas-owner': 'Gestion des Abonnements, Forfaits & Console Propriétaire'
  };
  const ctas = {
    overview: 'Nouveau Rendez-vous',
    patients: 'Nouveau Patient',
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
  } else if (tab === 'labs') {
    renderLabsView();
  } else if (tab === 'finance') {
    if (typeof renderFinanceHub === 'function') renderFinanceHub();
    if (typeof switchFinanceSubTab === 'function') switchFinanceSubTab('invoices');
  } else if (tab === 'stock') {
    if (typeof renderFinanceHub === 'function') renderFinanceHub();
    if (typeof switchFinanceSubTab === 'function') switchFinanceSubTab('stock');
  } else if (tab === 'reports' && typeof loadReportsSummary === 'function') {
    loadReportsSummary();
  } else if (tab === 'saas-owner') {
    document.querySelectorAll('.saas-view').forEach(v => v.classList.add('hidden'));
    document.getElementById('view-saas-owner')?.classList.remove('hidden');
    if (typeof renderSaasOwnerConsole === 'function') renderSaasOwnerConsole();
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
  } else if (saas.activeTab === 'finance' || saas.activeTab === 'stock') {
    if (saas.financeSubTab === 'expenses') {
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
    } else if (saas.financeSubTab === 'stock') {
      openModal('stock-modal');
    } else if (saas.financeSubTab === 'documents') {
      populatePatientSelects();
      openModal('document-modal');
    } else if (saas.financeSubTab === 'quotes') {
      populatePatientSelects();
      openModal('quote-modal');
    } else if (saas.financeSubTab === 'caisse') {
      openModal('close-caisse-modal');
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
  } else if (saas.activeTab === 'reports') {
    if (typeof printCurrentReportPdf === 'function') printCurrentReportPdf();
  } else if (saas.activeTab === 'saas-owner') {
    openModal('saas-clinic-modal');
  }
}

// ============================================================================
// SECTION 4 — REAL DASHBOARD INDICATORS (4A, 4B, 4C, 4D, 4E, 4F)
// ============================================================================
function renderOverview() {
  const dm = saas.dashboardMetrics || {};

  // --- 4A. PATIENTS AUJOURD'HUI (Prévus, Enregistrés, Consultés + 7j réels) ---
  const pDet = dm.patients_today_detail || { scheduled: saas.kpis.patients_today || 0, checked_in: 0, seen: 0 };
  const patValEl = document.getElementById('kpi-patients-val');
  if (patValEl) patValEl.textContent = String(pDet.scheduled ?? 0);

  const patBreakEl = document.getElementById('kpi-patients-breakdown');
  if (patBreakEl) {
    patBreakEl.innerHTML = `
      <span class="px-1.5 py-0.5 rounded-md bg-secondary-container/70 text-primary font-bold">Prévus : ${pDet.scheduled ?? 0}</span>
      <span class="px-1.5 py-0.5 rounded-md bg-surface-container-low text-on-surface font-semibold">Enreg. : ${pDet.checked_in ?? 0}</span>
      <span class="px-1.5 py-0.5 rounded-md bg-primary/10 text-primary font-bold">Vus : ${pDet.seen ?? 0}</span>
    `;
  }

  const barsGrid = document.getElementById('patients-bars-grid');
  const wp = saas.weeklyPatients || [];
  if (barsGrid) {
    if (wp.length === 0) {
      barsGrid.innerHTML = `<div class="col-span-7 text-[10.5px] text-outline text-center py-4">Aucun RDV sur les 7 derniers jours</div>`;
    } else {
      const selIdx = Math.min(saas.selectedPatientDayIdx, wp.length - 1);
      const selDay = wp[selIdx] || wp[wp.length - 1];
      const dayHint = document.getElementById('kpi-patients-day-hint');
      if (dayHint && selDay) {
        dayHint.textContent = `${selDay.day} • ${selDay.seen}/${selDay.scheduled} vus`;
      }
      barsGrid.innerHTML = wp.map((d, idx) => {
        const active = idx === selIdx;
        return `
          <button onclick="selectPatientBarDay(${idx})" title="${escHtml(d.date)} : ${d.scheduled} prévus, ${d.checked_in} enregistrés, ${d.seen} consultés"
                  class="flex flex-col items-center justify-end h-full group focus:outline-none">
            <div class="w-full rounded-t-lg transition-all ${active ? 'bg-primary shadow-xs' : 'bg-secondary-container/85 group-hover:bg-primary-light'}"
                 style="height:${Math.max(12, Number(d.h || 15))}%"></div>
            <span class="text-[9.5px] mt-1 ${active ? 'font-extrabold text-primary' : 'font-semibold text-outline'}">${escHtml(d.day)}</span>
          </button>
        `;
      }).join('');
    }
  }

  // --- 4B. ANALYSES RÉALISÉES (Uniquement si Labo activé ; sinon indicateur opérationnel réel) ---
  const labSum = dm.labs_summary || { enabled: Boolean(saas.tenant?.enable_laboratory) };
  const card2Title = document.getElementById('card-labs-title');
  const kpiTestsVal = document.getElementById('kpi-tests-val');
  const hmLabel = document.getElementById('heatmap-hover-label');
  const hmGrid = document.getElementById('tests-heatmap-grid');

  if (labSum.enabled) {
    if (card2Title) card2Title.textContent = 'Analyses Réalisées';
    if (kpiTestsVal) kpiTestsVal.textContent = String(labSum.completed_total ?? 0);
    if (hmLabel) {
      hmLabel.textContent = `${labSum.completed_today ?? 0} aujourd’hui • ${labSum.pending ?? 0} en cours`;
    }
    const cells = labSum.heatmap || [];
    if (hmGrid) {
      if (cells.length === 0) {
        hmGrid.innerHTML = `<div class="col-span-4 py-4 text-center text-[10.5px] text-outline bg-surface-container-low rounded-xl">Aucune analyse enregistrée</div>`;
      } else {
        hmGrid.innerHTML = cells.slice(0, 8).map(c => {
          const pct = parseInt(c.rate, 10) || 0;
          const tone = pct >= 80 ? 'bg-primary text-white' : (pct >= 50 ? 'bg-primary-light/75 text-on-primary-fixed' : 'bg-secondary-container text-on-surface');
          return `
            <div onmouseenter="document.getElementById('heatmap-hover-label').textContent='${escHtml(c.label)} • ${c.completed}/${c.total} (${c.rate})'"
                 class="rounded-lg p-1.5 ${tone} text-center cursor-default transition-transform hover:scale-105">
              <span class="block text-[9px] font-bold truncate">${escHtml(c.label)}</span>
              <span class="block text-[10px] font-extrabold">${escHtml(c.rate)}</span>
            </div>
          `;
        }).join('');
      }
    }
  } else {
    const repl = labSum.operational_replacement || { title: 'Consultations & Présence', completed_consultations: 0, attendance_rate_pct: 100 };
    if (card2Title) card2Title.textContent = repl.title || 'Consultations & Présence';
    if (kpiTestsVal) kpiTestsVal.textContent = `${repl.attendance_rate_pct ?? 0}%`;
    if (hmLabel) hmLabel.textContent = `${repl.completed_consultations ?? 0} consultations terminées`;
    if (hmGrid) {
      hmGrid.innerHTML = `
        <div class="col-span-4 p-2.5 rounded-xl bg-secondary-container/50 border border-primary/15 space-y-1 text-[10.5px]">
          <div class="flex justify-between"><span class="text-on-surface-variant">Consultations réalisées :</span><strong class="text-primary">${repl.completed_consultations ?? 0}</strong></div>
          <div class="flex justify-between"><span class="text-on-surface-variant">RDV vus aujourd’hui :</span><strong class="text-on-surface">${repl.seen_today ?? 0} / ${repl.scheduled_today ?? 0}</strong></div>
          <div class="text-[9.5px] text-outline pt-0.5">Laboratoire désactivé sur ce centre</div>
        </div>
      `;
    }
  }

  // --- 4C. RECETTES DU JOUR (Encaissements réels du jour distincts du Facturé, Impayés et Avoirs) ---
  const revDet = dm.revenue_today_detail || {
    collected_today: saas.kpis.revenue_today || 0,
    invoiced_today: saas.kpis.invoiced_today || 0,
    outstanding_total: saas.kpis.outstanding_total || 0,
    refunds_today: saas.kpis.refunds_today || 0,
    hourly_bars: []
  };
  const revValEl = document.getElementById('kpi-revenue-val');
  const revCurrEl = document.getElementById('kpi-currency-code');
  if (revValEl) revValEl.textContent = fmtInt(revDet.collected_today);
  if (revCurrEl) revCurrEl.textContent = saas.currency;

  const revSubEl = document.getElementById('kpi-revenue-submetrics');
  if (revSubEl) {
    revSubEl.innerHTML = `
      <span title="Total facturé aujourd'hui">Facturé : <strong>${fmtInt(revDet.invoiced_today)}</strong></span>
      <span>•</span>
      <span title="Soldes impayés patients">Impayés : <strong class="text-error">${fmtInt(revDet.outstanding_total)}</strong></span>
      ${Number(revDet.refunds_today) > 0 ? `<span>•</span><span title="Avoirs / Remboursements du jour">Avoirs : <strong>-${fmtInt(revDet.refunds_today)}</strong></span>` : ''}
    `;
  }

  const eqEl = document.getElementById('revenue-equalizer');
  if (eqEl) {
    const hBars = revDet.hourly_bars || [];
    eqEl.innerHTML = hBars.map(b => `
      <div title="${escHtml(b.hour)} : ${fmtMoney(b.amount, true)} encaissés" class="flex flex-col items-center justify-end h-full flex-1 group">
        <div class="w-2 rounded-full transition-all ${b.peak ? 'bg-primary' : (b.amount > 0 ? 'bg-brand-soft-green/80' : 'bg-surface-container-high')}"
             style="height:${Math.max(10, Number(b.height || 10))}%"></div>
        <span class="text-[8px] text-outline mt-1">${escHtml(String(b.hour).slice(0, 3))}</span>
      </div>
    `).join('');
  }

  // --- 4D. NOS SPÉCIALISTES (Nombre exact de médecins actifs & spécialités du centre/branche) ---
  const specSum = dm.specialists_summary || { active_doctors_count: saas.doctors.length, specialties_count: 0, by_specialty: [] };
  const specValEl = document.getElementById('kpi-specialists-val');
  if (specValEl) {
    specValEl.textContent = `${specSum.active_doctors_count} médecin${specSum.active_doctors_count > 1 ? 's' : ''}`;
  }
  const specCountSub = document.getElementById('kpi-specialties-count-label');
  if (specCountSub) {
    specCountSub.textContent = `${specSum.specialties_count} spécialité${specSum.specialties_count > 1 ? 's' : ''}`;
  }

  const chipsWrap = document.getElementById('specialists-chips-grid');
  if (chipsWrap) {
    const topSpecs = (specSum.by_specialty || []).slice(0, 3);
    chipsWrap.innerHTML = topSpecs.map(s => {
      const isSel = saas.specialistFilter === s.specialty;
      return `
        <button onclick="filterSpecialists('${escHtml(s.specialty)}')"
                class="spec-chip rounded-lg py-1 px-1.5 text-center transition-all ${isSel ? 'bg-primary text-white shadow-2xs' : 'bg-surface-container-low text-on-surface-variant hover:bg-secondary-container/70'}">
          <span class="block text-[9px] font-medium truncate">${escHtml(s.specialty)}</span>
          <span class="block text-[10.5px] font-bold leading-tight">${String(s.count).padStart(2, '0')}</span>
        </button>
      `;
    }).join('');
  }
  renderSpecialistsDoctorsList();

  // --- 4E. UTILISATION DES CRÉNEAUX & SALLES (ou Lits Hospitaliers uniquement si activé) ---
  renderUtilizationCard(dm.utilization_summary || {});

  // --- 4F. MOYENNE DES DIAGNOSTICS PATIENTS (Vraies consultations cliniques par pôle) ---
  renderDiagnosticsCard(dm.diagnostics_summary || {});

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
  const todays = saas.appointments.filter(a => a.date === TODAY_ISO);
  if (todays.length === 0) {
    wrap.innerHTML = `<div class="col-span-3 p-4 rounded-xl bg-surface-container-low text-center text-xs text-outline">Aucun rendez-vous programmé aujourd’hui (${TODAY_ISO}) sur cette sélection.</div>`;
    return;
  }

  const statusMeta = {
    confirmed: { label: 'Confirmé', cls: 'bg-surface-container-low text-on-surface-variant', next: 'waiting', nextLbl: 'Enregistrer arrivée' },
    waiting: { label: 'Salle d’attente', cls: 'bg-secondary-container text-primary', next: 'in_progress', nextLbl: 'Démarrer consultation' },
    in_progress: { label: 'En consultation', cls: 'bg-primary text-white', next: 'completed', nextLbl: 'Terminer & Facturer' },
    completed: { label: 'Consulté', cls: 'bg-primary/15 text-primary', next: null, nextLbl: null },
    cancelled: { label: 'Annulé', cls: 'bg-error-container text-error', next: null, nextLbl: null },
    no_show: { label: 'Absent (No-show)', cls: 'bg-error-container/70 text-error', next: null, nextLbl: null }
  };

  wrap.innerHTML = todays.slice(0, 6).map(a => {
    const p = saas.patients.find(x => x.id === a.patient_id);
    const pName = p ? `${p.first} ${p.last}` : `Patient #${a.patient_id}`;
    const sm = statusMeta[a.status] || statusMeta.confirmed;
    return `
      <div class="p-3 rounded-xl bg-surface-container-low/75 border border-outline-variant/30 flex flex-col justify-between gap-2">
        <div class="flex items-start justify-between gap-2">
          <div>
            <div class="flex items-center gap-1.5">
              <span class="text-xs font-extrabold text-primary font-mono">${escHtml(a.time)}</span>
              <strong class="text-xs font-bold text-on-surface">${escHtml(pName)}</strong>
            </div>
            <span class="text-[10.5px] text-outline block">${escHtml(a.doctor)} • ${escHtml(a.room)}</span>
            <span class="text-[10px] text-on-surface-variant block truncate">${escHtml(a.service_name || a.reason)}</span>
          </div>
          <span class="px-2 py-0.5 rounded-full text-[9.5px] font-bold shrink-0 ${sm.cls}">${sm.label}</span>
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
  }).join('');
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
  document.getElementById('doc-modal-title').textContent = doc ? 'Modifier Praticien & Horaires' : 'Ajouter un Médecin';
  document.getElementById('doc-name').value = doc ? doc.name : '';
  document.getElementById('doc-specialty').value = doc ? doc.specialty : 'Cardiologue';
  document.getElementById('doc-room').value = doc ? doc.room : 'Salle 108';
  document.getElementById('doc-fee').value = doc ? doc.fee_mad : 600;
  document.getElementById('doc-inpe').value = doc ? (doc.inpe || '') : '';
  document.getElementById('doc-phone').value = doc ? (doc.phone || '+212 661-506070') : '+212 661-506070';
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
  document.getElementById('dme-avatar').src = p.avatar || makeInitialsAvatar(`${p.first} ${p.last}`);
  document.getElementById('dme-name').textContent = `${p.first} ${p.last}`;
  document.getElementById('dme-code').textContent = `#${p.code}`;
  document.getElementById('dme-meta').textContent = `${p.age} ans • CIN ${p.cin} • Groupe ${p.blood}`;
  document.getElementById('dme-allergy-input').value = p.allergy || 'Aucune';
  document.getElementById('dme-coverage').textContent = p.coverage || 'Particulier';
  document.getElementById('dme-bp-input').value = p.bp || '120/80';
  document.getElementById('dme-spo2-input').value = p.spo2 || '98%';
  document.getElementById('dme-hr-input').value = p.hr || '72 bpm';
  document.getElementById('dme-note').value = '';

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
          <span class="text-[10px] font-bold ${Number(d.shared_with_patient) ? 'text-primary' : 'text-outline'}">${Number(d.shared_with_patient) ? 'Portail OK' : 'Interne'}</span>
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
  ['vitals', 'dental', 'family', 'history', 'billing'].forEach(t => {
    document.getElementById(`dme-panel-${t}`)?.classList.toggle('hidden', t !== tab);
    const btn = document.getElementById(`dme-tab-${t}`);
    if (btn) {
      btn.className = t === tab
        ? 'py-1.5 px-1.5 rounded-lg bg-white text-primary shadow-2xs font-bold text-xs'
        : 'py-1.5 px-1.5 rounded-lg text-outline hover:text-on-surface text-xs';
    }
  });
  if (tab === 'dental') renderDentalOdontogram();
  if (tab === 'family') renderFamilyPanel();
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
    shared_with_patient: true
  });
  applyBackendState(res.data || res);
  const createdDoc = saas.documents[0];
  if (createdDoc) {
    CliniqoPdf.printDocument(saas.tenant, createdDoc, saas.currency);
  }
  showToast('Ordonnance générée et partagée sur le Portail Patient');
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
  document.getElementById(id)?.classList.remove('hidden');
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
  document.getElementById(`view-${saas.activeTab}`)?.classList.remove('hidden');

  renderHeaderAndBranches();
  renderOverview();
  renderPatientsView();
  renderLabsView();
  if (typeof renderFinanceHub === 'function') renderFinanceHub();
  if (typeof populateReportFilterDropdowns === 'function') populateReportFilterDropdowns();
}

document.addEventListener('click', () => {
  document.getElementById('branch-menu')?.classList.add('hidden');
  document.getElementById('notif-popover')?.classList.add('hidden');
  document.getElementById('quick-role-menu')?.classList.add('hidden');
});

document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    openModal('search-modal');
  } else if (e.key === 'Escape') {
    ['search-modal', 'settings-modal', 'patient-modal', 'doctor-modal', 'lab-modal', 'invoice-modal', 'payment-modal', 'refund-modal', 'expense-modal', 'stock-modal', 'document-modal', 'saas-clinic-modal', 'quick-role-menu'].forEach(closeModal);
    closeDmeDrawer();
    document.getElementById('quick-role-menu')?.classList.add('hidden');
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
window.openModal = openModal;
window.closeModal = closeModal;
window.switchTenant = switchTenant;
window.switchBranch = switchBranch;
window.switchAuthProfile = switchAuthProfile;
window.toggleBranchMenu = toggleBranchMenu;
window.toggleNotifications = toggleNotifications;
window.toggleRoleSelector = toggleRoleSelector;
window.switchRoleFast = switchRoleFast;
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

  document.querySelectorAll('.saas-owner-nav-btn').forEach(b => {
    b.className = 'saas-owner-nav-btn px-3.5 py-1.5 rounded-full text-[11.5px] font-semibold text-on-surface-variant hover:text-primary transition-all flex items-center gap-1.5';
  });

  const activeBtn = document.getElementById('owner-subnav-' + sec);
  if (activeBtn) {
    activeBtn.className = 'saas-owner-nav-btn px-4 py-1.5 rounded-full text-[11.5px] font-bold bg-gradient-to-r from-primary to-primary-container text-white shadow-emeraldGlow flex items-center gap-1.5 transition-all';
  }

  if (sec === 'plans') {
    if (secSubs) secSubs.classList.add('hidden');
    if (secPlans) secPlans.classList.remove('hidden');
    if (secBackups) secBackups.classList.add('hidden');
  } else if (sec === 'backups') {
    if (secSubs) secSubs.classList.add('hidden');
    if (secPlans) secPlans.classList.add('hidden');
    if (secBackups) secBackups.classList.remove('hidden');
  } else {
    // subscriptions
    if (secSubs) secSubs.classList.remove('hidden');
    if (secPlans) secPlans.classList.add('hidden');
    if (secBackups) secBackups.classList.add('hidden');
  }
};
