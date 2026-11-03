<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SaasWorkspaceController;
use App\Http\Controllers\ReportsAndAdminController;
use App\Http\Controllers\PatientPortalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\InvoicingController;
use App\Http\Controllers\CnssController;

/*
|--------------------------------------------------------------------------
| Cliniqo Medical REST API Routes (v1 — Production SQLite Multi-Tenant FR)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->middleware(['tenant.isolate'])->group(function () {

    // CORS Preflight handler
    Route::options('/{any}', static fn() => response('', 204))->where('any', '.*');

    // 0. Authentification Staff & Changement de Rôle RBAC
    Route::get('/auth/profiles', [ReportsAndAdminController::class, 'listAuthProfiles']);
    Route::post('/auth/login', [ReportsAndAdminController::class, 'login']);

    // 1. État Global Multi-Tenant / Multi-Branche & Réinitialisation
    Route::get('/state', [SaasWorkspaceController::class, 'getState']);
    Route::post('/reset', [SaasWorkspaceController::class, 'resetTenant']);
    Route::get('/dashboard/overview', [SaasWorkspaceController::class, 'getState']);
    Route::get('/dashboard/staff-on-duty', [DashboardController::class, 'getStaffOnDuty']);
    Route::get('/dashboard/triage-alerts', [DashboardController::class, 'getTriageAlerts']);

    // 2. Gestion Complète des Rendez-vous & Agenda
    Route::get('/appointments', [SaasWorkspaceController::class, 'listAppointments']);
    Route::post('/appointments', [SaasWorkspaceController::class, 'createAppointment']);
    Route::put('/appointments/{id}', [SaasWorkspaceController::class, 'updateAppointment']);
    Route::patch('/appointments/{id}', [SaasWorkspaceController::class, 'updateAppointment']);
    Route::patch('/appointments/{id}/status', [SaasWorkspaceController::class, 'updateAppointmentStatus']);
    Route::delete('/appointments/{id}', [SaasWorkspaceController::class, 'deleteAppointment']);
    Route::get('/practitioners/{id}/slots', [AppointmentController::class, 'getAvailableSlots']);

    // 2b. Gestion des Médecins & Horaires
    Route::get('/doctors', [SaasWorkspaceController::class, 'listDoctors']);
    Route::post('/doctors', [SaasWorkspaceController::class, 'createDoctor']);
    Route::put('/doctors/{id}', [SaasWorkspaceController::class, 'updateDoctor']);
    Route::patch('/doctors/{id}', [SaasWorkspaceController::class, 'updateDoctor']);
    Route::delete('/doctors/{id}', [SaasWorkspaceController::class, 'deleteDoctor']);

    // 3. Patients & Dossier Médical Électronique (DME protégé RBAC)
    Route::get('/patients', [SaasWorkspaceController::class, 'listPatients']);
    Route::post('/patients', [SaasWorkspaceController::class, 'createPatient']);
    Route::delete('/patients/{id}', [SaasWorkspaceController::class, 'deletePatient']);
    Route::get('/patients/{id}/dme', [SaasWorkspaceController::class, 'getPatientDme']);
    Route::post('/patients/{id}/dme', [SaasWorkspaceController::class, 'savePatientDme']);
    Route::get('/patients/{id}', [PatientController::class, 'show']);
    Route::get('/patients/{id}/family', [PatientController::class, 'getFamilyDossier']);
    Route::get('/patients/{id}/encounters', [PatientController::class, 'getEncountersHistory']);

    // 4. Laboratoire & Biologie Médicale
    Route::get('/labs', [SaasWorkspaceController::class, 'listLabs']);
    Route::post('/labs', [SaasWorkspaceController::class, 'createLab']);
    Route::patch('/labs/{id}/validate', [SaasWorkspaceController::class, 'validateLab']);
    Route::delete('/labs/{id}/delete', [SaasWorkspaceController::class, 'deleteLab']);
    Route::delete('/labs/{id}', [SaasWorkspaceController::class, 'deleteLab']);

    // 5. Consultations Cliniques & Prescriptions
    Route::post('/encounters/start', [ConsultationController::class, 'startEncounter']);
    Route::post('/encounters/{id}/vitals', [ConsultationController::class, 'recordVitals']);
    Route::post('/encounters/{id}/notes', [ConsultationController::class, 'saveNotes']);
    Route::post('/encounters/{id}/prescriptions', [ConsultationController::class, 'issuePrescription']);

    // 6. Facturation, Encaissements Partiels, Avoirs/Remboursements & CNSS
    Route::get('/invoices', [SaasWorkspaceController::class, 'listInvoices']);
    Route::post('/invoices', [SaasWorkspaceController::class, 'createInvoice']);
    Route::post('/invoices/{id}/payments', [SaasWorkspaceController::class, 'recordInvoicePayment']);
    Route::post('/invoices/{id}/refunds', [SaasWorkspaceController::class, 'refundInvoicePayment']);
    Route::patch('/invoices/{id}/cnss', [SaasWorkspaceController::class, 'advanceCnssClaim']);
    Route::get('/cash-sessions/current', [InvoicingController::class, 'getCurrentCashSession']);
    Route::post('/cash-sessions/close', [SaasWorkspaceController::class, 'closeCashSession']);

    // 6b. Devis Médicaux & Dentaires (Conversion en Facture)
    Route::get('/quotes', [SaasWorkspaceController::class, 'listQuotes']);
    Route::post('/quotes', [SaasWorkspaceController::class, 'createQuote']);
    Route::post('/quotes/{id}/convert', [SaasWorkspaceController::class, 'convertQuoteToInvoice']);

    // 6c. Dentisterie & Odontogramme Interactif
    Route::post('/dental/teeth', [SaasWorkspaceController::class, 'saveDentalTooth']);
    Route::get('/dental/plans', [SaasWorkspaceController::class, 'listDentalPlans']);
    Route::post('/dental/plans', [SaasWorkspaceController::class, 'createDentalPlan']);
    Route::patch('/dental/plans/{id}', [SaasWorkspaceController::class, 'updateDentalPlan']);

    // 6d. Liens Familiaux
    Route::post('/patients/{id}/family', [SaasWorkspaceController::class, 'addFamilyLink']);

    // 7. Dépenses & Stock Médical Optionnel (Section 3H)
    Route::get('/expenses', [SaasWorkspaceController::class, 'listExpenses']);
    Route::post('/expenses', [SaasWorkspaceController::class, 'createExpense']);
    Route::delete('/expenses/{id}', [SaasWorkspaceController::class, 'deleteExpense']);

    Route::get('/inventory', [SaasWorkspaceController::class, 'listInventory']);
    Route::post('/inventory', [SaasWorkspaceController::class, 'createInventoryItem']);
    Route::post('/inventory/{id}/movements', [SaasWorkspaceController::class, 'recordStockMovement']);

    // 8. Documents Médicaux, Devis, Certificats & Partage Portail (Section 3G)
    Route::post('/documents', [SaasWorkspaceController::class, 'createDocument']);
    Route::patch('/documents/{id}/share', [SaasWorkspaceController::class, 'toggleDocumentShare']);

    // 9. Notifications & Préférences (Section 3D)
    Route::patch('/notifications/{id}/read', [SaasWorkspaceController::class, 'markNotificationRead']);
    Route::post('/notifications/read-all', [SaasWorkspaceController::class, 'markAllNotificationsRead']);
    Route::put('/notifications/preferences', [SaasWorkspaceController::class, 'updateNotificationPreferences']);

    // 10. Rapports Analytiques & Export CSV (Section 2)
    Route::get('/reports/summary', [ReportsAndAdminController::class, 'getReportsSummary']);
    Route::get('/reports/export-csv', [ReportsAndAdminController::class, 'exportReportsCsv']);

    // 11. Paramètres du Centre, Branches, Actes, Congés & Utilisateurs (Section 3A & 3B)
    Route::get('/settings', [ReportsAndAdminController::class, 'getCentreSettings']);
    Route::put('/settings', [ReportsAndAdminController::class, 'updateCentreSettings']);
    Route::post('/branches', [ReportsAndAdminController::class, 'createBranch']);
    Route::post('/services', [ReportsAndAdminController::class, 'createMedicalService']);
    Route::delete('/services/{id}', [ReportsAndAdminController::class, 'deleteMedicalService']);
    Route::post('/holidays', [ReportsAndAdminController::class, 'createHoliday']);
    Route::delete('/holidays/{id}', [ReportsAndAdminController::class, 'deleteHoliday']);
    Route::post('/users', [ReportsAndAdminController::class, 'createClinicUser']);

    // 12. Console Propriétaire SaaS MediCab (Section 2 — Space One)
    Route::get('/saas/overview', [ReportsAndAdminController::class, 'saasOverview']);
    Route::post('/saas/clinics', [ReportsAndAdminController::class, 'saasCreateClinic']);
    Route::put('/saas/clinics/{tenantId}', [ReportsAndAdminController::class, 'saasUpdateClinic']);
    Route::post('/saas/clinics/{tenantId}/toggle-suspend', [ReportsAndAdminController::class, 'saasToggleSuspendClinic']);
    Route::post('/saas/clinics/{tenantId}/reset-admin', [ReportsAndAdminController::class, 'saasResetAdminPassword']);
    Route::post('/saas/clinics/{tenantId}/payments', [ReportsAndAdminController::class, 'saasRecordSubscriptionPayment']);
    Route::patch('/saas/subscriptions/{tenantId}', [ReportsAndAdminController::class, 'saasUpdateSubscription']);
    Route::post('/saas/plans', [ReportsAndAdminController::class, 'saasCreatePlan']);
    Route::put('/saas/plans/{planId}', [ReportsAndAdminController::class, 'saasUpdatePlan']);
    Route::delete('/saas/plans/{planId}', [ReportsAndAdminController::class, 'saasDeletePlan']);

    // 13. Sauvegardes & Restauration SQLite (Section 3E)
    Route::get('/backups', [ReportsAndAdminController::class, 'listBackups']);
    Route::post('/backups/create', [ReportsAndAdminController::class, 'createBackup']);
    Route::post('/backups/restore', [ReportsAndAdminController::class, 'restoreBackup']);

    // 14. Portail Patient Sécurisé (Section 3F)
    Route::get('/portal/clinics', [PatientPortalController::class, 'publicClinics']);
    Route::post('/portal/login', [PatientPortalController::class, 'login']);
    Route::get('/portal/me', [PatientPortalController::class, 'me']);
    Route::get('/portal/patients/{targetPatientId}', [PatientPortalController::class, 'guardCrossPatientAttempt']);
    Route::post('/portal/appointments', [PatientPortalController::class, 'requestAppointment']);
    Route::patch('/portal/appointments/{id}/cancel', [PatientPortalController::class, 'cancelAppointment']);
    Route::put('/portal/contact', [PatientPortalController::class, 'updateContact']);

    // 15. Référentiel CNSS Legacy
    Route::get('/cnss/claims', [CnssController::class, 'index']);
    Route::get('/cnss/claims/{id}', [CnssController::class, 'show']);
    Route::post('/cnss/claims/{id}/submit', [CnssController::class, 'submitClaim']);
    Route::get('/cnss/providers', [CnssController::class, 'getProviders']);
});
