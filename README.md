# 🏥 Cliniqo Medical — Plateforme SaaS de Gestion des Centres Médicaux

[![Release](https://img.shields.io/badge/Release-v4.8%20Enterprise-005131?style=for-the-badge)](https://github.com/chaymaakhab/cliniqo)
[![Architecture](https://img.shields.io/badge/Architecture-Frontend%20•%20Backend%20•%20Mobile-10b981?style=for-the-badge)](https://github.com/chaymaakhab/cliniqo)
[![Conformité](https://img.shields.io/badge/Conformit%C3%A9-CNDP%20(Loi%2009--08)%20•%20HIPAA%20•%20RGPD-176b45?style=for-the-badge)](https://github.com/chaymaakhab/cliniqo)

**Cliniqo Medical** est une solution SaaS unifiée pour cliniques, polycliniques, cabinets de groupe et cabinets médicaux libéraux. Conçue pour répondre aux réalités du secteur de la santé au Maroc et à l'international (CNSS, AMO, mutuelles, tarification en Dirhams marocains MAD, conformité CNDP Loi 09-08).

---

## 📁 Organisation du Workspace Monorepo

```text
cliniqo/
│
├── frontend/                          # Application Web & Interface Clinique
│   ├── index.html                     # Landing Page Publique (Simulation 3D ADN, Télémétrie)
│   ├── dashboard.html                 # Suite Clinique Interactive (Espace Médecin & Direction)
│   └── assets/                        # Ressources graphiques & logos vectoriels
│
├── backend/                           # API REST Laravel 11 / PHP 8.3 & Base de Données
│   ├── database/
│   │   ├── schema.sql                 # Schéma DDL MySQL complet (30+ tables, clés, index)
│   │   └── seed.sql                   # Jeu de données réaliste (Grand Central Polyclinic, Praticiens)
│   ├── app/
│   │   ├── Http/Controllers/          # Dashboard, Appointments, Patients, Invoicing, CNSS
│   │   ├── Http/Middleware/           # Isolation multi-tenant stricte (tenant_id)
│   │   └── Services/                  # Moteur anti-conflits & calculs paiements partiels (MAD)
│   ├── routes/api.php                 # Points d'accès REST API v1
│   ├── composer.json                  # Dépendances Laravel 11 & Sanctum
│   └── .env.example                   # Configuration d'environnement
│
├── mobile/                            # Application Mobile Flutter (iOS & Android)
│   ├── pubspec.yaml                   # Dépendances Flutter (Material 3, Google Fonts, HTTP)
│   └── lib/
│       ├── main.dart                  # Point d'entrée de l'application mobile
│       ├── config/theme.dart          # Charte graphique médicale officielle Cliniqo
│       ├── models/appointment.dart    # Modèles de données (RDV, KPI, Triage)
│       ├── services/api_service.dart  # Client HTTP connecté à l'API Laravel
│       └── screens/dashboard_screen.dart # Écran d'accueil mobile des praticiens & alertes
│
├── docker-compose.yml                 # Orchestration MySQL 8.0, Backend & Frontend
├── .gitignore                         # Règles d'exclusion Git standard
└── README.md                          # Documentation globale du projet
```

---

## 🗄️ 1. Base de Données (MySQL 8.0+)

Le schéma relationnel [`backend/database/schema.sql`](file:///c:/Users/pc/Desktop/cliniqo/backend/database/schema.sql) implémente l'ensemble des exigences du **Cahier des charges fonctionnel et technique** :

1. **Multi-Tenancy & Centres** : `tenants`, `branches`, `subscription_plans`, `subscriptions`.
2. **Gestion des Accès & RBAC** : `users`, `roles`, `permissions`, `role_permissions`, `user_roles`.
3. **Patients & Familles** : `patients`, `families`, `family_members`, `patient_coverages`, `patient_consents`, `patient_documents`.
4. **Agenda & Ressources** : `specialties`, `practitioners`, `rooms`, `practitioner_schedules`, `appointments`, `appointment_events`.
5. **Dossier Médical (DME)** : `encounters`, `vitals`, `consultation_notes` (codification CIM-11), `prescriptions` (avec hash de vérification QR).
6. **Finances & Caisse en MAD** : `invoices`, `invoice_items`, `payments` (multi-échéances), `cash_sessions` (contrôle des écarts de caisse).
7. **Remboursements CNSS & Mutuelles** : `coverage_providers`, `reimbursement_cases`, `reimbursement_documents`.
8. **Traçabilité CNDP** : `audit_logs` immuables.

Le fichier [`backend/database/seed.sql`](file:///c:/Users/pc/Desktop/cliniqo/backend/database/seed.sql) initialise immédiatement :
- Le centre **Grand Central Polyclinic** (Casablanca)
- Le collège médical : **Dr. Sarah Chen** (Directrice Médicale), **Dr. Anil Patel** (Cardiologue), **Dr. Michael Chen** (Chirurgie Orthopédique), **Dr. Amina Ndiaye** (Pédiatrie).
- La file active de patients (**Eleanor Pena**, **Wade Warren**, **Jacob Jones**, **Dianne Russell**).
- Les encaissements de 4 850 DH et les 5 dossiers CNSS en cours d'instruction.

---

## 💻 2. Frontend & Interface Utilisateur (UI / UX)

- **Landing Page Publique** ([`frontend/index.html`](file:///c:/Users/pc/Desktop/cliniqo/frontend/index.html)) :
  - Simulation 3D interactive en Three.js avec particuless bioluminescentes.
  - Commutateur de suites diagnostiques en temps réel (*Cardiologie*, *Endocrinologie*, *Post-Opératoire*).
  - Prise de rendez-vous en ligne avec validation des numéros marocains (+212).
  - Sélecteur de langue immédiat : **Français**, **العربية (RTL)** et **English**.

- **Suite Clinique Back-Office** ([`frontend/dashboard.html`](file:///c:/Users/pc/Desktop/cliniqo/frontend/dashboard.html)) :
  - **Overview / Dashboard** : 4 métriques d'exploitation (38 RDV, 29 enregistrés, 4 850 DH encaissés, 1 180 DH CNSS), flux des patients de la journée et alertes de triage prioritaire (STAT).
  - **Matrice des Plannings & Agenda** : Vue détaillée des créneaux horaires, statut des salles de soins, fiche praticien et filtre des disponibilités.
  - **Dossiers Patients** : Recherche par CIN/Nom, gestion des antécédents et couverture médicale.
  - **Facturation & Caisse** : Suivi des paiements partiels (exemple 800 DH : 500 DH payés, 300 DH restant) et clôtures journalières.

---

## 📱 3. Application Mobile Flutter (`mobile/`)

- Conçue pour Android et iOS sous **Material 3**.
- Palette officielle Cliniqo : Vert forêt impérial (`#005131`), conteneur émeraude (`#176B45`) et menthe clinique (`#10B981`).
- Synchronisation temps réel avec l'API Laravel REST via [`ApiService`](file:///c:/Users/pc/Desktop/cliniqo/mobile/lib/services/api_service.dart).
- Écran de garde pour praticiens avec carrousel des KPI et alertes labo en direct.

---

## 🚀 Démarrage Rapide

### Visualiser l'interface Web (Frontend) :
```powershell
# Serveur local intégré PHP
php -S localhost:8000 -t .

# Ou via npx
npx serve .
```
- **Landing Page** : [http://localhost:8000/index.html](http://localhost:8000/index.html)
- **Suite Clinique Espace Praticien** : [http://localhost:8000/frontend/dashboard.html](http://localhost:8000/frontend/dashboard.html)

### Démarrer la stack complète (Docker) :
```powershell
docker-compose up -d
```

### Lancer l'application Mobile (Flutter) :
```powershell
cd mobile
flutter pub get
flutter run
```

---

© 2026 Cliniqo Medical. Tous droits réservés.
