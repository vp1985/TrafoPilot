# TrafoPilot: Lexware-Office-Spiegel – fehlende Secret-Injektion

Historische Vorprüfung vom 2026-10-01. **Die Secret-Blockade ist inzwischen aufgelöst:** Der Benutzer hat die Read-only-Datei unter `/home/vadmin/.config/trafopilot/lexware-dev.env` mit Rechten 600 bereitgestellt. GET-Profilprüfung, Dry Runs und DEV-Import wurden anschließend ausgeführt. Aktueller Stand: [Prüfbericht](lexware-phase1-report.md). Die folgenden Abschnitte beschreiben ausschließlich den ursprünglichen Abbruch.

## Vorprüfung

- Repository `/home/vadmin/projects/hwos-dolibarr` vorhanden; Git-Arbeitsbaum vor Beginn sauber.
- `AGENTS.md`, `README.md`, vorhandene Dokumentation, Moduldateien und Tests vollständig gelesen.
- Bestehendes Modul: `hwoscore` 0.1.0, Dolibarr 24.0, PHP ab 8.1.
- Core enthält Modulrechte, idempotente Tabellenanlage und Audit-Tabelle; ausführbare gemeinsame Audit-, Queue-, Mapping- und Idempotenzdienste fehlen bislang.
- DEV-Container `dolibarr-dev-dolibarr-1` vorhanden.
- `LEXWARE_TEST_API_KEY_READ_ONLY` fehlt sowohl in der CLI-Umgebung als auch im DEV-Webcontainer. Es wurden ausschließlich Präsenzprüfungen ohne Ausgabe von Secret-Werten durchgeführt.
- Der Vollzugriffsschlüssel wurde weder geladen noch geprüft.

## Abbruch gemäß Auftrag

Der Auftrag verlangt bei fehlender Umgebungsvariable einen sauberen Abbruch und Dokumentation der fehlenden Secret-Injektion. Deshalb wurden keine API-Smoke-Tests, Imports, Modulaktivierungen oder fachlichen Datenänderungen ausgeführt. Kein Dummy-Schlüssel wurde angelegt. Es erfolgten keine Commits, Pushes oder Deployments.

Die Infrastruktur muss `LEXWARE_TEST_API_KEY_READ_ONLY` sicher in den autorisierten DEV-Ausführungskontext injizieren. Der Schlüssel gehört nicht in Chat, Repository, Testfixtures oder normale Logs. Eine Injektion in TEST oder Produktion ist für diesen Auftrag nicht erforderlich.

## API-Vorprüfung

Quelle: [aktuelle offizielle Lexware-API-Dokumentation](https://developers.lexware.io/docs/), geprüft am 2026-10-01.

Die API-Basis ist `https://api.lexware.io`; das globale Limit beträgt zwei Anfragen je Sekunde. Der Profilvergleich muss Organisations-ID und Firmenname prüfen, bevor lokale Änderungen beginnen.

Wichtig: Die veralteten GET-Unterressourcen `/document` können PDF-Erzeugung auslösen. GET allein garantiert daher keine semantische Schreibfreiheit; diese Endpunkte sind für Phase 1 auszuschließen. Die vollständige Endpoint-/Methoden-Matrix und die Prüfung der Nebenwirkungsfreiheit der Datei-Endpunkte stehen noch aus.

Erwartetes Profil:

- Organisation: `Holger Testzentrum`
- ID: `a4545756-abd0-4ab9-965b-ecd42c41fc7c`

## Fortsetzung

Nach sicherer Secret-Injektion: aktuellen Git-Status erneut prüfen, vollständige API-Matrix erstellen, Datenmodell und Implementierungsplan dokumentieren, anschließend die Integration nach den TDD-Regeln implementieren und gegen DEV prüfen. Sämtliche 15 Abnahmekriterien bleiben offen; die Vorprüfung ersetzt keinen Funktionsnachweis.
