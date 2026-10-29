<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaasWorkspaceController
{
    private function storageFilePath(): string
    {
        $dir = storage_path('app');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'cliniqo_multitenant_fr_v5.json';
    }

    private function resolveTenantKey(Request $request): string
    {
        $id = (int) ($request->attributes->get('active_tenant_id')
            ?? $request->header('X-Tenant-ID')
            ?? $request->query('tenant_id', 1));

        if (!in_array($id, [1, 2, 3], true)) {
            $id = 1;
        }
        return (string) $id;
    }

    private function loadDatabase(): array
    {
        $path = $this->storageFilePath();
        if (file_exists($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && isset($decoded['1']['doctors'])) {
                return $decoded;
            }
        }

        $seeded = [
            '1' => $this->buildTenantSeed(1),
            '2' => $this->buildTenantSeed(2),
            '3' => $this->buildTenantSeed(3),
        ];
        $this->saveDatabase($seeded);
        return $seeded;
    }

    private function saveDatabase(array $db): void
    {
        file_put_contents(
            $this->storageFilePath(),
            json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function sortAppointments(array &$appointments): void
    {
        usort($appointments, static function (array $a, array $b): int {
            return strcmp(
                ($a['date'] ?? '2026-10-29') . ' ' . ($a['time'] ?? '00:00'),
                ($b['date'] ?? '2026-10-29') . ' ' . ($b['time'] ?? '00:00')
            );
        });
    }

    private function respondWithState(array $payload, float $startedAt, int $status = 200): JsonResponse
    {
        $payload['meta'] = [
            'engine' => 'Cliniqo Cloud Multi-Tenant v5 (FR)',
            'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'timestamp' => date('c'),
        ];
        return response()->json($payload, $status);
    }

    private function buildTenantSeed(int $tenantId): array
    {
        $tenants = [
            1 => [
                'id' => 1,
                'name' => 'Polyclinique Grand Central',
                'short' => 'Grand Central',
                'city' => 'Casablanca',
                'branch' => 'Casablanca • Médecine Générale & Cardiologie',
                'patients_today' => 248,
                'tests_conducted' => 1024,
                'revenue_today' => 185000,
                'specialists_count' => 120,
            ],
            2 => [
                'id' => 2,
                'name' => 'Centre Diagnostic Anfa',
                'short' => 'Anfa Diagnostic',
                'city' => 'Casablanca',
                'branch' => 'Casablanca • Biologie & Oncologie',
                'patients_today' => 184,
                'tests_conducted' => 1490,
                'revenue_today' => 214500,
                'specialists_count' => 86,
            ],
            3 => [
                'id' => 3,
                'name' => 'Clinique Agdal Santé',
                'short' => 'Agdal Santé',
                'city' => 'Rabat',
                'branch' => 'Rabat • Pôle Multidisciplinaire',
                'patients_today' => 162,
                'tests_conducted' => 820,
                'revenue_today' => 142800,
                'specialists_count' => 64,
            ],
        ];

        $cfg = $tenants[$tenantId] ?? $tenants[1];

        return [
            'tenant' => [
                'id' => $cfg['id'],
                'name' => $cfg['name'],
                'short' => $cfg['short'],
                'city' => $cfg['city'],
                'branch' => $cfg['branch'],
                'compliance' => 'Certifié CNDP Loi 09-08 • CNSS AMO • HDS',
            ],
            'kpis' => [
                'patients_today' => $cfg['patients_today'],
                'tests_conducted' => $cfg['tests_conducted'],
                'revenue_today' => $cfg['revenue_today'],
                'specialists_count' => $cfg['specialists_count'],
            ],
            'doctors' => [
                [
                    'id' => 1,
                    'name' => 'Dr. Alimi Jan',
                    'specialty' => 'Cardiologue',
                    'room' => 'Salle 102',
                    'fee_mad' => 650,
                    'inpe' => 'INPE-0412890',
                    'phone' => '+212 661-102030',
                    'avatar' => 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120',
                ],
                [
                    'id' => 2,
                    'name' => 'Dr. Karim Tazi',
                    'specialty' => 'Neurologue',
                    'room' => 'Labo Neuro 2',
                    'fee_mad' => 850,
                    'inpe' => 'INPE-0591124',
                    'phone' => '+212 661-334455',
                    'avatar' => 'https://images.unsplash.com/photo-1537368910025-700350fe46c7?w=120',
                ],
                [
                    'id' => 3,
                    'name' => 'Dr. Mike Millars',
                    'specialty' => 'Psychologue',
                    'room' => 'Cabinet 105',
                    'fee_mad' => 500,
                    'inpe' => 'INPE-0773201',
                    'phone' => '+212 662-556677',
                    'avatar' => 'https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=120',
                ],
                [
                    'id' => 4,
                    'name' => 'Dr. Sarah Chen',
                    'specialty' => 'Cardiologue',
                    'room' => 'Salle 101',
                    'fee_mad' => 600,
                    'inpe' => 'INPE-0884512',
                    'phone' => '+212 663-778899',
                    'avatar' => 'https://images.unsplash.com/photo-1651008376811-b90baee60c1f?w=120',
                ],
                [
                    'id' => 5,
                    'name' => 'Dr. Nadia Berrada',
                    'specialty' => 'Psychologue',
                    'room' => 'Cabinet 106',
                    'fee_mad' => 550,
                    'inpe' => 'INPE-0912044',
                    'phone' => '+212 664-990011',
                    'avatar' => 'https://images.unsplash.com/photo-1527613426441-4da17471b66d?w=120',
                ],
            ],
            'weekly_patients' => [
                ['day' => 'Dim', 'count' => 118, 'growth' => '+12%', 'h' => 42],
                ['day' => 'Lun', 'count' => 164, 'growth' => '+24%', 'h' => 62],
                ['day' => 'Mar', 'count' => 149, 'growth' => '+18%', 'h' => 55],
                ['day' => 'Mer', 'count' => 192, 'growth' => '+39%', 'h' => 76],
                ['day' => 'Jeu', 'count' => 138, 'growth' => '+15%', 'h' => 49],
                ['day' => 'Ven', 'count' => 176, 'growth' => '+31%', 'h' => 68],
                ['day' => 'Sam', 'count' => $cfg['patients_today'], 'growth' => '+65%', 'h' => 94],
            ],
            'patients' => [
                [
                    'id' => 1,
                    'code' => 'PT-4429',
                    'first' => 'Salma',
                    'last' => 'Bennani',
                    'cin' => 'BE-882910',
                    'age' => 58,
                    'gender' => 'F',
                    'blood' => 'A+',
                    'phone' => '+212 661-234890',
                    'coverage' => 'CNSS AMO (80%)',
                    'department' => 'Cardiologie',
                    'allergy' => 'Pénicilline',
                    'bp' => '126/80',
                    'spo2' => '98.6%',
                    'hr' => '74 bpm',
                    'avatar' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=120',
                    'notes' => [
                        [
                            'date' => '29-10-2026 09:05',
                            'doctor' => 'Dr. Alimi Jan',
                            'icd' => 'BA00 • Hypertension essentielle',
                            'text' => 'Tension artérielle stabilisée sous Bisoprolol 5mg. Écho-Doppler cardiaque normal.',
                        ],
                    ],
                ],
                [
                    'id' => 2,
                    'code' => 'PT-9014',
                    'first' => 'Youssef',
                    'last' => 'El Mansouri',
                    'cin' => 'BH-451029',
                    'age' => 44,
                    'gender' => 'M',
                    'blood' => 'O+',
                    'phone' => '+212 662-908123',
                    'coverage' => 'CNOPS (90%)',
                    'department' => 'Neurologie',
                    'allergy' => 'Aucune connue',
                    'bp' => '132/84',
                    'spo2' => '99.0%',
                    'hr' => '78 bpm',
                    'avatar' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=120',
                    'notes' => [
                        [
                            'date' => '28-10-2026 16:20',
                            'doctor' => 'Dr. Karim Tazi',
                            'icd' => '8A80 • Migraine avec aura',
                            'text' => 'Électroencéphalogramme (EEG) sans anomalie paroxystique. Traitement prophylactique reconduit.',
                        ],
                    ],
                ],
                [
                    'id' => 3,
                    'code' => 'PT-9132',
                    'first' => 'Mehdi',
                    'last' => 'Chraibi',
                    'cin' => 'AB-774120',
                    'age' => 36,
                    'gender' => 'M',
                    'blood' => 'B+',
                    'phone' => '+212 664-112233',
                    'coverage' => 'AXA Assurance (85%)',
                    'department' => 'Urologie',
                    'allergy' => 'Aspirine (AINS)',
                    'bp' => '118/76',
                    'spo2' => '99.2%',
                    'hr' => '68 bpm',
                    'avatar' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=120',
                    'notes' => [],
                ],
                [
                    'id' => 4,
                    'code' => 'PT-7945',
                    'first' => 'Kenza',
                    'last' => 'Berrada',
                    'cin' => 'CD-339011',
                    'age' => 49,
                    'gender' => 'F',
                    'blood' => 'AB+',
                    'phone' => '+212 668-445566',
                    'coverage' => 'CNSS AMO (80%)',
                    'department' => 'Oncologie',
                    'allergy' => 'Produits iodés',
                    'bp' => '122/78',
                    'spo2' => '98.4%',
                    'hr' => '72 bpm',
                    'avatar' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=120',
                    'notes' => [],
                ],
                [
                    'id' => 5,
                    'code' => 'PT-6210',
                    'first' => 'Zineb',
                    'last' => 'Tahiri',
                    'cin' => 'EE-910442',
                    'age' => 31,
                    'gender' => 'F',
                    'blood' => 'O-',
                    'phone' => '+212 661-998877',
                    'coverage' => 'CNOPS (90%)',
                    'department' => 'Cardiologie',
                    'allergy' => 'Latex',
                    'bp' => '116/74',
                    'spo2' => '99.4%',
                    'hr' => '69 bpm',
                    'avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=120',
                    'notes' => [],
                ],
            ],
            'appointments' => [
                // 26 Octobre 2026
                [
                    'id' => 98,
                    'patient_id' => 2,
                    'date' => '2026-10-26',
                    'time' => '09:30',
                    'doctor' => 'Dr. Karim Tazi',
                    'specialty' => 'Neurologue',
                    'room' => 'Labo Neuro 2',
                    'channel' => 'Présentiel',
                    'status' => 'completed',
                    'fee_mad' => 850,
                    'billed' => true,
                    'reason' => 'Bilan céphalées chroniques & EEG',
                ],
                // 27 Octobre 2026
                [
                    'id' => 99,
                    'patient_id' => 1,
                    'date' => '2026-10-27',
                    'time' => '10:00',
                    'doctor' => 'Dr. Alimi Jan',
                    'specialty' => 'Cardiologue',
                    'room' => 'Salle 102',
                    'channel' => 'Présentiel',
                    'status' => 'completed',
                    'fee_mad' => 650,
                    'billed' => true,
                    'reason' => 'Suivi Holter tensionnel 24h',
                ],
                [
                    'id' => 100,
                    'patient_id' => 4,
                    'date' => '2026-10-27',
                    'time' => '15:00',
                    'doctor' => 'Dr. Sarah Chen',
                    'specialty' => 'Cardiologue',
                    'room' => 'Salle 101',
                    'channel' => 'Téléconsultation',
                    'status' => 'completed',
                    'fee_mad' => 600,
                    'billed' => true,
                    'reason' => 'Lecture résultats bilan pré-thérapeutique',
                ],
                // 28 Octobre 2026
                [
                    'id' => 101,
                    'patient_id' => 5,
                    'date' => '2026-10-28',
                    'time' => '11:00',
                    'doctor' => 'Dr. Nadia Berrada',
                    'specialty' => 'Psychologue',
                    'room' => 'Cabinet 106',
                    'channel' => 'Présentiel',
                    'status' => 'completed',
                    'fee_mad' => 550,
                    'billed' => true,
                    'reason' => 'Suivi gestion du stress & sommeil',
                ],
                [
                    'id' => 102,
                    'patient_id' => 3,
                    'date' => '2026-10-28',
                    'time' => '16:00',
                    'doctor' => 'Dr. Mike Millars',
                    'specialty' => 'Psychologue',
                    'room' => 'Cabinet 105',
                    'channel' => 'Téléconsultation',
                    'status' => 'completed',
                    'fee_mad' => 500,
                    'billed' => true,
                    'reason' => 'Entretien de suivi mensuel',
                ],
                // AUJOURD'HUI : 29 Octobre 2026 (6 rendez-vous)
                [
                    'id' => 103,
                    'patient_id' => 1,
                    'date' => '2026-10-29',
                    'time' => '09:00',
                    'doctor' => 'Dr. Alimi Jan',
                    'specialty' => 'Cardiologue',
                    'room' => 'Salle 102',
                    'channel' => 'Présentiel',
                    'status' => 'completed',
                    'fee_mad' => 650,
                    'billed' => true,
                    'reason' => 'Contrôle Écho-Doppler cardiaque',
                ],
                [
                    'id' => 104,
                    'patient_id' => 2,
                    'date' => '2026-10-29',
                    'time' => '09:45',
                    'doctor' => 'Dr. Karim Tazi',
                    'specialty' => 'Neurologue',
                    'room' => 'Labo Neuro 2',
                    'channel' => 'Présentiel',
                    'status' => 'in_progress',
                    'fee_mad' => 850,
                    'billed' => false,
                    'reason' => 'Exploration EEG & suivi migraine',
                ],
                [
                    'id' => 105,
                    'patient_id' => 3,
                    'date' => '2026-10-29',
                    'time' => '10:30',
                    'doctor' => 'Dr. Mike Millars',
                    'specialty' => 'Psychologue',
                    'room' => 'Cabinet 105',
                    'channel' => 'Présentiel',
                    'status' => 'waiting',
                    'fee_mad' => 500,
                    'billed' => false,
                    'reason' => 'Séance thérapie cognitivo-comportementale',
                ],
                [
                    'id' => 106,
                    'patient_id' => 4,
                    'date' => '2026-10-29',
                    'time' => '11:30',
                    'doctor' => 'Dr. Sarah Chen',
                    'specialty' => 'Cardiologue',
                    'room' => 'Salle 101',
                    'channel' => 'Urgence',
                    'status' => 'confirmed',
                    'fee_mad' => 750,
                    'billed' => false,
                    'reason' => 'Évaluation cardio-oncologique prioritaire',
                ],
                [
                    'id' => 107,
                    'patient_id' => 5,
                    'date' => '2026-10-29',
                    'time' => '14:30',
                    'doctor' => 'Dr. Nadia Berrada',
                    'specialty' => 'Psychologue',
                    'room' => 'Cabinet 106',
                    'channel' => 'Téléconsultation',
                    'status' => 'confirmed',
                    'fee_mad' => 550,
                    'billed' => false,
                    'reason' => 'Téléconsultation suivi post-partum',
                ],
                [
                    'id' => 108,
                    'patient_id' => 1,
                    'date' => '2026-10-29',
                    'time' => '16:30',
                    'doctor' => 'Dr. Sarah Chen',
                    'specialty' => 'Cardiologue',
                    'room' => 'Salle 101',
                    'channel' => 'Présentiel',
                    'status' => 'confirmed',
                    'fee_mad' => 600,
                    'billed' => false,
                    'reason' => 'Ajustement posologique antihypertenseur',
                ],
                // 30 Octobre 2026
                [
                    'id' => 109,
                    'patient_id' => 3,
                    'date' => '2026-10-30',
                    'time' => '09:30',
                    'doctor' => 'Dr. Alimi Jan',
                    'specialty' => 'Cardiologue',
                    'room' => 'Salle 102',
                    'channel' => 'Présentiel',
                    'status' => 'confirmed',
                    'fee_mad' => 650,
                    'billed' => false,
                    'reason' => 'Épreuve d’effort cardiovasculaire',
                ],
                [
                    'id' => 110,
                    'patient_id' => 4,
                    'date' => '2026-10-30',
                    'time' => '11:00',
                    'doctor' => 'Dr. Karim Tazi',
                    'specialty' => 'Neurologue',
                    'room' => 'Labo Neuro 2',
                    'channel' => 'Présentiel',
                    'status' => 'confirmed',
                    'fee_mad' => 850,
                    'billed' => false,
                    'reason' => 'Contrôle sensibilité périphérique',
                ],
                [
                    'id' => 111,
                    'patient_id' => 2,
                    'date' => '2026-10-30',
                    'time' => '15:00',
                    'doctor' => 'Dr. Nadia Berrada',
                    'specialty' => 'Psychologue',
                    'room' => 'Cabinet 106',
                    'channel' => 'Téléconsultation',
                    'status' => 'waiting',
                    'fee_mad' => 550,
                    'billed' => false,
                    'reason' => 'Accompagnement biofeedback',
                ],
                // 31 Octobre 2026
                [
                    'id' => 112,
                    'patient_id' => 5,
                    'date' => '2026-10-31',
                    'time' => '10:30',
                    'doctor' => 'Dr. Alimi Jan',
                    'specialty' => 'Cardiologue',
                    'room' => 'Salle 102',
                    'channel' => 'Présentiel',
                    'status' => 'confirmed',
                    'fee_mad' => 650,
                    'billed' => false,
                    'reason' => 'Bilan cardiologique préventif annuel',
                ],
                // 02 Novembre 2026
                [
                    'id' => 113,
                    'patient_id' => 1,
                    'date' => '2026-11-02',
                    'time' => '09:00',
                    'doctor' => 'Dr. Karim Tazi',
                    'specialty' => 'Neurologue',
                    'room' => 'Labo Neuro 2',
                    'channel' => 'Présentiel',
                    'status' => 'confirmed',
                    'fee_mad' => 850,
                    'billed' => false,
                    'reason' => 'Doppler des troncs supra-aortiques',
                ],
                [
                    'id' => 114,
                    'patient_id' => 3,
                    'date' => '2026-11-02',
                    'time' => '14:00',
                    'doctor' => 'Dr. Sarah Chen',
                    'specialty' => 'Cardiologue',
                    'room' => 'Salle 101',
                    'channel' => 'Présentiel',
                    'status' => 'confirmed',
                    'fee_mad' => 600,
                    'billed' => false,
                    'reason' => 'Synthèse bilan cardio-métabolique',
                ],
            ],
            'labs' => [
                [
                    'id' => 501,
                    'code' => 'LAB-8821',
                    'patient_id' => 1,
                    'patient_name' => 'Salma Bennani',
                    'test_name' => 'Ionogramme Sanguin & Kaliémie (K+)',
                    'category' => 'Biochimie',
                    'value' => '5.8 mEq/L',
                    'reference' => '3.5 - 5.1 mEq/L',
                    'priority' => 'URGENT',
                    'status' => 'flagged',
                    'ordered_by' => 'Dr. Alimi Jan',
                    'timestamp' => '09:18',
                ],
                [
                    'id' => 502,
                    'code' => 'LAB-8824',
                    'patient_id' => 2,
                    'patient_name' => 'Youssef El Mansouri',
                    'test_name' => 'Panel Neuro-Métabolique & Magnésium',
                    'category' => 'Neurologie',
                    'value' => 'En cours d’analyse',
                    'reference' => '1.7 - 2.2 mg/dL',
                    'priority' => 'Routine',
                    'status' => 'in_analysis',
                    'ordered_by' => 'Dr. Karim Tazi',
                    'timestamp' => '09:50',
                ],
                [
                    'id' => 503,
                    'code' => 'LAB-8819',
                    'patient_id' => 4,
                    'patient_name' => 'Kenza Berrada',
                    'test_name' => 'Marqueurs CA 15-3 & NFS Complète',
                    'category' => 'Oncologie',
                    'value' => '18.4 U/mL',
                    'reference' => '< 30.0 U/mL',
                    'priority' => 'Prioritaire',
                    'status' => 'validated',
                    'ordered_by' => 'Dr. Sarah Chen',
                    'timestamp' => '08:40',
                ],
            ],
            'invoices' => [
                [
                    'id' => 901,
                    'num' => 'FAC-2026-3011',
                    'patient_id' => 1,
                    'patient' => 'Salma Bennani',
                    'service' => 'Écho-Doppler Cardiaque + ECG',
                    'total' => 850.00,
                    'paid' => 850.00,
                    'method' => 'TPE CMI',
                    'cnss_org' => 'CNSS AMO',
                    'cnss_est' => 680.00,
                    'cnss_status' => 'Transmis CNSS',
                    'date' => '29-10-2026',
                ],
                [
                    'id' => 902,
                    'num' => 'FAC-2026-3012',
                    'patient_id' => 2,
                    'patient' => 'Youssef El Mansouri',
                    'service' => 'Cartographie EEG + Consultation Neuro',
                    'total' => 1200.00,
                    'paid' => 400.00,
                    'method' => 'Espèces',
                    'cnss_org' => 'CNOPS',
                    'cnss_est' => 1080.00,
                    'cnss_status' => 'À télétransmettre',
                    'date' => '29-10-2026',
                ],
                [
                    'id' => 903,
                    'num' => 'FAC-2026-3013',
                    'patient_id' => 4,
                    'patient' => 'Kenza Berrada',
                    'service' => 'Bilan Oncologie & Marqueurs',
                    'total' => 1650.00,
                    'paid' => 0.00,
                    'method' => 'En attente',
                    'cnss_org' => 'CNSS AMO',
                    'cnss_est' => 1320.00,
                    'cnss_status' => 'Cachet manquant',
                    'date' => '29-10-2026',
                ],
            ],
            'audit_logs' => [
                [
                    'time' => '09:32:10',
                    'actor' => 'Dr. Jackson',
                    'action' => 'CHIFFREMENT_DME',
                    'target' => 'PT-4429 (Salma Bennani)',
                    'status' => 'CHIFFRÉ AES-256',
                ],
                [
                    'time' => '09:20:04',
                    'actor' => 'Automate Labo',
                    'action' => 'ALERTE_CRITIQUE_K+',
                    'target' => 'LAB-8821 (5.8 mEq/L)',
                    'status' => 'PRIORITÉ STAT',
                ],
                [
                    'time' => '09:05:44',
                    'actor' => 'Caisse #1',
                    'action' => 'ENCAISSEMENT_CMI',
                    'target' => 'FAC-2026-3011 (850 MAD)',
                    'status' => 'VALIDÉ',
                ],
            ],
        ];
    }

    private function checkScheduleConflict(
        array $appointments,
        string $date,
        string $time,
        string $doctor,
        string $room,
        int $patientId,
        ?int $ignoreId = null
    ): ?string {
        foreach ($appointments as $apt) {
            if ($ignoreId !== null && (int) ($apt['id'] ?? 0) === $ignoreId) {
                continue;
            }
            if (($apt['date'] ?? '') === $date && ($apt['time'] ?? '') === $time) {
                if (strcasecmp((string) ($apt['doctor'] ?? ''), $doctor) === 0) {
                    return "Conflit d'agenda : {$doctor} a déjà un rendez-vous le {$date} à {$time}.";
                }
                if ($room !== '' && strcasecmp((string) ($apt['room'] ?? ''), $room) === 0) {
                    return "Conflit de salle : {$room} est déjà occupée le {$date} à {$time}.";
                }
                if ($patientId > 0 && (int) ($apt['patient_id'] ?? 0) === $patientId) {
                    return "Conflit patient : ce patient a déjà un rendez-vous prévu le {$date} à {$time}.";
                }
            }
        }
        return null;
    }

    private function maybeAutoBillAppointment(array &$state, array &$apt): ?array
    {
        if (($apt['status'] ?? '') !== 'completed' || !empty($apt['billed'])) {
            return null;
        }

        $apt['billed'] = true;
        $fee = (float) ($apt['fee_mad'] ?? 600);
        $patient = $state['patients'][0] ?? ['id' => 1, 'first' => 'Patient', 'last' => 'Clinique', 'coverage' => 'CNSS AMO (80%)'];
        foreach ($state['patients'] as $p) {
            if ((int) $p['id'] === (int) ($apt['patient_id'] ?? 0)) {
                $patient = $p;
                break;
            }
        }

        $cov = (string) ($patient['coverage'] ?? 'CNSS AMO (80%)');
        $rate = str_contains($cov, '90%') ? 0.90 : (str_contains($cov, '85%') ? 0.85 : 0.80);
        $org = str_contains($cov, 'CNOPS') ? 'CNOPS' : (str_contains($cov, 'AXA') ? 'AXA Assurance' : 'CNSS AMO');

        $invoice = [
            'id' => (int) round(microtime(true) * 1000) + random_int(1, 99),
            'num' => 'FAC-2026-' . (3015 + count($state['invoices'])),
            'patient_id' => (int) $patient['id'],
            'patient' => trim($patient['first'] . ' ' . $patient['last']),
            'service' => ($apt['reason'] ?? 'Consultation Spécialisée') . ' (' . ($apt['doctor'] ?? 'Praticien') . ')',
            'total' => $fee,
            'paid' => 0.00,
            'method' => 'À encaisser',
            'cnss_org' => $org,
            'cnss_est' => round($fee * $rate, 2),
            'cnss_status' => 'À télétransmettre',
            'date' => date('d-m-Y'),
        ];

        array_unshift($state['invoices'], $invoice);
        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => 'Facturation Auto',
            'action' => 'GENERATION_FACTURE',
            'target' => "{$invoice['num']} ({$fee} MAD)",
            'status' => 'ÉMISE',
        ]);

        return $invoice;
    }

    public function getState(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = $db[$tk];
        $this->sortAppointments($state['appointments']);

        return $this->respondWithState([
            'success' => true,
            'centre' => $state['tenant'],
            'state' => $state,
        ], $start);
    }

    public function resetTenant(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $db[$tk] = $this->buildTenantSeed((int) $tk);
        $this->sortAppointments($db[$tk]['appointments']);
        $this->saveDatabase($db);

        return $this->respondWithState([
            'success' => true,
            'message' => 'Données de démonstration réinitialisées avec succès.',
            'state' => $db[$tk],
        ], $start);
    }

    // =========================================================================
    // RENDEZ-VOUS CRUD (GET, POST, PUT/PATCH, STATUS, DELETE)
    // =========================================================================
    public function listAppointments(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $list = $db[$tk]['appointments'];
        $this->sortAppointments($list);

        $date = trim((string) $request->query('date', ''));
        $doctor = trim((string) $request->query('doctor', ''));
        $status = trim((string) $request->query('status', ''));

        if ($date !== '') {
            $list = array_values(array_filter($list, static fn(array $a): bool => ($a['date'] ?? '') === $date));
        }
        if ($doctor !== '' && $doctor !== 'all') {
            $list = array_values(array_filter($list, static fn(array $a): bool => strcasecmp((string) ($a['doctor'] ?? ''), $doctor) === 0));
        }
        if ($status !== '' && $status !== 'all') {
            $list = array_values(array_filter($list, static fn(array $a): bool => ($a['status'] ?? '') === $status));
        }

        return $this->respondWithState([
            'success' => true,
            'count' => count($list),
            'data' => $list,
        ], $start);
    }

    public function createAppointment(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $date = trim((string) $request->input('date', '2026-10-29'));
        $time = trim((string) $request->input('time', '11:00'));
        $doctor = trim((string) $request->input('doctor', 'Dr. Alimi Jan'));
        $room = trim((string) $request->input('room', 'Salle 102'));
        $patientId = (int) $request->input('patient_id', $state['patients'][0]['id'] ?? 1);

        $conflict = $this->checkScheduleConflict($state['appointments'], $date, $time, $doctor, $room, $patientId, null);
        if ($conflict !== null) {
            return $this->respondWithState(['success' => false, 'message' => $conflict], $start, 409);
        }

        $newApt = [
            'id' => (int) round(microtime(true) * 1000),
            'patient_id' => $patientId,
            'date' => $date,
            'time' => $time,
            'doctor' => $doctor,
            'specialty' => trim((string) $request->input('specialty', 'Cardiologue')),
            'room' => $room,
            'channel' => trim((string) $request->input('channel', 'Présentiel')),
            'status' => trim((string) $request->input('status', 'confirmed')),
            'fee_mad' => max(50, (float) $request->input('fee_mad', 650)),
            'billed' => false,
            'reason' => trim((string) $request->input('reason', 'Consultation spécialisée')),
        ];

        $autoInvoice = $this->maybeAutoBillAppointment($state, $newApt);
        $state['appointments'][] = $newApt;
        $this->sortAppointments($state['appointments']);

        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => 'Secrétariat Agenda',
            'action' => 'AJOUT_RENDEZ_VOUS',
            'target' => "{$doctor} ({$date} à {$time})",
            'status' => 'CONFIRMÉ',
        ]);

        $this->saveDatabase($db);
        return $this->respondWithState([
            'success' => true,
            'message' => "Rendez-vous programmé le {$date} à {$time}.",
            'appointment' => $newApt,
            'auto_invoice' => $autoInvoice,
            'state' => $state,
        ], $start, 201);
    }

    public function updateAppointment(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $targetIndex = null;
        foreach ($state['appointments'] as $idx => $apt) {
            if ((int) $apt['id'] === $id) {
                $targetIndex = $idx;
                break;
            }
        }

        if ($targetIndex === null) {
            return $this->respondWithState(['success' => false, 'message' => 'Rendez-vous introuvable.'], $start, 404);
        }

        $current = $state['appointments'][$targetIndex];
        $newDate = trim((string) $request->input('date', $current['date'] ?? '2026-10-29'));
        $newTime = trim((string) $request->input('time', $current['time'] ?? '10:00'));
        $newDoctor = trim((string) $request->input('doctor', $current['doctor'] ?? 'Dr. Alimi Jan'));
        $newRoom = trim((string) $request->input('room', $current['room'] ?? 'Salle 102'));
        $newPatientId = (int) $request->input('patient_id', $current['patient_id'] ?? 1);

        $conflict = $this->checkScheduleConflict($state['appointments'], $newDate, $newTime, $newDoctor, $newRoom, $newPatientId, $id);
        if ($conflict !== null) {
            return $this->respondWithState(['success' => false, 'message' => $conflict], $start, 409);
        }

        $updated = array_merge($current, [
            'patient_id' => $newPatientId,
            'date' => $newDate,
            'time' => $newTime,
            'doctor' => $newDoctor,
            'specialty' => trim((string) $request->input('specialty', $current['specialty'] ?? 'Cardiologue')),
            'room' => $newRoom,
            'channel' => trim((string) $request->input('channel', $current['channel'] ?? 'Présentiel')),
            'fee_mad' => max(50, (float) $request->input('fee_mad', $current['fee_mad'] ?? 650)),
            'reason' => trim((string) $request->input('reason', $current['reason'] ?? 'Consultation')),
            'status' => trim((string) $request->input('status', $current['status'] ?? 'confirmed')),
        ]);

        $autoInvoice = $this->maybeAutoBillAppointment($state, $updated);
        $state['appointments'][$targetIndex] = $updated;
        $this->sortAppointments($state['appointments']);

        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => 'Secrétariat Agenda',
            'action' => 'MODIF_RENDEZ_VOUS',
            'target' => "#{$id} ({$newDate} à {$newTime})",
            'status' => 'MIS À JOUR',
        ]);

        $this->saveDatabase($db);
        return $this->respondWithState([
            'success' => true,
            'message' => 'Rendez-vous modifié avec succès.',
            'appointment' => $updated,
            'auto_invoice' => $autoInvoice,
            'state' => $state,
        ], $start);
    }

    public function updateAppointmentStatus(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $newStatus = trim((string) $request->input('status', 'completed'));
        $autoInvoice = null;

        foreach ($state['appointments'] as &$apt) {
            if ((int) $apt['id'] === $id) {
                $apt['status'] = $newStatus;
                $autoInvoice = $this->maybeAutoBillAppointment($state, $apt);
                array_unshift($state['audit_logs'], [
                    'time' => date('H:i:s'),
                    'actor' => $apt['doctor'] ?? 'Praticien',
                    'action' => 'STATUT_RDV_' . strtoupper($newStatus),
                    'target' => "RDV #{$id} ({$apt['time']})",
                    'status' => 'SYNCHRONISÉ',
                ]);
                break;
            }
        }
        unset($apt);

        $this->saveDatabase($db);
        return $this->respondWithState([
            'success' => true,
            'auto_invoice' => $autoInvoice,
            'state' => $state,
        ], $start);
    }

    public function deleteAppointment(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $deletedInfo = "RDV #{$id}";
        foreach ($state['appointments'] as $a) {
            if ((int) $a['id'] === $id) {
                $deletedInfo = "{$a['doctor']} ({$a['date']} à {$a['time']})";
                break;
            }
        }

        $state['appointments'] = array_values(array_filter(
            $state['appointments'],
            static fn(array $a): bool => (int) $a['id'] !== $id
        ));

        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => 'Secrétariat Agenda',
            'action' => 'SUPPRESSION_RDV',
            'target' => $deletedInfo,
            'status' => 'SUPPRIMÉ',
        ]);

        $this->saveDatabase($db);
        return $this->respondWithState([
            'success' => true,
            'deleted_id' => $id,
            'message' => 'Rendez-vous supprimé avec succès.',
            'state' => $state,
        ], $start);
    }

    // =========================================================================
    // PATIENTS & DOSSIER MÉDICAL ÉLECTRONIQUE (DME)
    // =========================================================================
    public function listPatients(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        return $this->respondWithState(['success' => true, 'data' => $db[$tk]['patients']], $start);
    }

    public function createPatient(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $cin = strtoupper(trim((string) $request->input('cin', '')));
        foreach ($state['patients'] as $existing) {
            if ($cin !== '' && strtoupper((string) $existing['cin']) === $cin) {
                return $this->respondWithState([
                    'success' => false,
                    'message' => "Le numéro CIN {$cin} existe déjà dans le dossier {$state['tenant']['short']}.",
                ], $start, 422);
            }
        }

        $newPatient = [
            'id' => (int) round(microtime(true) * 1000),
            'code' => 'PT-' . random_int(1000, 9999),
            'first' => trim((string) $request->input('first', 'Amine')),
            'last' => trim((string) $request->input('last', 'Bennani')),
            'cin' => $cin ?: ('BK-' . random_int(100000, 999999)),
            'age' => max(1, (int) $request->input('age', 35)),
            'gender' => trim((string) $request->input('gender', 'M')),
            'blood' => trim((string) $request->input('blood', 'O+')),
            'phone' => trim((string) $request->input('phone', '+212 661-000000')),
            'coverage' => trim((string) $request->input('coverage', 'CNSS AMO (80%)')),
            'department' => trim((string) $request->input('department', 'Cardiologie')),
            'allergy' => trim((string) $request->input('allergy', 'Aucune connue')),
            'bp' => '120/78',
            'spo2' => '98.9%',
            'hr' => '71 bpm',
            'avatar' => 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=120',
            'notes' => [],
        ];

        array_unshift($state['patients'], $newPatient);
        $state['kpis']['patients_today']++;
        $state['weekly_patients'][6]['count'] = $state['kpis']['patients_today'];

        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => 'Accueil Patients',
            'action' => 'CREATION_DOSSIER',
            'target' => "{$newPatient['code']} ({$newPatient['cin']})",
            'status' => 'VÉRIFIÉ',
        ]);

        $this->saveDatabase($db);
        return $this->respondWithState([
            'success' => true,
            'patient' => $newPatient,
            'state' => $state,
        ], $start, 201);
    }

    public function deletePatient(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $state['patients'] = array_values(array_filter($state['patients'], static fn(array $p): bool => (int) $p['id'] !== $id));
        $state['appointments'] = array_values(array_filter($state['appointments'], static fn(array $a): bool => (int) $a['patient_id'] !== $id));

        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => 'Direction Médicale',
            'action' => 'ARCHIVAGE_PATIENT',
            'target' => "Dossier #{$id}",
            'status' => 'ARCHIVÉ',
        ]);

        $this->saveDatabase($db);
        return $this->respondWithState(['success' => true, 'state' => $state], $start);
    }

    public function savePatientDme(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        foreach ($state['patients'] as &$pat) {
            if ((int) $pat['id'] === $id) {
                if ($request->filled('bp')) {
                    $pat['bp'] = trim((string) $request->input('bp'));
                }
                if ($request->filled('spo2')) {
                    $pat['spo2'] = trim((string) $request->input('spo2'));
                }
                if ($request->filled('hr')) {
                    $pat['hr'] = trim((string) $request->input('hr'));
                }
                if ($request->has('allergy')) {
                    $pat['allergy'] = trim((string) $request->input('allergy')) ?: 'Aucune connue';
                }
                $noteText = trim((string) $request->input('note', ''));
                if ($noteText !== '') {
                    if (!isset($pat['notes']) || !is_array($pat['notes'])) {
                        $pat['notes'] = [];
                    }
                    array_unshift($pat['notes'], [
                        'date' => date('d-m-Y H:i'),
                        'doctor' => trim((string) $request->input('doctor', 'Dr. Jackson')),
                        'icd' => trim((string) $request->input('icd', 'Suivi clinique CIM-11')),
                        'text' => $noteText,
                    ]);
                }
                array_unshift($state['audit_logs'], [
                    'time' => date('H:i:s'),
                    'actor' => 'Dr. Jackson',
                    'action' => 'SIGNATURE_DME',
                    'target' => $pat['code'],
                    'status' => 'CHIFFRÉ AES-256',
                ]);
                break;
            }
        }
        unset($pat);

        $this->saveDatabase($db);
        return $this->respondWithState(['success' => true, 'state' => $state], $start);
    }

    // =========================================================================
    // LABORATOIRE & BIOLOGIE
    // =========================================================================
    public function listLabs(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        return $this->respondWithState(['success' => true, 'data' => $db[$tk]['labs']], $start);
    }

    public function createLab(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $priority = trim((string) $request->input('priority', 'Routine'));
        $newLab = [
            'id' => (int) round(microtime(true) * 1000),
            'code' => 'LAB-' . random_int(8830, 9999),
            'patient_id' => (int) $request->input('patient_id', 1),
            'patient_name' => trim((string) $request->input('patient_name', 'Salma Bennani')),
            'test_name' => trim((string) $request->input('test_name', 'Bilan Lipidique & Troponine')),
            'category' => trim((string) $request->input('category', 'Biochimie')),
            'value' => $priority === 'URGENT' ? 'Priorité Critique' : 'En cours d’analyse',
            'reference' => trim((string) $request->input('reference', 'Valeurs usuelles')),
            'priority' => $priority,
            'status' => $priority === 'URGENT' ? 'flagged' : 'in_analysis',
            'ordered_by' => trim((string) $request->input('ordered_by', 'Dr. Jackson')),
            'timestamp' => date('H:i'),
        ];

        array_unshift($state['labs'], $newLab);
        $state['kpis']['tests_conducted']++;

        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => $newLab['ordered_by'],
            'action' => 'DEMANDE_ANALYSE',
            'target' => "{$newLab['code']} ({$newLab['test_name']})",
            'status' => strtoupper($priority),
        ]);

        $this->saveDatabase($db);
        return $this->respondWithState(['success' => true, 'lab' => $newLab, 'state' => $state], $start, 201);
    }

    public function validateLab(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $measured = trim((string) $request->input('value', ''));
        foreach ($state['labs'] as &$lab) {
            if ((int) $lab['id'] === $id) {
                $lab['status'] = 'validated';
                if ($measured !== '' && $measured !== 'En cours d’analyse' && $measured !== 'Priorité Critique') {
                    $lab['value'] = $measured;
                } elseif (in_array($lab['value'], ['En cours d’analyse', 'Priorité Critique'], true)) {
                    $lab['value'] = '4.3 mEq/L (Normal)';
                }
                array_unshift($state['audit_logs'], [
                    'time' => date('H:i:s'),
                    'actor' => 'Biologiste Chef',
                    'action' => 'VALIDATION_LABO',
                    'target' => $lab['code'],
                    'status' => 'SIGNÉ',
                ]);
                break;
            }
        }
        unset($lab);

        $this->saveDatabase($db);
        return $this->respondWithState(['success' => true, 'state' => $state], $start);
    }

    public function deleteLab(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $state['labs'] = array_values(array_filter($state['labs'], static fn(array $l): bool => (int) $l['id'] !== $id));
        $this->saveDatabase($db);

        return $this->respondWithState(['success' => true, 'state' => $state], $start);
    }

    // =========================================================================
    // FACTURATION, ENCAISSEMENT MAD & DOSSIERS CNSS / AMO
    // =========================================================================
    public function listInvoices(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        return $this->respondWithState(['success' => true, 'data' => $db[$tk]['invoices']], $start);
    }

    public function createInvoice(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $total = max(50, (float) $request->input('total', 650));
        $paid = min($total, max(0, (float) $request->input('paid', 0)));
        $patId = (int) $request->input('patient_id', $state['patients'][0]['id'] ?? 1);

        $patient = $state['patients'][0];
        foreach ($state['patients'] as $p) {
            if ((int) $p['id'] === $patId) {
                $patient = $p;
                break;
            }
        }

        $cov = (string) ($patient['coverage'] ?? 'CNSS AMO (80%)');
        $rate = str_contains($cov, '90%') ? 0.90 : (str_contains($cov, '85%') ? 0.85 : 0.80);
        $org = str_contains($cov, 'CNOPS') ? 'CNOPS' : (str_contains($cov, 'AXA') ? 'AXA Assurance' : 'CNSS AMO');

        $inv = [
            'id' => (int) round(microtime(true) * 1000),
            'num' => 'FAC-2026-' . (3015 + count($state['invoices'])),
            'patient_id' => $patId,
            'patient' => trim($patient['first'] . ' ' . $patient['last']),
            'service' => trim((string) $request->input('service', 'Consultation Spécialisée')),
            'total' => $total,
            'paid' => $paid,
            'method' => trim((string) $request->input('method', $paid > 0 ? 'TPE CMI' : 'À encaisser')),
            'cnss_org' => $org,
            'cnss_est' => round($total * $rate, 2),
            'cnss_status' => 'À télétransmettre',
            'date' => date('d-m-Y'),
        ];

        array_unshift($state['invoices'], $inv);
        $state['kpis']['revenue_today'] += $paid;

        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => 'Caisse Centrale',
            'action' => 'EMISSION_FACTURE',
            'target' => "{$inv['num']} ({$total} MAD)",
            'status' => 'COMPTABILISÉ',
        ]);

        $this->saveDatabase($db);
        return $this->respondWithState(['success' => true, 'invoice' => $inv, 'state' => $state], $start, 201);
    }

    public function recordInvoicePayment(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $amount = max(0, (float) $request->input('amount', 0));
        $method = trim((string) $request->input('method', 'TPE CMI'));

        foreach ($state['invoices'] as &$inv) {
            if ((int) $inv['id'] === $id) {
                $remaining = max(0, (float) $inv['total'] - (float) $inv['paid']);
                $applied = min($remaining, $amount > 0 ? $amount : $remaining);
                $inv['paid'] = round((float) $inv['paid'] + $applied, 2);
                $inv['method'] = $method;
                $state['kpis']['revenue_today'] += $applied;

                array_unshift($state['audit_logs'], [
                    'time' => date('H:i:s'),
                    'actor' => 'Caisse Centrale',
                    'action' => 'ENCAISSEMENT_PATIENT',
                    'target' => "{$inv['num']} (+{$applied} MAD • {$method})",
                    'status' => 'ENCAISSÉ',
                ]);
                break;
            }
        }
        unset($inv);

        $this->saveDatabase($db);
        return $this->respondWithState(['success' => true, 'state' => $state], $start);
    }

    public function advanceCnssClaim(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $nextMap = [
            'Cachet manquant' => 'À télétransmettre',
            'À télétransmettre' => 'Transmis CNSS',
            'Transmis CNSS' => 'Remboursé',
            'Remboursé' => 'Remboursé',
        ];

        foreach ($state['invoices'] as &$inv) {
            if ((int) $inv['id'] === $id) {
                $curr = (string) ($inv['cnss_status'] ?? 'À télétransmettre');
                $inv['cnss_status'] = $nextMap[$curr] ?? 'Transmis CNSS';

                array_unshift($state['audit_logs'], [
                    'time' => date('H:i:s'),
                    'actor' => 'Bureau CNSS/AMO',
                    'action' => 'SUIVI_DOSSIER_AMO',
                    'target' => "{$inv['num']} -> {$inv['cnss_status']}",
                    'status' => 'TÉLÉTRANSMIS',
                ]);
                break;
            }
        }
        unset($inv);

        $this->saveDatabase($db);
        return $this->respondWithState(['success' => true, 'state' => $state], $start);
    }

    // =========================================================================
    // MÉDECINS & PRATICIENS CRUD (GET, POST, PUT/PATCH, DELETE)
    // =========================================================================
    public function listDoctors(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        return $this->respondWithState([
            'success' => true,
            'count' => count($db[$tk]['doctors'] ?? []),
            'data' => $db[$tk]['doctors'] ?? [],
        ], $start);
    }

    public function createDoctor(Request $request): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $rawName = trim((string) $request->input('name', ''));
        if ($rawName === '') {
            return $this->respondWithState(['success' => false, 'message' => 'Le nom du médecin est obligatoire.'], $start, 422);
        }
        if (!preg_match('/^(Dr\.|Pr\.|Docteur|Professeur)\s/i', $rawName)) {
            $rawName = 'Dr. ' . $rawName;
        }

        $inpe = strtoupper(trim((string) $request->input('inpe', '')));
        foreach ($state['doctors'] as $existing) {
            if (strcasecmp((string) $existing['name'], $rawName) === 0) {
                return $this->respondWithState([
                    'success' => false,
                    'message' => "{$rawName} figure déjà dans l'équipe médicale de {$state['tenant']['short']}.",
                ], $start, 422);
            }
            if ($inpe !== '' && strcasecmp((string) ($existing['inpe'] ?? ''), $inpe) === 0) {
                return $this->respondWithState([
                    'success' => false,
                    'message' => "Le code INPE {$inpe} est déjà attribué à {$existing['name']}.",
                ], $start, 422);
            }
        }

        $defaultAvatars = [
            'https://images.unsplash.com/photo-1612349317150-e413f6a5b16d?w=120',
            'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120',
            'https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=120',
            'https://images.unsplash.com/photo-1651008376811-b90baee60c1f?w=120',
        ];
        $avatar = trim((string) $request->input('avatar', ''));
        if ($avatar === '') {
            $avatar = $defaultAvatars[count($state['doctors']) % count($defaultAvatars)];
        }

        $newDoc = [
            'id' => (int) round(microtime(true) * 1000),
            'name' => $rawName,
            'specialty' => trim((string) $request->input('specialty', 'Cardiologue')),
            'room' => trim((string) $request->input('room', 'Salle 108')),
            'fee_mad' => max(50, (float) $request->input('fee_mad', 600)),
            'inpe' => $inpe ?: ('INPE-0' . random_int(100000, 999999)),
            'phone' => trim((string) $request->input('phone', '+212 661-000000')),
            'avatar' => $avatar,
        ];

        $state['doctors'][] = $newDoc;
        $state['kpis']['specialists_count'] = (int) ($state['kpis']['specialists_count'] ?? 120) + 1;

        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => 'Direction Médicale',
            'action' => 'AJOUT_MEDECIN',
            'target' => "{$newDoc['name']} ({$newDoc['specialty']} • {$newDoc['room']})",
            'status' => 'ACTIF',
        ]);

        $this->saveDatabase($db);
        return $this->respondWithState([
            'success' => true,
            'message' => "{$newDoc['name']} ajouté à l'équipe médicale.",
            'doctor' => $newDoc,
            'state' => $state,
        ], $start, 201);
    }

    public function updateDoctor(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        $foundIdx = null;
        foreach ($state['doctors'] as $idx => $doc) {
            if ((int) $doc['id'] === $id) {
                $foundIdx = $idx;
                break;
            }
        }

        if ($foundIdx === null) {
            return $this->respondWithState(['success' => false, 'message' => 'Médecin introuvable.'], $start, 404);
        }

        $oldDoc = $state['doctors'][$foundIdx];
        $rawName = trim((string) $request->input('name', $oldDoc['name']));
        if ($rawName !== '' && !preg_match('/^(Dr\.|Pr\.|Docteur|Professeur)\s/i', $rawName)) {
            $rawName = 'Dr. ' . $rawName;
        }

        $updatedDoc = array_merge($oldDoc, [
            'name' => $rawName ?: $oldDoc['name'],
            'specialty' => trim((string) $request->input('specialty', $oldDoc['specialty'])),
            'room' => trim((string) $request->input('room', $oldDoc['room'])),
            'fee_mad' => max(50, (float) $request->input('fee_mad', $oldDoc['fee_mad'] ?? 600)),
            'inpe' => strtoupper(trim((string) $request->input('inpe', $oldDoc['inpe'] ?? ''))),
            'phone' => trim((string) $request->input('phone', $oldDoc['phone'] ?? '')),
            'avatar' => trim((string) $request->input('avatar', $oldDoc['avatar'] ?? '')) ?: $oldDoc['avatar'],
        ]);

        $state['doctors'][$foundIdx] = $updatedDoc;

        // Synchroniser les rendez-vous existants de ce praticien
        foreach ($state['appointments'] as &$apt) {
            if (strcasecmp((string) ($apt['doctor'] ?? ''), (string) $oldDoc['name']) === 0) {
                $apt['doctor'] = $updatedDoc['name'];
                $apt['specialty'] = $updatedDoc['specialty'];
                $apt['room'] = $updatedDoc['room'];
            }
        }
        unset($apt);

        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => 'Direction Médicale',
            'action' => 'MODIF_MEDECIN',
            'target' => "{$updatedDoc['name']} ({$updatedDoc['room']})",
            'status' => 'MIS À JOUR',
        ]);

        $this->saveDatabase($db);
        return $this->respondWithState([
            'success' => true,
            'message' => "Fiche de {$updatedDoc['name']} mise à jour.",
            'doctor' => $updatedDoc,
            'state' => $state,
        ], $start);
    }

    public function deleteDoctor(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $db = $this->loadDatabase();
        $tk = $this->resolveTenantKey($request);
        $state = &$db[$tk];

        if (count($state['doctors']) <= 1) {
            return $this->respondWithState([
                'success' => false,
                'message' => 'Au moins un praticien actif est requis dans la clinique.',
            ], $start, 422);
        }

        $removedName = "Médecin #{$id}";
        foreach ($state['doctors'] as $d) {
            if ((int) $d['id'] === $id) {
                $removedName = $d['name'];
                break;
            }
        }

        $state['doctors'] = array_values(array_filter(
            $state['doctors'],
            static fn(array $d): bool => (int) $d['id'] !== $id
        ));
        $state['kpis']['specialists_count'] = max(1, (int) ($state['kpis']['specialists_count'] ?? 120) - 1);

        array_unshift($state['audit_logs'], [
            'time' => date('H:i:s'),
            'actor' => 'Direction Médicale',
            'action' => 'SUPPRESSION_MEDECIN',
            'target' => $removedName,
            'status' => 'RETIRÉ',
        ]);

        $this->saveDatabase($db);
        return $this->respondWithState([
            'success' => true,
            'message' => "{$removedName} retiré de la liste active.",
            'state' => $state,
        ], $start);
    }
}
