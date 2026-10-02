# TrafoPilot – Abnahmeprüfung Lexware Phase 1

Prüfdatum: 2026-10-02. Ergebnis: **Teilabnahme; Gesamtfreigabe noch offen**.
Geprüft wurden der vorhandene Modulcode, die aktuelle offizielle API-Dokumentation,
die gesamte Repository-Testsuite, echte DEV-HTTP-Aufrufe und erneute GET-only-Läufe.
Keine Commits, Pushes oder TEST-/Produktionsänderungen.

## Kriterien und Nachweise

| Nr. | Kriterium | Ergebnis | Nachweis / Grenze |
| --- | --- | --- | --- |
| 1 | Zweimalige Aktivierung/Migration | Erfüllt | DEV-Test prüft Schema, Indizes, Extrafields und Zustandswiederherstellung. |
| 2 | Read-only-Verbindung und Organisation | Erfüllt | Erneuter Live-GET bestätigt Holger Testzentrum und erwartete UUID; ausschließlich Read-only-Variable. |
| 3 | Dry Run ohne fachliche Änderungen | Erfüllt | Live-Lauf 66 vollständig; Geschäftstabellen-Prüfsummen unverändert. |
| 4 | Pagination, Wiederaufnahme, Idempotenz | Erfüllt im geprüften Umfang | Persistente Queue und unterbrochener/wiederaufgenommener Fixturelauf; Live-Vollabgleich. |
| 5 | Aktuelle Endpoint-Matrix | Erfüllt mit API-Grenze | Inventar erneut gegen https://developers.lexware.io/docs/ geprüft; Mahnungen nur über bekannte IDs/Beziehungen ermittelbar. |
| 6 | Verlustfreier Spiegel aller Antworten | Erfüllt im geprüften JSON-Umfang | Profilantwort jetzt bytegenau gespeichert; Referenzobjekte direkt aus Original-JSON entnommen. Contracts und Datenbanktests prüfen leere Objekte, große Integer, verschachtelte Strukturen und Zeichenketten. Dateigrößenbegrenzung bleibt separat offen. |
| 7 | Korrekte native Projektion | Teilweise belegt | Native Fixtureprojektionen erfolgreich. Live-Dokumente ohne nutzbare Kontaktreferenzen bleiben Spiegelobjekte; kein positiver Live-Nachweis. Unterstützte Steuer-/Statuskonstellationen begrenzt. |
| 8 | Wiederholung ohne Dubletten | Erfüllt im geprüften Umfang | Fixturewiederholung und wiederholter Live-Vollabgleich; eindeutige Ressourcen-/Mappingregeln. |
| 9 | Historische Lieferung ohne Bestandsänderung | Erfüllt im geprüften Umfang | Native Fixturelieferung und Live-Läufe; Lager-Prüfsummen unverändert. |
| 10 | Keine unfreigegebenen Zahlungsbuchungen | Erfüllt | Bank-, Zahlungs- und Buchhaltungstabellen unverändert; Zahlungen werden nur gespiegelt. |
| 11 | Nummern, UUIDs, PDFs, Dokumentketten | Teilweise belegt | Live-PDF einer Lexware-Offerte bytegenau erneut abgerufen und gegen den Spiegel geprüft; Fixture-PDF, Cache, Nummern, UUIDs und Beziehungen ebenfalls geprüft. Native Duplikate von Angebot/Auftrag/Rechnung und lokale Folgeobjekte geprüft; Original und Mapping unverändert. Eine weitere Dateirepräsentation bleibt nicht verfügbar. |
| 12 | Rechte verhindern unbefugte Aktionen | Erfüllt im geprüften Umfang | Serverseitige Tests aller fünf Rechte; negative HTTP-Tests für Abruf, Verbindungstest, Retry, Ressourcenabruf und Mapping mit gültigem CSRF-Token. Prüfsummen von Läufen, Ressourcen und Mappings unverändert; POST ohne CSRF abgewiesen. |
| 13 | Fehler/Konflikte sichtbar und wiederverarbeitbar | Erfüllt im geprüften Umfang | Persistente Einzelfehler, Retry, Konflikt und manuelle Zuordnung getestet; Übersichts-/Konfliktseiten über HTTP erfolgreich. |
| 14 | Keine schreibenden Lexware-Aufrufe | Erfüllt | Transport ausschließlich CURLOPT_HTTPGET, feste Domain, GET-Allowlist, keine Redirects; ausschließlich Read-only-Secret. Rendering-Endpunkte ausgeschlossen. |
| 15 | Tests und dokumentierte manuelle DEV-Prüfung | Teilweise erfüllt | Gesamte Testsuite und echte HTTP-Prüfungen erfolgreich; authentifizierte visuelle Bedienprüfung und dauerhafte DEV-Einbindung ausstehend. |

## Erneut ausgeführte Prüfungen

- `bash scripts/test-dev.sh`: vollständig erfolgreich, einschließlich Coretests und aller Lexwaretests.
- `python3 scripts/lexware-dev.py --mode smoke`: erfolgreich. Ein erster gleichzeitig mit der Testsuite versuchter Aufruf wurde durch den Organisations-Lock sicher abgewiesen; nach Testende erfolgreich wiederholt.
- `python3 scripts/lexware-dev.py --mode dry`: Lauf 66, acht Batches, vollständig, unveränderte Geschäftsdaten.
- `python3 scripts/lexware-dev.py --mode full`: Lauf 67, acht Batches, vollständig; 28 Dokumente erneut gespiegelt, Lager-/Bank-/Zahlungs-/Buchhaltungs-Prüfsummen unverändert.
- Abschließende Integritätsprüfung: 529 Spiegelressourcen, gültige Payload-Prüfsummen, keine doppelten Identitäten; Modul wieder inaktiv, temporäre HTTP-Benutzer entfernt. Temporäre DEV-Modulkopie und CLI-Prüfhelfer anschließend entfernt.
- `python3 scripts/lexware-ui-dev.py`: Übersicht, Spiegel und Konfliktseite authentifiziert erreichbar; POST ohne CSRF abgewiesen. Temporären Benutzer und Verzeichnisrechte wiederhergestellt.

