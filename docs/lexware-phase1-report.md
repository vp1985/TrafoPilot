# TrafoPilot – Lexware Phase 1: DEV-Prüfbericht

Datum: 2026-10-01. Implementierung im externen Modul `modules/hwoslexware`, kein Core-Patch. Keine Commits, Pushes oder TEST-/Produktionsdeployments.

Fortgeschriebene Abnahme vom 2026-10-02: siehe [Abnahmebericht](lexware-abnahme-2026-10-02.md). Profil-/Referenzdaten werden jetzt bytegenau gespeichert. Native Duplikate und Folgeobjekte wurden geprüft; ein externer Trigger entfernt geerbte Lexware-Kennungen aus neuen lokalen Dokumenten. Negative HTTP-Aktionsprüfungen ergänzen die Rechteprüfungen. Gesamtfreigabe bleibt wegen der dort dokumentierten Restnachweise offen.

## Änderungen

- `modules/hwoslexware/core/modules/modHwosLexware.class.php`: Abhängigkeit, Rechte 700201–700205, Menüs, Tabs, deaktivierter Cronjob, wiederholbare Migrationen und UUID-Extrafields.
- `modules/hwoslexware/sql/llx_hwoslexware.sql`: Spiegel, Läufe/Checkpoint-Aufgaben, Mapping/Projektionspolitik, Konflikte, Originaldateien, Beziehungen.
- `modules/hwoslexware/class/`: GET-Client, Zugriffskontrolle, Normalisierung/Adapter, Spiegel/Audit, native Projektionen, persistente Pipeline, Cron und triggerfreie Versand-Unterklasse.
- `modules/hwoslexware/*.php` und `lib/bootstrap.php`: Konfiguration/Verbindung, Übersicht, Historie, Ressourcen, Originaldateien, Konflikte und Objekttabs.
- `modules/hwoslexware/langs/`: deutsche und englische Modultexte.
- `scripts/lexware-dev.py`, `scripts/lexware-dev.php`: gesicherter, ausschließlich DEV verwendender CLI-Launcher.
- `scripts/lexware-ui-dev.py`, `scripts/lexware-ui-fixture.php`, `scripts/lexware-ui-probe.php`: temporäre HTTP-Testfixtures und Zustandswiederherstellung.
- `scripts/lexware-clean-fixtures.php`: gezielte Bereinigung künstlicher Rückstände unterbrochener DEV-Tests; reale Spiegel- und Auditdaten bleiben erhalten.
- `scripts/lexware-state-dev.php`: Prüfsummen aller gespeicherten Payloads, eindeutige Identitäten und Wiederherstellung des Ausgangszustands.
- `tests/php/test_lexware_*.php`: Client-, Contract-, Berechtigungs-, Schema-, Integrations- und UI-Prüfungen.
- `docs/lexware-endpoints.md`, Modul-README und historische Blockerdokumentation: Inventar, Plan, Betrieb und Grenzen.

## Echte Lexware-Testorganisation

GET `/v1/profile` bestätigt `Holger Testzentrum` und `a4545756-abd0-4ab9-965b-ecd42c41fc7c`. Es wurde ausschließlich der Read-only-Schlüssel aus der lokalen Datei verwendet. Der Vollzugriffsschlüssel wurde nie abgefragt. Der Schlüssel steht nicht in Quellcode, Fixtures, Argumentlisten oder Ausgaben.

| Ressource | Gefunden |
| --- | ---: |
| Angebote | 7 |
| Auftragsbestätigungen | 6 |
| Rechnungen | 5 |
| Gutschriften | 5 |
| Lieferscheine | 5 |
| Länder | 257 |
| Buchungskategorien | 231 |
| Zahlungsbedingungen | 1 |
| Drucklayouts | 1 |
| Kontakte, Artikel, Wiederholungsvorlagen, Webhook-Konfigurationen | jeweils 0 |

Erstimport Lauf 18 und Wiederholungsimporte 31 und 61 abgeschlossen. Zusätzlicher Dry Run 39: Datenbankprüfsummen sämtlicher geprüfter Geschäftstabellen unverändert. Inkrementeller Lauf 40 abgeschlossen; keine neuen Belegänderungen im Filterzeitraum. Lager-, Bank-, Zahlungs- und Buchhaltungstabellen bleiben bei sämtlichen geprüften Live-Imports unverändert.

