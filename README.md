# 🏥 Cliniqo Medical — Plateforme SaaS de Gestion des Centres Médicaux

[![Version](https://img.shields.io/badge/Release-v4.8%20Enterprise-005131?style=for-the-badge)](https://github.com/chaymaakhab/cliniqo)
[![Tech Stack](https://img.shields.io/badge/Stack-Laravel%20•%20MySQL%20•%20Flutter-1d70f5?style=for-the-badge)](https://github.com/chaymaakhab/cliniqo)
[![Compliance](https://img.shields.io/badge/Conformit%C3%A9-CNDP%20(Loi%2009--08)%20•%20HIPAA%20•%20RGPD-176b45?style=for-the-badge)](https://github.com/chaymaakhab/cliniqo)

**Cliniqo Medical** est une solution SaaS unifiée conçue pour les cabinets médicaux, cabinets de groupe et centres médicaux multidisciplinaires (polycliniques), particulièrement optimisée pour le contexte marocain et international (CNSS, AMO, mutuelles, facturation en Dirham marocain MAD).

---

## 🚀 Aperçu de la Landing Page

La page d'accueil officielle (`index.html`) intègre :
- **Identité Visuelle Officielle Cliniqo** : Logo SVG haute fidélité (Croix médicale bleue, gélule violette et glyphe « C » central).
- **Simulation 3D Interactive (Three.js)** : Double hélice d'ADN en temps réel avec réactivité au curseur et particules bioluminescentes.
- **Hub Télémétrique & Diagnostic Vivant** : Sélecteur dynamique de spécialités (*Cardiologie*, *Endocrinologie*, *Post-Opératoire*) avec tracé ECG SVG réactif, constantes vitales et équipe soignante.
- **Système de Réservation Interactive** : Sélecteur de créneaux et modale de demande de démo adaptée aux structures médicales (Cabinet solo, Cabinet de groupe, Polyclinique) avec validation des numéros marocains (+212).
- **Support Multilingue & RTL** : Sélecteur instantané Français, Arabe (RTL complet) et Anglais.
- **Conformité & Sécurité** : Mise en avant de la conformité **CNDP (Loi 09-08)**, **HIPAA BAA**, **RGPD Niveau 3** et **SOC 2 Type II**.

---

## 📂 Architecture Prévue (Cahier des Charges)

| Composant | Technologie | Description |
| :--- | :--- | :--- |
| **Backend API** | PHP 8.3 / Laravel 11 | Architecture modulaire multi-tenant avec isolation stricte des données (`tenant_id`). |
| **Base de Données** | MySQL 8.0+ | Schéma relationnel complet, transactions ACID pour les réservations concurrentes. |
| **Web Back-Office** | Blade + Tailwind CSS / Livewire | Interface responsive pour administrateurs, praticiens, secrétaires et comptables. |
| **Application Mobile** | Flutter (iOS & Android) | Application mobile connectée à l'API Laravel Sanctum pour praticiens et patients. |
| **Télétransmission** | Module CNSS / AMO / Assurances | Suivi des dossiers de remboursement, feuilles de soins et pièces justificatives. |

---

## 🛠️ Phases de Développement (Roadmap)

- [x] **Phase 0** : Landing page interactive, maquettes & spécifications fonctionnelles.
- [ ] **Phase 1** : Fondations Laravel, Multi-tenant, Rôles & Permissions granulaires (RBAC).
- [ ] **Phase 2** : Gestion des Patients, Dossiers familiaux, Spécialités & Praticiens.
- [ ] **Phase 3** : Agenda intelligent, Prise de RDV publique & Gestion des conflits de créneaux.
- [ ] **Phase 4** : Dossier Médical Électronique (DME), Notes de consultation & Ordonnances chiffrées.
- [ ] **Phase 5** : Facturation en MAD, Paiements échelonnés partiels, Sessions de caisse & Suivi CNSS.
- [ ] **Phase 6** : Application mobile Flutter pour praticiens et portail patient.

---

## 💻 Visualiser la Landing Page en Local

Ouvrez simplement le fichier `index.html` dans n'importe quel navigateur moderne, ou lancez un serveur de développement local :

```bash
# Avec PHP :
php -S localhost:8000

# Ou avec Node.js (npx serve) :
npx serve .
```

Puis rendez-vous sur `http://localhost:8000`.

---

© 2026 Cliniqo Medical. Tous droits réservés.