## Erforderliche Restnachweise

1. Geschlossen: Profil-/Referenzverarbeitung korrigiert und mit Contract-/Datenbanktests für vollständige Original-Payloads geprüft.
2. Originaldatei geschlossen: Eine Live-PDF-Datei wurde erneut per GET abgerufen und bytegenau gegen den Spiegel geprüft. Native Projektionen bleiben mangels nichtleerer Kontakte und Artikel in der Testorganisation offen. Diese Prüfung darf keine Lexware-Schreibzugriffe verwenden.
3. Geschlossen für Angebot/Auftrag/Rechnung: native Duplikate sowie Auftrag aus Angebot und Rechnung aus Auftrag geprüft. Gefundene Übernahme von Lexware-Identitäten durch externen Modultrigger korrigiert; Originale und Mapping unverändert.
4. Negative HTTP-Aktionsprüfungen geschlossen; authentifizierte visuelle DEV-Bedienprüfung bleibt offen.
5. Dauerhafte DEV-Einbindung und sichere Secret-Injektion für Web/Cron separat einrichten, bevor laufender Betrieb geprüft werden kann.

Weitere Reviewpunkte: Dateigrößenlimit 32 MiB begrenzt die behauptete Vollständigkeit;
gelöste/entfernte Dokumentbeziehungen werden bislang nur hinzugefügt; Änderungen von
Positionsstrukturen und Versandrevisionen bleiben manuelle Konfliktfälle.
Diese Punkte sind keine freigegebene Gesamtvollständigkeit.

Die fehlende Mahnungsliste ist eine Grenze der Public API. Keine UI-Simulation oder Scraping-Ergänzung.

## Ergänzende Korrekturen nach der ersten Prüfung

- Originalprofil und einzelne Referenzobjekte werden ohne JSON-Umkodierung gespeichert; Datenbanktests vergleichen die Originalbytes inklusive leerer Objekte und großer Integer.
- Native Dolibarr-Kopien übernahmen `ref_ext`, Folgeobjekte zusätzlich UUID-Extrafields. Ein externer Trigger entfernt solche geerbten Identitäten ausschließlich aus neuen, nicht gemappten lokalen Dokumenten. Er verlangt native Erstellrechte und schreibt ein Audit-Ereignis innerhalb der nativen Erstelltransaktion. Importierte Originale bleiben triggerfrei und unverändert.
- Triggerregistrierung ist bei mehrfacher Aktivierung idempotent. DEV-Tests verwenden den tatsächlich registrierten Triggerpfad und explizite native Erstellrechte ausschließlich im Testprozess.
- Webaktionen prüfen Rechte vor Secret-Konfiguration und Clienterstellung. Negative HTTP-Tests bestätigen die Ablehnung und unveränderte Spiegelzustände.
- Erneuter Live-Vollabgleich mit den Korrekturen: Lauf 98, acht Batches, vollständig; 28 Verkaufsdokumente, keine API-Aufgabenfehler, eine nicht verfügbare Dateirepräsentation sichtbar übersprungen. Lager-, Bank-, Zahlungs- und Buchhaltungstabellen unverändert.

## Ergänzender Live-Nachweis

- GET-only-Vollabgleich Lauf 103 am 2026-10-02: acht Batches, Status `complete`, kein Laufzeitfehler.
- Erneut gefunden: 28 Verkaufsdokumente (7 Angebote, 6 Auftragsbestätigungen, 5 Rechnungen, 5 Gutschriften und 5 Lieferscheine) sowie 257 Länder, 231 Buchungskategorien, eine Zahlungsbedingung und ein Drucklayout.
- Lager-, Bank-, Zahlungs- und Buchhaltungstabellen blieben laut Vorher-/Nachher-Prüfsummen unverändert.
- Integritätsstand nach Lauf 103: 529 Spiegelressourcen, keine offenen Spiegelprobleme, keine native Zuordnung; `hwoslexware` wurde nach dem Lauf wieder deaktiviert und die temporäre Modulkopie sowie der CLI-Helfer wurden entfernt.
- Live-Dateinachweis: GET `/v1/quotations/6db0ceb0-8a87-4ff6-b79d-d6f4a3e94b54/file` mit dem Read-only-Schlüssel lieferte HTTP 200 und `application/pdf`. Die 42.974 Bytes stimmen mit dem gespeicherten Spiegelobjekt überein; beide SHA-256-Prüfsummen sind `9438215303046841f7ebfe651c3a5016928ea1d0417b88bf917bf85a4ee25cba`.
- Der verbleibende Live-Blocker ist fachlich: Die Testorganisation liefert weiterhin null Kontakte und null Artikel. Deshalb kann derzeit keine echte native Projektion nach Dolibarr nachgewiesen werden, ohne geeignete Teststammdaten in Lexware bereitzustellen. Es wurden keine Lexware-Daten geschrieben.