Die 28 Verkaufsdokumente besitzen keine für diese Projektion nutzbaren Kontaktreferenzen. Sie sind vollständig im Rohdatenspiegel gespeichert und bleiben Spiegelobjekte; es wurden keine fiktiven Kunden/Aufträge erzeugt. Eine Dateirepräsentation war über die API nicht verfügbar und wurde sichtbar übersprungen. Kein Live-PDF- oder Zahlungspositionsnachweis mit nichtleeren Daten möglich; diese Fälle werden zusätzlich mit künstlichen DEV-Fixtures geprüft. Es wurden keine Testbelege nach Lexware geschrieben, um solche Fälle herzustellen.

Nach Abschluss der Prüfungen wurden Rückstände unterbrochener künstlicher Tests gezielt bereinigt und das neu eingeführte Modul wieder deaktiviert. Der reale Lexware-Spiegel und Core-Audit bleiben gespeichert.

Abschließende Integritätsprüfung: 529 geschützte Spiegel-Datensätze, sämtliche Payload-Prüfsummen gültig, keine doppelten Ressourcenidentitäten und keine verbleibenden temporären HTTP-Testbenutzer.

## Prüfungen

Die Tests prüfen GET-Allowlist, fehlendes/falsches Profil, Sicherheitsabstand, HTTP 429, Netzwerkfehler, 5xx/504, Retry-Grenzen und Fehlerredaktion. Contracts prüfen normalisierte Prüfsummen, Listenreihenfolge, leere Objekte/Listen, Routing und Dublettenentscheidungen. Rechteprüfungen gelten auf Serverseite.

DEV-Fixtures prüfen zweimalige Aktivierung, vollständige Spaltenmetadaten und alle Indizes, UUID-Extrafields, Pagination, Dry-Run-Invarianten, Wiederaufnahme, native Kontakte inklusive kombinierter Rollen/Ansprechpartner, Produkte/Dienstleistungen, Angebote, Aufträge, Rechnungen, Gutschriften, eindeutig verknüpfte Lieferungen, Nummern/UUIDs, Original-PDF-Zuordnung, Wiederholungsimport, PDF-Cache, Dokumentrevisionen, isolierte Fehler mit Wiederholung sowie lokale Konflikte. Fixture-Entität: 970200. Vorherige Aktivierungszustände werden wiederhergestellt; Fixtureobjekte werden nach Abhängigkeiten bereinigt.

Oberflächen werden in DEV gerendert. Zusätzliche echte HTTP-Prüfung mit temporärem Nicht-Admin-Benutzer: Übersicht, Spiegel und Konfliktseite erfolgreich; POST ohne CSRF-Token abgewiesen. Der Benutzer und der geschützte Diagnoseendpunkt werden anschließend entfernt. Bestehende Benutzerpasswörter werden nicht verändert.

## Grenzen und offene Einrichtung

- Keine vollständige Enumeration von Mahnungen: die Public API dokumentiert nur Einzelabruf. Bekannte Beziehungen werden verfolgt, kein Scraping.
- Leere Live-Stammdaten und überwiegend Entwurfsbelege begrenzen den nichtleeren Live-Nachweis. Nicht verfügbare Dateien werden nicht durch Rendering erzeugt.
- Nicht eindeutig abbildbare Steuern, fehlende Kontakte/Aufträge, Strukturänderungen von Dokumentpositionen und Versandrevisionen bleiben sichtbare Prüf-/Spiegelfälle. Bestehende manuell verknüpfte Kontakte werden ohne gesonderte Feldfreigabe nicht überschrieben.
- Die dauerhafte DEV-Web-/Cron-Einrichtung ist nicht vorgenommen: das bestehende `custom`-Elternverzeichnis ist für den Webserver nicht traversierbar (`root:root 750`). Für den HTTP-Test wurde das Traverse-Recht temporär ergänzt und danach wiederhergestellt. Außerdem benötigen Webserver/Cron die sichere Injektion der Read-only-Umgebungsvariable. Die Tests nutzen temporär bereitgestellten Code; eine dauerhafte Quellcodeeinbindung ist separat einzurichten.
- Kein authentifizierter visueller Browsertest und keine native Live-Projektion mit echten nichtleeren Stammdaten. Der Bericht behauptet deshalb keine uneingeschränkte Abnahme aller 15 Kriterien.

Phase 2 benötigt eine separate Freigabe für Schreibzugriffe, Feldhoheit und Konfliktauflösung, Bank-/Zahlungs-/Buchhaltungsmapping sowie Webhook-Registrierung. Die bestehende persistente Aufgabenpipeline kann später von Webhook-Ereignissen genutzt werden.
