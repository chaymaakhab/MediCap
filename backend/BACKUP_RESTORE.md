# Cliniqo Medical SaaS — Procédure de Sauvegarde & Restauration Sécurisée

Ce document décrit l'architecture de sauvegarde et la procédure opérationnelle de restauration des données cliniques, financières et d'audit de **Cliniqo** (conformité CNDP Loi 09-08 & continuité d'activité).

---

## 1. Architecture des Sauvegardes

- **Base de données de production** : `backend/storage/app/cliniqo_saas_prod.sqlite` (mode transactionnel `WAL` activé, intégrité référentielle `FOREIGN KEYS = ON`).
- **Répertoire des archives** : `backend/storage/backups/` (exclu du contrôle de version Git via `.gitignore`).
- **Mécanisme atomique à chaud** :
  1. Exécution d'un point de contrôle complet `PRAGMA wal_checkpoint(FULL);`.
  2. Export atomique cohérent via `VACUUM INTO 'backend/storage/backups/cliniqo_backup_<YYYYMMDD_HHMMSS>_<id>.sqlite';` sans interrompre les consultations en cours.
  3. Génération d'une empreinte cryptographique **SHA-256** enregistrée dans `<archive>.sqlite.meta.json`.
  4. Journalisation immuable de l'opération dans la table `audit_logs` (`CREATE_BACKUP` / `RESTORE_BACKUP`).

---

## 2. Création d'une Sauvegarde

### A. Depuis l'Interface Administrateur
1. Connectez-vous avec un compte disposant de la permission `backups.manage` (`Administrateur Clinique` ou `Propriétaire Plateforme SaaS`).
2. Cliquez sur l'icône **Paramètres (`⚙`)** en haut à droite $\rightarrow$ onglet **Sauvegardes & Sécurité**.
3. Cliquez sur **Créer une Sauvegarde Immédiate**. L'archive horodatée et son empreinte SHA-256 apparaissent immédiatement dans la liste.

### B. Via l'API REST Sécurisée
```bash
curl -X POST http://127.0.0.1:8001/api/v1/backups/create \
  -H "Authorization: Bearer <TOKEN_ADMIN>" \
  -H "Accept: application/json"
```

---

## 3. Procédure de Restauration (Disaster Recovery)

### A. Vérification préalable d'intégrité SHA-256
Avant toute restauration manuelle ou automatisée, vérifiez que l'empreinte SHA-256 du fichier `.sqlite` correspond à celle stockée dans son fichier `.meta.json` :
```powershell
Get-FileHash backend\storage\backups\<nom_archive>.sqlite -Algorithm SHA256
Get-Content backend\storage\backups\<nom_archive>.sqlite.meta.json
```

### B. Restauration via l'Interface / API
1. Dans **Paramètres $\rightarrow$ Sauvegardes & Sécurité**, cliquez sur **Restaurer** en face du point de restauration souhaité (ou appelez `POST /api/v1/backups/restore` avec `{"filename": "<nom_archive>.sqlite"}`).
2. Le moteur vérifie automatiquement l'en-tête binaire `SQLite format 3`, purge les fichiers temporaires `-wal` / `-shm`, remplace atomiquement la base active et rouvre la connexion transactionnelle.

### C. Restauration manuelle hors-ligne (Serveur arrêté)
1. Arrêtez le processus serveur PHP/FPM ou placez l'application en maintenance (`php backend/artisan down`).
2. Sauvegardez par sécurité l'état courant endommagé :
   ```powershell
   Copy-Item backend\storage\app\cliniqo_saas_prod.sqlite backend\storage\backups\pre_recovery.bak
   Remove-Item backend\storage\app\cliniqo_saas_prod.sqlite-wal -ErrorAction SilentlyContinue
   Remove-Item backend\storage\app\cliniqo_saas_prod.sqlite-shm -ErrorAction SilentlyContinue
   ```
3. Copiez l'archive saine vers l'emplacement actif :
   ```powershell
   Copy-Item backend\storage\backups\<nom_archive>.sqlite backend\storage\app\cliniqo_saas_prod.sqlite -Force
   ```
4. Vérifiez l'intégrité SQLite :
   ```powershell
   php -r "$p = new PDO('sqlite:backend/storage/app/cliniqo_saas_prod.sqlite'); echo $p->query('PRAGMA integrity_check;')->fetchColumn();"
   ```
   *(Doit afficher `ok`.)*
5. Redémarrez le service (`php backend/artisan up`).
