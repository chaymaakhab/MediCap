<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SaasWorkspaceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\InvoicingController;
use App\Http\Controllers\CnssController;

/*
|--------------------------------------------------------------------------
| Cliniqo Medical REST API Routes (v1 — Multi-Tenant Français)
|--------------------------------------------------------------------------
| Conçu pour le Web Back-office (Tableau de bord & Agenda Rendez-vous)
| et l'application Mobile Flutter.
*/

Route::prefix('v1')->middleware(['tenant.isolate'])->group(function () {

    // 1. État Global Multi-Tenant & Réinitialisation Démo
    Route::get('/state', [SaasWorkspaceController::class, 'getState']);
    Route::post('/reset', [SaasWorkspaceController::class, 'resetTenant']);
    Route::get('/dashboard/overview', [SaasWorkspaceController::class, 'getState']);
    Route::get('/dashboard/staff-on-duty', [DashboardController::class, 'getStaffOnDuty']);
    Route::get('/dashboard/triage-alerts', [DashboardController::class, 'getTriageAlerts']);

    // 2. Gestion Complète des Rendez-vous & Agenda (Ajouter / Modifier / Supprimer)
    Route::get('/appointments', [SaasWorkspaceController::class, 'listAppointments']);
    Route::post('/appointments', [SaasWorkspaceController::class, 'createAppointment']);
    Route::put('/appointments/{id}', [SaasWorkspaceController::class, 'updateAppointment']);
    Route::patch('/appointments/{id}', [SaasWorkspaceController::class, 'updateAppointment']);
    Route::patch('/appointments/{id}/status', [SaasWorkspaceController::class, 'updateAppointmentStatus']);
    Route::delete('/appointments/{id}', [SaasWorkspaceController::class, 'deleteAppointment']);
    Route::get('/practitioners/{id}/slots', [AppointmentController::class, 'getAvailableSlots']);

    // 2b. Gestion des Médecins & Praticiens (Ajouter / Modifier / Supprimer)
    Route::get('/doctors', [SaasWorkspaceController::class, 'listDoctors']);
    Route::post('/doctors', [SaasWorkspaceController::class, 'createDoctor']);
    Route::put('/doctors/{id}', [SaasWorkspaceController::class, 'updateDoctor']);
    Route::patch('/doctors/{id}', [SaasWorkspaceController::class, 'updateDoctor']);
    Route::delete('/doctors/{id}', [SaasWorkspaceController::class, 'deleteDoctor']);

    // 3. Patients & Dossier Médical Électronique (DME)
    Route::get('/patients', [SaasWorkspaceController::class, 'listPatients']);
    Route::post('/patients', [SaasWorkspaceController::class, 'createPatient']);
    Route::delete('/patients/{id}', [SaasWorkspaceController::class, 'deletePatient']);
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

    // 6. Facturation, Caisse MAD & Télétransmission CNSS / AMO
    Route::get('/invoices', [SaasWorkspaceController::class, 'listInvoices']);
    Route::post('/invoices', [SaasWorkspaceController::class, 'createInvoice']);
    Route::post('/invoices/{id}/payments', [SaasWorkspaceController::class, 'recordInvoicePayment']);
    Route::patch('/invoices/{id}/cnss', [SaasWorkspaceController::class, 'advanceCnssClaim']);
    Route::get('/cash-sessions/current', [InvoicingController::class, 'getCurrentCashSession']);

    // 7. Référentiel CNSS / Organismes
    Route::get('/cnss/claims', [CnssController::class, 'index']);
    Route::get('/cnss/claims/{id}', [CnssController::class, 'show']);
    Route::post('/cnss/claims/{id}/submit', [CnssController::class, 'submitClaim']);
    Route::get('/cnss/providers', [CnssController::class, 'getProviders']);
});
