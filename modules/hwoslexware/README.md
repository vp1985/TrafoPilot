# TrafoPilot – Lexware Office Spiegel

Externes Dolibarr-24-Modul `hwoslexware`, Version 0.1.0, Modul-ID 700200. Abhängigkeit: `modHwosCore`. Der Dolibarr-Core bleibt unverändert.

## Sicherheit und Betrieb

Ausschließlich `LEXWARE_TEST_API_KEY_READ_ONLY`; kein Vollzugriffs-Fallback. Der Client bietet ausschließlich erlaubte GET-Pfade auf `https://api.lexware.io`. Redirects und `/document`-Rendering sind gesperrt. UUID und Name der Testorganisation werden vor jedem Batch geprüft. Ein organisationsweiter Datenbank-Lock und mindestens 600 ms Abstand begrenzen parallele Abrufe. Wiederholbare Fehler werden maximal fünfmal mit Backoff versucht; Response-Fehlertexte und Header werden nicht protokolliert.

Die lokale Secret-Datei `/home/vadmin/.config/trafopilot/lexware-dev.env` wird ausschließlich vom DEV-Launcher gelesen, mit Eigentümer-/Rechteprüfung. Nur die benannte Read-only-Zeile wird ausgewertet; die Datei wird nicht als Shellcode geladen. Der Wert wird per stdin übergeben. Webserver und Cron benötigen eine gesonderte Injektion derselben Umgebungsvariable. Das Modul zeigt ausschließlich deren Verfügbarkeit an.

## Daten und Oberfläche

Geschützte Tabellen speichern Original-JSON, Prüfsummen, Versionen, Zeitpunkte, Archiv-/Fehlend-Markierungen, Läufe, Aufgaben, Zuordnungen, Konflikte, Dokumentbeziehungen und Originaldateien. Alle UI-Zugriffe prüfen das Leserecht; schreibende lokale Aktionen zusätzlich ihr eigenes Recht. Webaktionen verwenden POST und Dolibarr-CSRF-Prüfung.

Die Modulübersicht bietet Verbindungstest, Vorschau, Voll-/Änderungsabruf, Batch-Fortsetzung, Historie und Fehlerwiederholung. Ressourcen können einzeln neu geladen werden. Die Lexware-Tabs zeigen IDs, Originalnummern, Versionen, Status, Abgleich, Dateien, Dokumentketten und Zahlungsinformationen. Unsichere Kontakte werden zur manuellen Zuordnung angezeigt. Eine bestätigte Zuordnung überschreibt keine vorhandenen Kontaktfelder; spätere Remote-Änderungen eines solchen verknüpften Kontakts bleiben Konflikte.

Native Kontakte, Produkte/Dienstleistungen, Angebote, Aufträge, Rechnungen, Gutschriften und eindeutig auftragsbezogene Lieferungen werden über native Dolibarr-Klassen angelegt. UUID-Extrafields werden beim Duplizieren geleert. Zeilenänderungen erhalten native IDs; Strukturänderungen und Versandrevisionen verlangen eine Prüfung. Lokale Änderungen einschließlich Extrafields und Ansprechpartnern blockieren automatische Überschreibungen. Erweiterte Steuerszenarien ohne geprüftes Mapping bleiben im Spiegel. Belege ohne Kontaktreferenz erzeugen keine erfundenen Kunden.

Zahlungen bleiben ausschließlich Spiegelinformationen; keine Bank-, Kassen-, Buchhaltungs- oder Lagerbewegungen. Historische Statuswerte werden ohne operative Validierung gesetzt. Dolibarr 24 hat einen Fehler in `Expedition::create(...,1)`; eine externe Import-Unterklasse unterdrückt den Triggerdispatcher und umgeht diesen Fehler ohne Core-Patch.

## DEV-Prüfung

Profilantworten werden unverändert gespeichert. Einzelne Referenzobjekte werden aus dem Original-JSON ausgeschnitten; leere Objekte und große numerische Werte bleiben erhalten. Contract- und DEV-Tests prüfen diese Fälle ausdrücklich.

Ein externer Modultrigger entfernt übernommene Lexware-UUIDs und passende `ref_ext`-Kennungen aus neuen lokalen Angeboten, Aufträgen und Rechnungen. Native Erstellrechte gelten weiterhin. Die Importpipeline unterdrückt Trigger und behält die Originalidentität. DEV-Tests prüfen native Duplikate aller drei Dokumenttypen sowie Auftrag aus Angebot und Rechnung aus Auftrag; Spiegel, Mapping und Original bleiben unverändert.

Nur gegen `dolibarr-dev-dolibarr-1` mit geprüften Compose-Labels:

```bash
scripts/test-dev.sh
python3 scripts/lexware-dev.py --mode smoke
python3 scripts/lexware-dev.py --mode dry
python3 scripts/lexware-dev.py --mode full
python3 scripts/lexware-dev.py --mode incremental
python3 scripts/lexware-ui-dev.py
```

Der Quellcode muss in DEV unter `/var/www/html/custom/hwoslexware` verfügbar sein. Der CLI-Launcher installiert oder deployt keinen Code. Seine Modulaktivierung wird nach dem Lauf auf den vorherigen Zustand zurückgesetzt. Unterbrochene Läufe werden mit `--run ID` fortgesetzt. Vollimporte erfordern einen erfolgreichen persistenten Dry Run. Der Launcher prüft Geschäftsdatentabellen beim Dry Run sowie Lager-, Zahlungs-, Bank- und Buchhaltungstabellen bei Imports per Datenbankprüfsumme.

Integrationstest-Fixtures verwenden die getrennte Entität 970200 und künstliche Daten. Migrationstests prüfen sämtliche Tabellenspalten inklusive Typ, Nullbarkeit, Default und Extra sowie alle Indizes. Aktivierung wird zweimal ausgeführt; Deaktivierung bewahrt Spiegel und Audit. Tests stellen den vorherigen Aktivierungszustand wieder her. Der Cronjob ist bei Registrierung deaktiviert; ein freigegebener Cron-Benutzer braucht das Abrufrecht. Er verarbeitet begrenzte Batches, wählt wöchentlich einen vollständigen Kontrollscan und sonst einen Änderungsabruf.

TEST und Produktion dürfen ausschließlich ein versioniertes, unveränderliches Release-Artefakt erhalten. Kein solches Deployment gehört zu diesem Auftrag.

Der HTTP-Prüfer verwendet einen temporären Benutzer mit ausschließlich den Modulrechten und löscht ihn anschließend. Das vorhandene DEV-Elternverzeichnis `/var/www/html/custom` ist `root:root 750` und damit für den Webserver nicht traversierbar. Der Prüfer ergänzt nur für den Test das Traverse-Recht und stellt die ursprünglichen Rechte im `finally`-Block wieder her. Für dauerhaft erreichbare DEV-Oberflächen muss die Infrastruktur dieses Verzeichnis passend freigeben; das gehört zur gesonderten DEV-Einrichtung. Bestehende Benutzerpasswörter bleiben unverändert.

## API-Grenzen und spätere Phasen

Siehe [Endpoint-Matrix](../../docs/lexware-endpoints.md). Mahnungen sind nicht vollständig enumerierbar. Entwürfe besitzen häufig keine PDF-Datei; 404/406/409 bei Dateirepräsentationen werden sichtbar übersprungen. Nicht lesbare UI-Bereiche werden nicht gescrapt. Native Zahlungsprojektion, Schreibzugriff, Webhook-Registrierung, erweiterte Steuermappings und feinere Feldkonfliktauflösung benötigen separate Planung und Freigabe.
