# Vipers Toolset - Sicherheitssystem für Autoload-Cleanup

## 🔒 Neu implementierte Sicherheitsfeatures

Das Vipers Toolset wurde um ein umfassendes Sicherheitssystem erweitert, um Datenverlust beim Löschen von WordPress-Optionen zu verhindern.

---

## 1. **Whitelist/Protected Patterns** ⛔

### Was ist geschützt?
Es gibt eine **harte Whitelist** von Optionen, die **NIEMALS gelöscht werden können**:

```
WordPress Core:
✓ wp_* (WordPress System)
✓ siteurl, home (URLs)
✓ admin_* (Admin Einstellungen)
✓ blog_*, users_can_* (Blog Einstellungen)
✓ rewrite_* (Permalink-Struktur)
✓ _transient_* (WordPress Caching)
✓ _site_transient_* (Site-weite Transients)
✓ cron (WordPress Scheduler)
✓ db_version (Datenbank-Version)
✓ theme_* (Theme-Optionen)

Critical Plugins:
✓ vipers_* (Vipers selbst)
✓ woo_* (WooCommerce)
✓ stripe_*, shopify_* (Payment Systems)
✓ gravity_*, elementor*, acf_* (Page Builders)
```

### Verhalten
- Geschützte Optionen zeigen ein **🔒 Schloss-Symbol** in der Tabelle
- Der Löschen-Button wird durch "🔒 Geschützt" ersetzt
- Versuche, geschützte Optionen zu löschen, werden abgeblockt

---

## 2. **Automatisches Backup-System** 💾

### Wie funktioniert es?

**VOR jeder Löschung:**
1. Die aktuelle Option wird automatisch in ein Backup gespeichert
2. Das Backup enthält:
   - Option Name
   - Option Value (vollständige Daten)
   - Zeitstempel der Löschung
   - Benutzer ID des Deleters

3. Ein **Backup-Index** verwaltet alle Backups zentral

### Speicherort
- Backups werden als WordPress Options gespeichert
- Meta-Information: `vipers_deleted_backups`
- Einzelne Backups: `backup_{MD5_HASH}`

### Speicherlimit
- Die Datenbank wächst kontrolliert
- Alte Backups können manuell gelöscht werden
- Empfehlung: Regelmäßig aufräumen

---

## 3. **Rollback / Wiederherstellung** 🔄

### Im Recovery-Tab verfügbar

**Recovery & Audit Trail** zeigt:
- ✓ Alle gelöschten Optionen mit Backups
- ✓ Gelöschte Benutzer & Zeitpunkte
- ✓ Größe der Optionen
- ✓ **Restore-Button** zum Wiederherstellen

### Wie man wiederherstellt
1. Navigieren Sie zum **🔄 Recovery Tab**
2. Finden Sie die gelöschte Option in der Liste
3. Klicken Sie **"Wiederherstellen"**
4. Die Option wird sofort wiederhergestellt
5. Das Backup wird aus dem Index entfernt

### Zeitraum
- **Unbegrenzt!** Gelöschte Optionen können jederzeit wiederhergestellt werden
- Solange das Backup existiert, können Sie es zurückholen

---

## 4. **Audit Log / Änderungshistorie** 📋

### Was wird protokolliert?

Jede Aktion wird dokumentiert:

| Zeit | Aktion | Option | Benutzer | Details |
|------|--------|--------|----------|---------|
| 23.12.2025 14:30:15 | ❌ Gelöscht | `my_old_option` | admin | - |
| 23.12.2025 14:31:02 | ✓ Wiederhergestellt | `my_old_option` | admin | Backup ID: abc123... |

### Audit Log Speicherlimit
- Letzte **500 Einträge** werden behalten
- Ältere Einträge werden automatisch gelöscht
- Speicherlocation: `vipers_deletion_logs` Option

---

## 5. **Bestätigungsdialoge** ⚠️

### Doppelte Bestätigung beim Löschen

Bevor eine Option gelöscht wird:

```
Bestätigungsdialog erscheint:
"❌ Wirklich löschen?

Option: option_name

Hinweis: Die Option wird automatisch gesichert 
und kann im Recovery-Tab wiederhergestellt werden."
```

### Verhindert Unfälle
- Schützt vor Doppel-Klicks
- Zeigt Optionsnamen zur Bestätigung
- Erinnert an Backup & Recovery

---

## 📊 Sicherheits-Übersicht

### Wenn Sie eine Option löschen:

```
1. Whitelist-Check ✓
   ↓ Ist die Option geschützt? 
   ↓ NEIN → weitermachen
   ↓ JA → Fehler "Geschützt" anzeigen

2. Bestätigungsdialog ✓
   ↓ Benutzer muss bestätigen

3. Backup erstellen ✓
   ↓ Option Value speichern
   ↓ Backup-ID generieren
   ↓ In Index registrieren

4. Löschen ✓
   ↓ delete_option() aufrufen

5. Audit Log ✓
   ↓ Aktion dokumentieren
   ↓ Benutzer & Zeit speichern

6. Bestätigung zeigen ✓
   ↓ "✓ Option erfolgreich gelöscht (und gesichert)"
```

---

## 🛠️ Neue Admin-Tabs

### **🔄 Recovery Tab**

**Verfügbare Backups**
- Liste aller gelöschten Optionen
- Gelöschungsdatum & Benutzer
- Datengröße
- Restore-Button

**Audit Trail**
- Komplette Änderungshistorie
- Löschen & Wiederherstellungen
- Zeitstempel & Benutzer-Info

---

## 💡 Best Practices

### ✅ SICHER:
- Einzelne Options gezielt löschen
- Recovery Tab vorher prüfen
- Audit Log regelmäßig kontrollieren
- Regelmäßige Datenbank-Backups machen

### ❌ NICHT SICHER:
- Manuell SQL ausführen (umgeht Sicherheit)
- Mehrere Options auf einmal löschen
- Ohne Backup des Systems
- Im Live-System ohne Test-Umgebung

---

## 📝 Für Entwickler

### Neue Methoden

```php
// Prüfe ob Option löschbar ist
$this->is_option_deletable( $option_name );

// Erstelle Backup VOR dem Löschen
$backup_id = $this->backup_option( $option_name );

// Hole alle Backups
$backups = $this->get_deleted_backups();

// Stelle Option aus Backup wieder her
$this->restore_option_from_backup( $backup_id );

// Hole Audit Logs
$logs = $this->get_audit_logs( $limit = 50 );
```

### Whitelist erweitern

In der Methode `get_protected_patterns()`:

```php
private function get_protected_patterns() {
    return array(
        // Neue Patterns hinzufügen:
        '/^my_critical_plugin_/',
        '/^special_system_/',
    );
}
```

---

## 🚨 Support & Fehlerbehebung

### Problem: "Option ist geschützt"
- Die Option gehört zu WordPress Core oder kritischem Plugin
- **Lösung**: Manuelle SQL-Query (mit Vorsicht!) oder Admin kontaktieren

### Problem: Backup nicht gefunden
- Backup wurde zu alt und wurde aufgeräumt
- **Lösung**: Aus Datenbank-Backup wiederherstellen

### Problem: Recovery Tab funktioniert nicht
- Überprüfen Sie Benutzer-Rechte (manage_options nötig)
- Prüfen Sie Error Logs

---

**Version**: 1.0.0  
**Letztes Update**: Dezember 2025  
**Autor**: Vipers Team
