# Datenbank-Migration: breaks Spalte hinzufügen

## Problem

Fehler: `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'breaks' in 'SET'`

Die `breaks` Spalte existiert nicht in der Datenbank, obwohl die Migration `Version1004Date20250104000000.php` existiert.

## Lösung

### Option 1: Migration automatisch ausführen (Empfohlen)

```bash
# Im Nextcloud-Verzeichnis
php occ upgrade
```

Dies führt alle ausstehenden Migrationen aus, einschließlich der `breaks` Spalte.

### Option 2: Migration manuell für diese App ausführen

```bash
# Im Nextcloud-Verzeichnis
php occ migrations:migrate arbeitszeitcheck
```

### Option 3: SQL manuell ausführen (Fallback)

Wenn die Migrationen nicht automatisch funktionieren, können Sie die Spalte manuell hinzufügen:

**Für MySQL/MariaDB:**
```sql
ALTER TABLE `oc_at_entries` ADD COLUMN `breaks` TEXT NULL;
```

**Für PostgreSQL:**
```sql
ALTER TABLE "oc_at_entries" ADD COLUMN "breaks" TEXT NULL;
```

**Für SQLite:**
```sql
ALTER TABLE "oc_at_entries" ADD COLUMN "breaks" TEXT NULL;
```

## Verifikation

Nach der Migration können Sie prüfen, ob die Spalte existiert:

**MySQL/MariaDB:**
```sql
DESCRIBE `oc_at_entries`;
-- oder
SHOW COLUMNS FROM `oc_at_entries` LIKE 'breaks';
```

**PostgreSQL:**
```sql
\d oc_at_entries
-- oder
SELECT column_name FROM information_schema.columns 
WHERE table_name = 'oc_at_entries' AND column_name = 'breaks';
```

**SQLite:**
```sql
PRAGMA table_info(oc_at_entries);
```

## Migration-Details

**Datei:** `lib/Migration/Version1004Date20250104000000.php`

**Was macht die Migration:**
- Fügt `breaks` Spalte (TEXT, nullable) zur `at_entries` Tabelle hinzu
- Diese Spalte speichert JSON-Array mit allen Pausen (inkl. automatischer Pausen)

**Warum wird sie benötigt:**
- Speichert mehrere Pausen pro TimeEntry
- Speichert automatische Pausen (ArbZG §4)
- Speichert Clock-Out-Perioden (wenn Entry pausiert/resumed wird)
