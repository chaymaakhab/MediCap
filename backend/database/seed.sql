-- =============================================================================
-- CLINIQO MEDICAL - REALISTIC SEED DATA (Conforme aux maquettes & Screenshots)
-- Polyclinique Grand Central • Direction Médicale Dr. Sarah Chen
-- =============================================================================

-- 1. Tenant & Branch
INSERT INTO tenants (id, uuid, name, subdomain, legal_name, tax_id, phone, email, address, city, country_code, currency)
VALUES (1, '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 'Grand Central Polyclinic', 'grandcentral', 'Grand Central Healthcare SARL', 'ICE-00293481000038', '+212 522 98 44 00', 'contact@grandcentral.ma', 'Angle Bd d’Anfa & Zerktouni', 'Casablanca', 'MAR', 'MAD');

INSERT INTO branches (id, tenant_id, name, code, phone, address, city, is_main)
VALUES (1, 1, 'Primary Care Branch', 'GC-MAIN-01', '+212 522 98 44 01', 'Boulevard d’Anfa, Étage 1-3', 'Casablanca', 1);

-- 2. Subscription Plans & Subscription
INSERT INTO subscription_plans (id, code, name, max_practitioners, max_branches, max_users, price_monthly, price_yearly)
VALUES 
(1, 'SOLO', 'Cabinet Solo', 1, 1, 2, 490.00, 4900.00),
(2, 'GROUPE', 'Cabinet de Groupe', 5, 2, 9, 1290.00, 12900.00),
(3, 'POLYCLINIQUE', 'Centre Multidisciplinaire & Polyclinique', 999, 20, 999, 3490.00, 34900.00);

INSERT INTO subscriptions (id, tenant_id, plan_id, status, starts_at, ends_at)
VALUES (1, 1, 3, 'active', '2026-01-01 00:00:00', '2026-12-31 23:59:59');

-- 3. Roles
INSERT INTO roles (id, tenant_id, name, display_name, description, is_system)
VALUES 
(1, 1, 'medical_director', 'Directrice Médicale', 'Supervision clinique globale & validation dossiers', 1),
(2, 1, 'doctor', 'Médecin Spécialiste', 'Agenda, consultations, ordonnances, dossiers patients', 1),
(3, 1, 'nurse', 'Infirmière / Personnel Soignant', 'Prise des constantes, soins et préparation', 1),
(4, 1, 'receptionist', 'Secrétariat & Accueil', 'Accueil, agenda téléphonique, encaissements autorisés', 1),
(5, 1, 'accountant', 'Comptabilité & Caisse', 'Gestion des factures, caisse journalière et bordereaux CNSS', 1);

-- 4. Users (Matching Screenshot 2 & 3)
INSERT INTO users (id, tenant_id, name, email, password, phone, avatar_url, job_title)
VALUES 
(1, 1, 'Dr. Sarah Chen', 'dr.chen@grandcentral.ma', '$2y$12$K1.dummyhashforcliniqoChen2026', '+212 661 11 22 33', 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120', 'Medical Director'),
(2, 1, 'Dr. Anil Patel', 'dr.patel@grandcentral.ma', '$2y$12$K1.dummyhashforcliniqoPatel2026', '+212 661 22 33 44', 'https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=120', 'Chief Cardiologist'),
(3, 1, 'Dr. Michael Chen', 'dr.mchen@grandcentral.ma', '$2y$12$K1.dummyhashforcliniqoMChen2026', '+212 661 33 44 55', 'https://images.unsplash.com/photo-1537368910025-700350fe46c7?w=120', 'Orthopedic Surgeon'),
(4, 1, 'Dr. Amina Ndiaye', 'dr.ndiaye@grandcentral.ma', '$2y$12$K1.dummyhashforcliniqoNdiaye2026', '+212 661 44 55 66', 'https://images.unsplash.com/photo-1594824813637-440263f9059f?w=120', 'Pediatric Specialist'),
(5, 1, 'Rachel K.', 'rachel.k@grandcentral.ma', '$2y$12$K1.dummyhashforcliniqoRachel2026', '+212 661 55 66 77', NULL, 'Head Nurse RN'),
(6, 1, 'Nadia Bennani', 'nadia.reception@grandcentral.ma', '$2y$12$K1.dummyhashforcliniqoNadia2026', '+212 661 66 77 88', NULL, 'Lead Medical Receptionist');

INSERT INTO user_roles (user_id, role_id)
VALUES (1, 1), (2, 2), (3, 2), (4, 2), (5, 3), (6, 4);

-- 5. Specialties & Rooms
INSERT INTO specialties (id, tenant_id, name, code, description)
VALUES 
(1, 1, 'Médecine Interne & Urgences', 'MED-INT', 'Consultations générales et orientation diagnostique'),
(2, 1, 'Cardiologie & Vasculaire', 'CARDIO', 'Bilan cardio, échographie Doppler, ECG d’effort'),
(3, 1, 'Chirurgie Orthopédique', 'ORTHO', 'Traumatologie, articulations et suivi post-opératoire'),
(4, 1, 'Pédiatrie', 'PED', 'Suivi de développement de l’enfant et vaccinations');

INSERT INTO rooms (id, tenant_id, branch_id, name, room_type, current_status)
VALUES 
(101, 1, 1, 'Exam Room 101', 'exam', 'occupied'),
(102, 1, 1, 'Consult Suite 102', 'consultation', 'occupied'),
(104, 1, 1, 'Exam Room 104', 'exam', 'available'),
(108, 1, 1, 'Consult Suite 108', 'consultation', 'available'),
(204, 1, 1, 'ECG Lab B', 'lab', 'sanitizing'),
(301, 1, 1, 'OR Suite 3 (Chirurgie)', 'surgery_theatre', 'occupied');

-- 6. Practitioners
INSERT INTO practitioners (id, tenant_id, user_id, specialty_id, order_number, consultation_duration_minutes, default_consultation_fee, is_on_duty, duty_status)
VALUES 
(1, 1, 1, 1, 'OM-7741-MA', 30, 350.00, 1, 'available'),
(2, 1, 2, 2, 'OM-6632-MA', 45, 450.00, 1, 'in_consultation'),
(3, 1, 3, 3, 'OM-8820-MA', 45, 500.00, 1, 'in_surgery'),
(4, 1, 4, 4, 'OM-9912-MA', 30, 300.00, 1, 'on_duty');

-- 7. Patients (Matching Screenshot 2 & 3)
INSERT INTO patients (id, tenant_id, file_number, first_name, last_name, cin_number, birth_date, gender, blood_group, phone, email, address, medical_allergies)
VALUES 
(1, 1, 'PT-4429', 'Eleanor', 'Pena', 'BE-882910', '1968-04-12', 'female', 'A+', '+212 662 14 20 88', 'eleanor.pena@gmail.com', 'Quartier Racine, Casablanca', 'Pénicilline'),
(2, 1, 'PT-9014', 'Wade', 'Warren', 'BK-339182', '1979-11-23', 'male', 'O+', '+212 661 99 88 77', 'wade.warren@outlook.com', 'Gauthier, Casablanca', 'Aucune'),
(3, 1, 'PT-9132', 'Jacob', 'Jones', 'BJ-129984', '1992-07-05', 'male', 'B+', '+212 663 44 22 11', 'jacob.jones@yahoo.com', 'Oasis, Casablanca', 'Aspirine'),
(4, 1, 'PT-7945', 'Dianne', 'Russell', 'BH-772183', '1985-02-18', 'female', 'AB+', '+212 665 88 11 33', 'dianne.r@gmail.com', 'Californie, Casablanca', 'Sulfamides');

-- Coverage Providers
INSERT INTO coverage_providers (id, tenant_id, code, name, provider_type, direct_billing_supported, contact_phone)
VALUES 
(1, 1, 'CNSS', 'Caisse Nationale de Sécurité Sociale (AMO)', 'CNSS', 1, '3939'),
(2, 1, 'CNOPS', 'Caisse Nationale des Organismes de Prévoyance Sociale', 'CNOPS', 1, '+212 537 77 88 99'),
(3, 1, 'WAFA_ASSUR', 'Wafa Assurance Santé Tierce', 'PRIVATE', 1, '+212 522 54 55 56'),
(4, 1, 'AXA_MAROC', 'AXA Assurances Maroc', 'PRIVATE', 1, '+212 522 88 99 00');

INSERT INTO patient_coverages (id, tenant_id, patient_id, provider_type, provider_name, policy_number, coverage_rate)
VALUES 
(1, 1, 1, 'CNSS', 'CNSS AMO', 'CNSS-884910283', 80.00),
(2, 1, 2, 'PRIVATE_INSURANCE', 'Wafa Assurance', 'WAFA-POL-9921', 85.00),
(3, 1, 3, 'AMO', 'AMO Indépendants', 'AMO-553920192', 70.00),
(4, 1, 4, 'CNSS', 'CNSS AMO Salariés', 'CNSS-338192004', 80.00);

-- 8. Appointments Today (Matching Screenshot 2 & 3)
INSERT INTO appointments (id, tenant_id, branch_id, practitioner_id, patient_id, room_id, reference_code, appointment_date, start_time, end_time, duration_minutes, status, channel, priority, reason)
VALUES 
(1, 1, 1, 2, 1, 102, 'APT-2026-001', CURDATE(), '09:00:00', '09:30:00', 30, 'completed', 'web_public', 'routine', 'Routine Cardiology Checkup • Dr. Anil Patel • Room 102'),
(2, 1, 1, 2, 2, 204, 'APT-2026-002', CURDATE(), '09:45:00', '10:30:00', 45, 'in_consultation', 'phone', 'urgent', 'ECG Diagnostics & Rhythm Review • 45 min • Room 204'),
(3, 1, 1, 1, 3, 101, 'APT-2026-003', CURDATE(), '10:30:00', '11:00:00', 30, 'pending', 'internal_desk', 'routine', 'Initial Internal Consultation • Dr. Sarah Chen • Room 101'),
(4, 1, 1, 3, 4, 104, 'APT-2026-004', CURDATE(), '11:15:00', '12:00:00', 45, 'confirmed', 'web_public', 'routine', 'Post-Surgery Follow-up • Dr. Michael Chen • Room 104 (CNSS 80%)');

-- 9. Clinical Encounters & Vitals (Eleanor Pena & Wade Warren)
INSERT INTO encounters (id, tenant_id, appointment_id, patient_id, practitioner_id, branch_id, started_at, status)
VALUES 
(1, 1, 1, 1, 2, 1, CONCAT(CURDATE(), ' 09:02:00'), 'completed'),
(2, 1, 2, 2, 2, 1, CONCAT(CURDATE(), ' 09:46:00'), 'active');

INSERT INTO vitals (id, encounter_id, systolic_bp, diastolic_bp, mean_arterial_pressure, heart_rate, spo2, temperature, blood_glucose)
VALUES 
(1, 1, 128, 82, 97.3, 72, 98.8, 36.8, 98.0),
(2, 2, 122, 78, 92.0, 74, 98.4, 37.0, 104.0);

-- 10. Invoices, Payments ($4,850.00 Collected, $1,180.00 Claims)
INSERT INTO invoices (id, tenant_id, branch_id, patient_id, encounter_id, invoice_number, currency, subtotal, total_amount, paid_amount, balance_due, status, issued_date)
VALUES 
(1, 1, 1, 1, 1, 'INV-2026-0081', 'MAD', 450.00, 450.00, 450.00, 0.00, 'paid', CURDATE()),
(2, 1, 1, 2, 2, 'INV-2026-0082', 'MAD', 800.00, 800.00, 500.00, 300.00, 'partially_paid', CURDATE()),
(3, 1, 1, 4, NULL, 'INV-2026-0083', 'MAD', 1600.00, 1600.00, 1600.00, 0.00, 'paid', CURDATE());

-- Payments Breakdown (Cash 1,200 DH, POS/TPE 2,450 DH, CNSS direct 1,200 DH = Total 4,850 DH)
INSERT INTO payments (id, tenant_id, branch_id, invoice_id, patient_id, receipt_number, amount, currency, payment_method, reference_info, received_by_user_id)
VALUES 
(1, 1, 1, 1, 1, 'REC-2026-0120', 450.00, 'MAD', 'credit_card', 'TPE-CMI-88190', 6),
(2, 1, 1, 2, 2, 'REC-2026-0121', 300.00, 'MAD', 'cash', 'Especes Acompte 1', 6),
(3, 1, 1, 2, 2, 'REC-2026-0122', 200.00, 'MAD', 'cash', 'Especes Acompte 2', 6),
(4, 1, 1, 3, 4, 'REC-2026-0123', 2000.00, 'MAD', 'credit_card', 'TPE-CMI-88191', 6),
(5, 1, 1, 3, 4, 'REC-2026-0124', 1900.00, 'MAD', 'bank_transfer', 'VIR-BP-99210', 6);

-- CNSS Unsettled Claims (5 dossiers pending tier-3 validation = 1,180.00 MAD)
INSERT INTO reimbursement_cases (id, tenant_id, patient_id, invoice_id, provider_id, external_claim_reference, claimed_amount, estimated_reimbursement, patient_copay_amount, status)
VALUES 
(1, 1, 1, 1, 1, 'BORD-CNSS-0941', 360.00, 288.00, 72.00, 'submitted'),
(2, 1, 2, 2, 3, 'BORD-WAFA-1102', 640.00, 544.00, 96.00, 'in_review'),
(3, 1, 4, 3, 1, 'BORD-CNSS-0942', 1280.00, 1024.00, 256.00, 'documents_missing');

-- Audit Log for CNDP Traceability
INSERT INTO audit_logs (tenant_id, user_id, action, entity_type, entity_id, ip_address, created_at)
VALUES 
(1, 1, 'VIEW_DAILY_DASHBOARD', 'dashboard', 1, '192.168.1.10', NOW()),
(1, 2, 'START_ENCOUNTER', 'encounters', 2, '192.168.1.25', NOW());
