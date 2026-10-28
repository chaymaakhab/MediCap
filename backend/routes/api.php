<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\InvoicingController;
use App\Http\Controllers\CnssController;

/*
|--------------------------------------------------------------------------
| Cliniqo Medical REST API Routes (v1)
|--------------------------------------------------------------------------
| Conçu pour le Web Back-office & l'application Mobile Flutter
*/

Route::prefix('v1')->middleware(['tenant.isolate'])->group(function () {
    
    // 1. Dashboard Overview (Dr. Chen Overview, Live Stats & Triage)
    Route::get('/dashboard/overview', [DashboardController::class, 'getOverview']);
    Route::get('/dashboard/staff-on-duty', [DashboardController::class, 'getStaffOnDuty']);
    Route::get('/dashboard/triage-alerts', [DashboardController::class, 'getTriageAlerts']);

    // 2. Appointments & Scheduling (Schedule Matrix)
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::get('/appointments/{id}', [AppointmentController::class, 'show']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::patch('/appointments/{id}/status', [AppointmentController::class, 'updateStatus']);
    Route::get('/practitioners/{id}/slots', [AppointmentController::class, 'getAvailableSlots']);

    // 3. Patients & Family Dossiers
    Route::get('/patients', [PatientController::class, 'index']);
    Route::get('/patients/{id}', [PatientController::class, 'show']);
    Route::post('/patients', [PatientController::class, 'store']);
    Route::get('/patients/{id}/family', [PatientController::class, 'getFamilyDossier']);
    Route::get('/patients/{id}/encounters', [PatientController::class, 'getEncountersHistory']);

    // 4. Clinical Encounters, Vitals & Prescriptions (DME)
    Route::post('/encounters/start', [ConsultationController::class, 'startEncounter']);
    Route::post('/encounters/{id}/vitals', [ConsultationController::class, 'recordVitals']);
    Route::post('/encounters/{id}/notes', [ConsultationController::class, 'saveNotes']);
    Route::post('/encounters/{id}/prescriptions', [ConsultationController::class, 'issuePrescription']);

    // 5. Invoicing, Payments & Cashbox (MAD)
    Route::get('/invoices', [InvoicingController::class, 'index']);
    Route::get('/invoices/{id}', [InvoicingController::class, 'show']);
    Route::post('/invoices', [InvoicingController::class, 'store']);
    Route::post('/invoices/{id}/payments', [InvoicingController::class, 'recordPayment']);
    Route::get('/cash-sessions/current', [InvoicingController::class, 'getCurrentCashSession']);

    // 6. CNSS, AMO & Reimbursement Claims
    Route::get('/cnss/claims', [CnssController::class, 'index']);
    Route::get('/cnss/claims/{id}', [CnssController::class, 'show']);
    Route::post('/cnss/claims/{id}/submit', [CnssController::class, 'submitClaim']);
    Route::get('/cnss/providers', [CnssController::class, 'getProviders']);
});
