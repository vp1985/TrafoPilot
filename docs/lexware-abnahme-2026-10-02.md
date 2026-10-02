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
| 7 | Korrekte native Projektion | Erfüllt im geprüften Umfang | Autorisierte synthetische Live-Daten wurden in Lexware angelegt. Der anschließende GET-only-Lauf projizierte Kontakt, Leistung und verknüpftes Angebot nativ und mit korrekten Summen nach Dolibarr. Unterstützte Steuer-/Statuskonstellationen bleiben begrenzt. |
| 8 | Wiederholung ohne Dubletten | Erfüllt im geprüften Umfang | Fixturewiederholung und wiederholte Live-Vollabgleiche; Lauf 105 erkannte Kontakt, Leistung und Angebot als unverändert und behielt dieselben drei nativen Zuordnungen. |
| 9 | Historische Lieferung ohne Bestandsänderung | Erfüllt im geprüften Umfang | Native Fixturelieferung und Live-Läufe; Lager-Prüfsummen unverändert. |
| 10 | Keine unfreigegebenen Zahlungsbuchungen | Erfüllt | Bank-, Zahlungs- und Buchhaltungstabellen unverändert; Zahlungen werden nur gespiegelt. |
| 11 | Nummern, UUIDs, PDFs, Dokumentketten | Teilweise belegt | Live-PDF einer Lexware-Offerte bytegenau erneut abgerufen und gegen den Spiegel geprüft; Fixture-PDF, Cache, Nummern, UUIDs und Beziehungen ebenfalls geprüft. Native Duplikate von Angebot/Auftrag/Rechnung und lokale Folgeobjekte geprüft; Original und Mapping unverändert. Eine weitere Dateirepräsentation bleibt nicht verfügbar. |
| 12 | Rechte verhindern unbefugte Aktionen | Erfüllt im geprüften Umfang | Serverseitige Tests aller fünf Rechte; negative HTTP-Tests für Abruf, Verbindungstest, Retry, Ressourcenabruf und Mapping mit gültigem CSRF-Token. Prüfsummen von Läufen, Ressourcen und Mappings unverändert; POST ohne CSRF abgewiesen. |
| 13 | Fehler/Konflikte sichtbar und wiederverarbeitbar | Erfüllt im geprüften Umfang | Persistente Einzelfehler, Retry, Konflikt und manuelle Zuordnung getestet; Übersichts-/Konfliktseiten über HTTP erfolgreich. |
| 14 | Keine schreibenden Lexware-Aufrufe | Erfüllt | Transport ausschließlich CURLOPT_HTTPGET, feste Domain, GET-Allowlist, keine Redirects; ausschließlich Read-only-Secret. Rendering-Endpunkte ausgeschlossen. |
| 15 | Tests und dokumentierte manuelle DEV-Prüfung | Erfüllt im geprüften Umfang | Gesamte Testsuite, authentifizierte Chromium-Bedienprüfung mit visuell geprüften Screenshots und negative HTTP-Prüfungen erfolgreich; dauerhafte DEV-Einbindung und Secret-Datei für Web/Cron eingerichtet. Automatischer Cronbetrieb bleibt ungeprüft. |

## Erneut ausgeführte Prüfungen

- `bash scripts/test-dev.sh`: vollständig erfolgreich, einschließlich Coretests und aller Lexwaretests.
- `python3 scripts/lexware-dev.py --mode smoke`: erfolgreich. Ein erster gleichzeitig mit der Testsuite versuchter Aufruf wurde durch den Organisations-Lock sicher abgewiesen; nach Testende erfolgreich wiederholt.
- `python3 scripts/lexware-dev.py --mode dry`: Lauf 66, acht Batches, vollständig, unveränderte Geschäftsdaten.
- `python3 scripts/lexware-dev.py --mode full`: Lauf 67, acht Batches, vollständig; 28 Dokumente erneut gespiegelt, Lager-/Bank-/Zahlungs-/Buchhaltungs-Prüfsummen unverändert.
- Abschließende Integritätsprüfung: 529 Spiegelressourcen, gültige Payload-Prüfsummen, keine doppelten Identitäten; Modul wieder inaktiv, temporäre HTTP-Benutzer entfernt. Temporäre DEV-Modulkopie und CLI-Prüfhelfer anschließend entfernt.
- `python3 scripts/lexware-ui-dev.py`: Übersicht, Spiegel und Konfliktseite authentifiziert erreichbar; POST ohne CSRF abgewiesen. Temporären Benutzer und Verzeichnisrechte wiederhergestellt.

## Erforderliche Restnachweise

1. Geschlossen: Profil-/Referenzverarbeitung korrigiert und mit Contract-/Datenbanktests für vollständige Original-Payloads geprüft.
2. Geschlossen: Eine Live-PDF-Datei wurde erneut per GET abgerufen und bytegenau gegen den Spiegel geprüft. Nach ausdrücklicher Freigabe wurden ausschließlich zur Testvorbereitung ein synthetischer Kontakt, eine Leistung und ein Angebot in Lexware angelegt. Die Synchronisationsläufe selbst verwendeten danach ausschließlich den Read-only-Schlüssel und projizierten alle drei Objekte nativ nach Dolibarr.
3. Geschlossen für Angebot/Auftrag/Rechnung: native Duplikate sowie Auftrag aus Angebot und Rechnung aus Auftrag geprüft. Gefundene Übernahme von Lexware-Identitäten durch externen Modultrigger korrigiert; Originale und Mapping unverändert.
4. Geschlossen im geprüften Umfang: negative HTTP-Aktionsprüfungen sowie authentifizierte Chromium-Bedienprüfung von Übersicht, Spiegel, Ressourcendetail und Konfliktseite; Screenshots visuell geprüft.
5. Geschlossen für die Einrichtung: dauerhafte read-only DEV-Modulmounts, Web-Traverse-Rechte und geschützte Secret-Datei für Web/Cron eingerichtet und geprüft. Der Lexware-Cronjob bleibt deaktiviert; automatischer laufender Betrieb ist noch nicht abgenommen.

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
- Der zu diesem Zeitpunkt bestehende Live-Blocker durch fehlende Kontakte und Artikel wurde mit den nachfolgend dokumentierten, ausdrücklich autorisierten synthetischen Testdaten geschlossen.

## Nativer Live-Projektionsnachweis

- In der Lexware-Testorganisation wurden mit ausdrücklicher Freigabe drei eindeutig markierte synthetische Testobjekte angelegt: ein Kunde, eine Leistung und ein damit verknüpftes Angebotsdokument im Entwurfsstatus. Sie enthalten keine realen Personen-, Kunden- oder Leistungsdaten.
- Vor der Synchronisation wurden alle drei Objekte mit dem Read-only-Schlüssel einzeln erfolgreich zurückgelesen; Kontakt- und Artikel-IDs sowie die Artikelverknüpfung im Angebot stimmten überein.
- GET-only-Vollabgleich Lauf 104: neun Batches, Status `complete`, keine Aufgabenfehler. Gefunden wurden nun ein Kontakt, ein Artikel und 29 Verkaufsdokumente einschließlich acht Angeboten.
- Lauf 104 erzeugte genau drei native Mappings: Lexware-Kontakt → Dolibarr-Debitor 32, Lexware-Leistung → Dolibarr-Dienstleistung 44 und Lexware-Angebot → Dolibarr-Angebot 40.
- Das native Dolibarr-Angebot `AG0008` ist mit Debitor 32 und Dienstleistung 44 verknüpft. Eine Position mit Menge 1 und Nettopreis 100,00 EUR ergab 100,00 EUR netto, 19,00 EUR Umsatzsteuer und 119,00 EUR brutto.
- Kontrolllauf 105: Kontakt, Artikel und Angebot jeweils `unchanged=1`, keine neue Projektion, keine Dublette und keine Aufgabenfehler. Die nativen Objekt- und Mapping-IDs blieben unverändert.
- Beide Läufe bestätigten unveränderte Prüfsummen für Lager, Bank, Zahlungen und Buchhaltung. `hwoslexware` wurde nach jedem Lauf wieder in den vorherigen inaktiven Zustand versetzt; temporäre Modulkopien und CLI-Helfer wurden entfernt.

## Dauerhafte DEV-Einbindung und visueller Nachweis

- DEV-Compose bindet `hwoslexware` für Web und Cron dauerhaft aus dem Repository read-only ein. Die Secret-Datei ist ebenfalls read-only gemountet; beide Container wurden neu erstellt und der Webcontainer ist gesund.
- `/var/www/html/custom` wurde auf `root:www-data 0750` gesetzt. Core und Lexware sind anschließend dauerhaft in DEV aktiviert. Der verifizierte aktuelle DEV-Datenbankzustand nach ausdrücklicher Deaktivierung der vorbestehend aktiven, kontaminierten Fixture-Entität 970200 und Entfernung ihrer veralteten Cronzeile durch den Benutzer: genau eine `hwoslexware`-Cronzeile insgesamt, Entität 1, rowid 9, Status 0; keine Lexware-Konstanten für Entität 970200. Der Benutzer hat anschließend `bash scripts/test-dev.sh` aus diesem Worktree erfolgreich ausgeführt. Die Lifecycle-Tests stellen den vorherigen Aktivierungszustand wieder her; der zuvor aktive Fixturezustand war keine neu erzeugte Testaktivierung. Es wurde keine automatische Synchronisation freigeschaltet.
- Ausschließlich der bestehende `LEXWARE_TEST_API_KEY_READ_ONLY` wurde in eine Docker-Secret-Datei (`root:www-data 0440`) übernommen. Secret-Werte stehen weder in Compose noch in argv, Repository oder Prüfprotokollen. Web und Cron lesen den festen Secret-Pfad; ein GET-Profilabruf als `www-data` gelang auch mit vollständig bereinigter Cron-Umgebung.
- Testgetriebene Erweiterung: der neue Secret-Datei-Test scheiterte zuerst mit `READ_ONLY_SECRET_MISSING`; nach Implementierung bestehen sichere Dateiinjektion, Ablehnung öffentlicher/leerer/mehrdeutiger Quellen und die bestehende Umgebungsinjektion. `bash scripts/test-dev.sh` bestand vollständig gegen den dauerhaften Modulmount.
- `NODE_PATH=/tmp/hwos-lexware-browser/node_modules python3 scripts/lexware-ui-dev.py --visual` bestand nach Installation der Chromium-Systembibliotheken. Ein temporärer Benutzer bediente den GET-Verbindungstest, die Spiegelnavigation und ein Ressourcendetail. Übersicht, Spiegel, Detail und die derzeit leere Konfliktseite wurden in Desktop-Screenshots visuell geprüft; zusätzlich wurde die Übersicht bei 390 Pixeln Breite geprüft. Die Historientabelle benötigt dort horizontales Scrollen; eine allgemeine mobile Layoutabnahme ist damit nicht verbunden.
- Screenshot-Nachweise: `/tmp/hwos-lexware-visual/overview-desktop.png`, `resources-desktop.png`, `resource-desktop.png`, `issues-desktop.png` und `overview-mobile.png`. Diese lokalen Prüfarbeitsdateien sind kein Release-Artefakt.
- Erneute negative HTTP-Prüfungen wiesen Dry Run, Verbindungstest, Retry, Ressourcenabruf und Mapping für einen Benutzer mit ausschließlich Leserecht ab. Spiegelzustände blieben unverändert; POST ohne CSRF wurde abgewiesen. Temporäre HTTP-Benutzer wurden entfernt und die Verzeichnisrechte wiederhergestellt.
- Integritätsprüfung vor der dauerhaften Modulaktivierung: 532 Spiegelressourcen, sämtliche Payload-Prüfsummen gültig, keine doppelten Identitäten, keine verbliebenen HTTP-Fixture-Benutzer. Die drei zusätzlichen Ressourcen gegenüber Lauf 103 stammen aus den bereits dokumentierten synthetischen Live-Testdaten.

Die Teilabnahme bleibt bestehen: Dateigrößenbegrenzung, entfernte Dokumentbeziehungen, Positions-/Versandrevisionen und automatischer laufender Cronbetrieb sind weiterhin offene Review- bzw. Betriebsnachweise. TEST und Produktion wurden nicht verändert; keine Commits oder Pushes.

## Eingrenzte Nachprüfung der sechs Akzeptanzkorrekturen

Die Korrekturen wurden auf `feature/lexware-production-ready` im separaten Worktree geprüft. Der installierte Quellcheckout wurde nicht verändert. Die DEV-Suite verwendet temporär kopierte Worktree-Module und entfernt diese anschließend. Keine erneute Live-Synchronisation, Container-Neuerstellung oder Ausführung des Konfigurationsinstallers; TEST und Produktion bleiben unverändert.

- RED Client: `unsafe parent accepted` (PHP Exit 255). GREEN: sichere Elternpfade, Symlink-Elternabwehr, 4096/4097 serialisierte Dateibytes und bestehende Secret-Tests bestanden.
- RED Lifecycle: `no disabled Lexware fixture cron remains` (PHP Exit 255). GREEN: Fixture-Cronbereinigung und Wiederherstellung der vorherigen Aktivierung bestanden. Die Bereinigung ist auch bei Fehlern der nativen Objektbereinigung und Modulwiederherstellung über verschachtelte finally-Blöcke abgesichert.
- RED Einrichtung: Import führte Hostoperationen aus (`ROOT_REQUIRED`, unittest Exit 1); zusätzliche Prüfung doppelter YAML-Schlüssel scheiterte zunächst mit `ValueError not raised` (Exit 1). GREEN: sechs Python-Tests für importfreie Hostoperationen, teilweise konfigurierte Services, doppelte Mounts/Secrets/YAML-Schlüssel, Kandidatenvalidierungsfehler, erfolgreiche Installation, Rollback bei Compose-Austauschfehler und UTF-8/LF-Bytegrenze bestanden. Installationstests verwenden ausschließlich temporäre synthetische Dateien.
- `python3 -m unittest discover -s tests -p 'test_configure_lexware.py'`: sechs Tests, Exit 0.
- `sudo docker exec -e HWOS_MODULE_ROOT=/tmp/lx-modules dolibarr-dev-dolibarr-1 php /tmp/lx-unit.php`: fokussierter Clienttest, Exit 0 (Module/Test vorher aus dem Worktree kopiert).
- `sudo docker exec -e HWOS_MODULE_ROOT=/var/www/html/lx-focus dolibarr-dev-dolibarr-1 php /tmp/lx-dev.php`: fokussierter Lifecycle-Test, Exit 0; temporäre Kopien anschließend entfernt.
- `sudo docker exec --user www-data dolibarr-dev-dolibarr-1 env -i /usr/local/bin/php -r 'require "/var/www/html/lx-focus/hwoslexware/class/LexwareClient.php"; if (!(LexwareClient::fromEnvironment() instanceof LexwareClient)) { exit(1); } echo "PASS: default /run/secrets loading as www-data without environment\n";'`: Standard-Secretpfad mit leerer Umgebung, Exit 0, keine Secret-Ausgabe (`default-green.log`).
- `bash scripts/test-dev.sh`: sechs Python-Tests und alle PHP-Tests einschließlich Lifecycle und UI erfolgreich, Exit 0. `git diff --check`: Exit 0.

Die Rohprotokolle liegen lokal unter `/tmp/lexware-evidence/` (`config-red.log`, `config-duplicates-red.log`, `client-red.log`, `lifecycle-red.log`, entsprechende GREEN-Protokolle und `full-green.log`). Ein zwischenzeitlicher Test-Harness-Fehler durch gleichzeitiges Laden installierter und kopierter Klassen sowie ein fehlender Bootstrap beim ursprünglichen `/tmp`-Staging wurden korrigiert; die abschließende Suite prüft tatsächlich den Worktree einschließlich UI.

Die Einrichtung ersetzt jede Datei atomar und stellt bei einem fehlgeschlagenen Compose-Austausch das vorige Secret samt Dateirechten wieder her. Eine dateiübergreifende Atomizität bei Stromausfall ist damit nicht belegt. Gesamtfreigabe und Produktionsreife werden weiterhin nicht behauptet.

## Follow-up nach Bereinigung der Fixture-Entität

Der Benutzer bestätigte nach eigener ausdrücklicher Deaktivierung der kontaminierten, vorbestehend aktiven Entität 970200, Entfernung ihrer veralteten Cronzeile und erfolgreicher Worktree-Testsuite den aktuellen DEV-Datenbankzustand: genau eine `hwoslexware`-Cronzeile insgesamt (Entität 1, rowid 9, Status 0) und keine Lexware-Konstanten für Entität 970200. Diese Bestätigung ersetzt die frühere Aussage über eine zusätzliche Fixture-Cronzeile.

Die Backup-Zusage ist jetzt implementiert: nach erfolgreicher Compose-Kandidatenvalidierung wird das Original bytegenau als `compose.before-lexware.yaml` mit `root:root 0600` gesichert. Eine vollständig geschriebene, per fsync gesicherte temporäre Datei wird mittels atomarem Hardlink ohne Überschreiben veröffentlicht; anschließend wird das Elternverzeichnis per fsync gesichert, bevor Compose oder Secret ausgetauscht werden. Ein vorhandener Backup-Pfad bleibt unverändert. Ein späterer Installationsfehler darf das bereits gesicherte Original erhalten; bei Validierungsfehlern entsteht dagegen kein Backup und die installierten Dateien bleiben unverändert. Die bestehende Grenze fehlender dateiübergreifender Atomizität bleibt bestehen.

- RED: der neue Backuptest scheiterte mit `FileNotFoundError` für `compose.before-lexware.yaml`; sieben Tests, ein Fehler, Exit 1.
- GREEN: `python3 -m unittest discover -s tests -p test_configure_lexware.py`: sieben Tests bestanden, Exit 0.
- Eigentumsprüfung: `sudo python3 -m unittest discover -s tests -p test_configure_lexware.py`: sieben Tests bestanden, Exit 0; tatsächliche Backup-UID/GID 0/0 und Modus 0600 in temporären synthetischen Dateien geprüft. Wiederinstallation erhält Originalbytes und Backup-Inode; Validierungsfehler hinterlassen keine zusätzliche Datei.
- `bash scripts/test-dev.sh`: sieben Python-Tests und sämtliche PHP-Tests bestanden, Exit 0, einschließlich Fixture-Cronbereinigung und Wiederherstellung des vorherigen Aktivierungszustands.
- `git diff --check`: Exit 0.

Der Konfigurationsinstaller wurde ausschließlich in temporären Testverzeichnissen aufgerufen. Keine Live-Synchronisation, keine Änderung am ursprünglichen Checkout, TEST oder Produktion; keine Commits oder Pushes. Der Satz zur automatischen Synchronisation beginnt nun korrekt mit „Es“.

## Eng begrenzte Korrektur der unabhängigen Sicherheitsprüfung

Dieser Nachweis betrifft ausschließlich die vier blockierenden Installer-/Artefaktbefunde und die zugehörigen Reviewvorschläge. Er ersetzt keine Gesamtfreigabe und keine TEST-/Produktionsabnahme.

- Die ENV-Quelle wird über gepinnte No-follow-Verzeichnisdeskriptoren und einen auf 8192 Bytes begrenzten Dateideskriptor gelesen. Geöffnete Identität, regulärer Dateityp, Eigentümer, private Rechte und vertrauenswürdige Eltern werden geprüft. Tests decken Dateitausch nach dem Öffnen, Symlink-Datei/-Vorfahren, falschen Eigentümer, unsichere Eltern, FIFO und Übergröße ab.
- Compose-Zielkollisionen werden vor `safe_dump` abgewiesen, einschließlich abweichender Quellen, absoluter Ziele und normalisierter relativer Ziele. Bestehende Backups müssen regulär, root-eigen und 0600 sein; Symlinks, Verzeichnisse und unsichere Dateien werden abgewiesen.
- Bei fehlgeschlagenem Compose-Austausch und zusätzlich fehlgeschlagener Secret-Wiederherstellung bleibt die Rollback-Datei mit 0600 erhalten. `INCOMPLETE_RECOVERY` nennt den Wiederherstellungspfad ohne Secret oder ursprünglichen Fehlertext. Ein doppelter Austauschfehler ist fault-injiziert geprüft; erfolgreiche Wiederherstellung erhält Bytes und ursprüngliche Dateirechte.
- Der Browser traversiert Artefaktverzeichnisse über No-follow-Deskriptoren, verlangt am Ziel aktuellen Eigentümer und 0700 und erstellt PNGs exklusiv mit 0600. Vorhandene PNGs werden nicht überschrieben. Ein fehlender Ressourcendetail-Link ist ein Fehler. Sicherheitsprüfungen benötigen keinen Browser und kein Playwright beim Import.
- PHP-Testkopien liegen in einem invocation-privaten, zufälligen 0700-Containerverzeichnis. Fehlgeschlagene UI-Fixture-Erstellung entfernt den Benutzer und versucht die Wiederherstellung beider Module. Der Python-Helper wird auch bei fehlschlagender Rechtewiederherstellung entfernt.

RED-Nachweise vor den Implementierungen:

- Konfigurationsregressionen: 11 Tests, 1 Failure und 3 Errors, Exit 1 (Zielkollision, fehlender Descriptor-Reader, doppelte Wiederherstellungsstörung und unsicherer Backuppfad; der Backup-Test traf zunächst zusätzlich auf fehlendes root-fchown im unprivilegierten Test).
- Normalisierte Compose-Zielkollision: 11 Tests, 1 Failure, Exit 1.
- Browserfreie Visualtests: Exit 1 beim Import des bislang unbedingt benötigten Playwright; danach wurden die Dateisicherheits- und Detailpflicht-Prüfungen ohne Browser ausführbar.
- Helper-Entfernung bei fehlgeschlagener Rechtewiederherstellung: 1 Test, 1 Failure, Exit 1.
- Privates PHP-Testverzeichnis: 2 Tests, 1 Failure, Exit 1; Testkopien lagen noch unter gemeinsamen `/tmp/test_*.php`-Namen.
- UI-Fixture-Erstellfehler: PHP Exit 255, `creation failure leaked fixture or module state`. Nach Korrektur des Testdoubles bestand der neue Selbstbereinigungstest mit der Implementierung.

GREEN-Nachweise:

- `python3 -m unittest discover -s tests -p 'test_*.py'`: 13 Tests bestanden, Exit 0.
- `sudo -n python3 -m unittest discover -s tests -p test_configure_lexware.py`: 11 Tests bestanden, Exit 0; echte root-Eigentums-/Modusprüfung ausschließlich in temporären synthetischen Verzeichnissen.
- `node --test tests/test_lexware_visual.cjs`: 2 Tests bestanden, Exit 0, ohne Browser.
- `bash scripts/test-dev.sh`: Python-/Node-Prüfungen und alle 9 PHP-Testdateien bestanden, Exit 0. Enthalten sind GET-Allowlist, Fixturebereinigung, Migration, native Projektion, Rechte, UI und unveränderte Geschäfts-/Lager-/Zahlungsdaten.
- `python3 scripts/lexware-ui-dev.py`: Exit 0; authentifizierte Seiten, fünf unbefugte Aktionen, unveränderte Spiegelprüfsummen und CSRF-Abweisung geprüft.
- `NODE_PATH=/tmp/hwos-lexware-browser/node_modules python3 scripts/lexware-ui-dev.py --visual --artifacts /tmp/hwos-lexware-review-20261002`: Exit 0; Chromium prüfte Übersicht, Spiegel, verpflichtendes Ressourcendetail, Konflikte und mobile Übersicht. Der Verbindungstest verwendete ausschließlich GET. Verzeichnis: aktueller Benutzer, 0700; alle fünf PNGs: 0600.
- Bash-, Python-, Node- und PHP-Syntaxprüfungen sowie `git diff --check`: Exit 0.

Der damalige Prüflauf meldete keine offene Blockade aus seiner Befundliste; die finale Nachprüfung unten dokumentiert die verbleibende Quellvoraussetzung. Eine erneute unabhängige Prüfung steht aus; die bereits dokumentierten fachlichen Grenzen bleiben bestehen. Keine Commits/Pushes, keine Änderung am ursprünglichen Checkout oder an TEST/Produktion, keine Live-Synchronisation und kein Aufruf des Installers gegen die reale DEV-Konfiguration. UI-Prüfungen nutzten temporäre DEV-Fixtures und stellten deren Zustand wieder her. Die Screenshots belegen Navigation und Dateirechte; eine neue visuelle Inhaltsabnahme wird hier nicht behauptet.

## Zweiter und letzter Review-Fix-Zyklus

Dieser Abschnitt dokumentiert ausschließlich die sechs verbleibenden Reviewbefunde.
Keine erneute Installation, Live-Synchronisation, Container-Neuerstellung, Änderung
am ursprünglichen Checkout, TEST oder Produktion; keine Commits/Pushes. Der
GET-only-Transport wurde in diesem Zyklus nicht geändert.

- Linux-Zielnormalisierung behandelt redundante führende Slashes als einen Slash.
  RED: `//run/secrets/lexware_test_api_key_read_only` erreichte die Serialisierung
  (11 Python-Tests, 1 Failure, Exit 1). GREEN: einschließlich `//` und `///` werden
  konkurrierende Quellen vor der Serialisierung abgewiesen.
- Strikte Quellancestry bleibt erhalten. RED des neuen Preflight-Tests: fehlender
  `main(argv)`-Pfad (12 Tests, 1 Error, Exit 1). GREEN prüft synthetische 0775-Eltern,
  genaue sichere Diagnose, 0700-Erfolg und verbietet Docker-/Installationsaufrufe.
  Der reale schreibfreie Preflight lief mit Exit 1: `/home/vadmin/.config` ist
  `vadmin:vadmin 0775`. Das ist weiterhin eine **Betriebsblockade für Installation
  oder Rotation**. README dokumentiert die Administrator-Remediation, private
  0700-Quellverzeichnisse, vadmin-eigene 0600-ENV-Datei und Ziel `root:www-data 0440`.
  Keine Live-Rechte wurden dafür geändert.
- Normale HTTP-Fixture-Bereinigung versucht beide Modulzustände im finally-Block,
  auch wenn Benutzerlöschung fehlschlägt oder wirft. Fehler bei Benutzer- und
  Modulwiederherstellung werden gemeinsam als unvollständige Wiederherstellung
  gemeldet. Der korrigierte in-process Fault-Harness scheitert gegen das vorige
  Verhalten mit `ordinary cleanup leaked module state` (PHP Exit 255) und besteht
  gegen die Korrektur (Exit 0). Kombinierte Lösch-/Modulfehler sind enthalten.
- README verwendet für Web und Cron explizit `--force-recreate` nach Rotation.
- HTTP-Helper liegt in einem pro Aufruf neuen privaten Containerverzeichnis;
  hostlokales flock serialisiert Aufrufe desselben Benutzers vom Zustandslesen bis
  zur Bereinigung. RED: privater Helper fehlte und Lock-Modul existierte nicht
  (3 Tests, 1 Failure, 1 Error, Exit 1). GREEN: Entfernung wird auch nach misslungener
  Rechtewiederherstellung versucht; ein zweiter Prozess wartet bis zur Lock-Freigabe,
  Fehler geben den Lock frei, Symlink-Locks werden abgewiesen. Andere Werkzeuge
  oder Hostbenutzer sind von diesem Lock nicht erfasst.
- Generierte Python-Bytecode-Verzeichnisse wurden entfernt. `.gitignore` ignoriert
  `__pycache__/` und `*.py[cod]`; `git check-ignore` bestätigt beide betroffenen Pfade.

Abschließende verifizierte Ergebnisse:

- Fokussierte GREEN-Prüfungen: 12 Konfigurationstests und 3 Helpertests, Exit 0;
  PHP-Fault-Harness Exit 0. Das abschließende vollständige Script lief sequenziell:
  `PYTHONDONTWRITEBYTECODE=1 bash scripts/test-dev.sh`, Exit 0; 15 Python-Tests,
  2 Node-Tests und alle 9 PHP-Testdateien bestanden.
- `PYTHONDONTWRITEBYTECODE=1 python3 scripts/lexware-ui-dev.py`, Exit 0:
  drei authentifizierte Seiten, fünf Rechteabweisungen, unveränderte Spiegelprüfsummen
  und CSRF-Abweisung. Aufräumen lief erfolgreich. Kein visueller Neulauf in diesem
  Zyklus; keine neue visuelle Inhaltsabnahme oder erneuter Live-Verbindungsnachweis.
- Bash-, Python-, Node- und PHP-Syntaxprüfungen: Exit 0. Python wurde ohne neue
  Bytecode-Artefakte kompiliert; PHP erhielt den tatsächlichen Quelltext per stdin.
  `git diff --check`: Exit 0.

Zwischenläufe waren nicht als Abschlussnachweise geeignet: der erste PHP-Harness
verwendete das im Container deaktivierte `passthru`; überlappende Vollsuite-Aufrufe
kollidierten mit `LEXWARE_RUN_ALREADY_ACTIVE`. Der Harness arbeitet nun in-process,
und der abschließende sequenzielle Lauf bestand ohne diese Fehler.
Die finalen Rohprotokolle liegen lokal unter `/tmp/lexware-final-cycle-full-final.log`,
`/tmp/lexware-final-cycle-http.log` und
`/tmp/lexware-final-cycle-cleanup-{red,green}.log`.

Die fachlichen Grenzen und die Teilabnahme bleiben bestehen. Die reale
Quellancestry ist bis zur separat ausgeführten Remediation weiterhin nicht geeignet
für den strikten Konfigurationsinstaller; eine erfolgreiche Neuinstallation wird
hier ausdrücklich nicht behauptet.

## Abschließende Lifecycle-, Preflight- und Live-Nachprüfung

- Die zwei letzten Lifecycle-Befunde wurden testgetrieben geschlossen: Alle Aktivierungs-/Migrationsprüfungen registrieren den temporär kopierten Worktree-Modulpfad vor dem ersten `init()`-Aufruf. Eine globale Entity-0-Aktivierung wird außerhalb jedes Mutations- und Wiederherstellungspfads abgewiesen. Der UI-Fixture-Helfer verweigert globale Aktivierung ebenfalls vor Änderungen und vor Modulwiederherstellung.
- RED: Der neue Worktree-Pfadtest brach vor der Korrektur mit `staged module root is not registered before activation` ab. Der neue Global-Aktivierungstest erreichte zuvor fälschlich die Benutzeranlage und scheiterte mit `UI_FIXTURE_USER_FAILED` statt vor jeder Mutation.
- GREEN: `PYTHONDONTWRITEBYTECODE=1 bash scripts/test-dev.sh` bestand mit 15 Python-Tests, zwei Node-Tests und allen neun PHP-Testdateien ohne Warnungen. `git diff --check` bestand. Die fokussierte unabhängige Nachprüfung meldete keine Sicherheits- oder Logikfehler und bestätigte unveränderten GET-only-Transport.
- Die sichere DEV-Quellvoraussetzung wurde anschließend hergestellt: `/home/vadmin/.config` und `/home/vadmin/.config/trafopilot` sind `vadmin:vadmin 0700`, die ENV-Datei ist `vadmin:vadmin 0600`. `sudo python3 scripts/configure-lexware-dev.py --check-only` meldete `SOURCE_PREFLIGHT_OK`; der Schlüssel wurde nicht ausgegeben.
- GET-only-Live-Vollabgleich mit exakt dem Worktree-Modulcode: Lauf 206, neun Batches, Status `complete`, kein Lauf- oder Aufgabenfehler. Kontakt, Leistung und Angebot wurden unverändert den bestehenden nativen Objekten zugeordnet. Lager-, Bank-, Zahlungs- und Buchhaltungsprüfsummen blieben unverändert.
- Verifizierter DEV-Endzustand nach einmaliger Bereinigung historischer synthetischer Fixture-Reste und erneuter vollständiger Testsuite: ausschließlich Entität 1 enthält 13 Läufe, 532 Spiegelressourcen, drei native Mappings und eine PDF-Datei; Entität 970200 enthält keine Core-/Lexware-Aktivierung, keinen Cronjob und keine Spiegel-/Laufdaten. Genau ein deaktivierter Lexware-Cronjob besteht in Entität 1 (rowid 9, Status 0).
- Die nativen Live-Zuordnungen blieben unverändert: Debitor 32, Dienstleistung 44 und Angebot 40/`AG0008`; 100,00 EUR netto, 19,00 EUR Umsatzsteuer und 119,00 EUR brutto. Die gespeicherte PDF-Datei umfasst 42.974 Bytes; gespeicherter und berechneter SHA-256 sind weiterhin identisch (`9438215303046841f7ebfe651c3a5016928ea1d0417b88bf917bf85a4ee25cba`). Offene Spiegelprobleme: 0.

Dieser Abschnitt belegt den Worktree-Code in DEV. Versioniertes Artefakt, TEST-Installation und Produktionsfreigabe sind getrennte nachfolgende Gates.

## Versionierte Artefakte und TEST-Installation

- Aus Commit `1bb06ed` wurden zwei unveränderliche Dolibarr-Modulpakete erzeugt und mit gespeicherten SHA-256-Dateien geprüft:
  - `module_hwoscore-0.1.0-1bb06eda65f7.zip` — SHA-256 `038874d352018f4fff6d120659b66995784b43d5353cbee2484887fd9bb9f800`
  - `module_hwoslexware-0.1.0-1bb06eda65f7.zip` — SHA-256 `ea778be972f692167a71fc11a5d8423a8d5b72fe0ebb6c668d888d3bca6494fa`
- Beide Archive wurden auf Pfadtraversal, Symlinks und ZIP-Integrität geprüft, sicher nach `/opt/dolibarr/test/custom` installiert und anschließend dateiweise gegen den jeweiligen Archivinhalt verglichen: Core 5 Dateien, Lexware 21 Dateien, vollständige Manifestgleichheit.
- Core und Lexware wurden in TEST jeweils zweimal aktiviert. Tabellen und Aktivierungskonstanten sind vorhanden; genau ein Lexware-Cronjob besteht in TEST-Entität 1 und ist deaktiviert (rowid 1, Status 0).
- Die vollständigen neun PHP-/Dolibarr-Testdateien liefen anschließend gegen die tatsächlich installierten TEST-Module erfolgreich. Der Endzustand enthält keine Fixture-Aktivierung, keinen Fixture-Cronjob und keine Fixture-Spiegeldaten.
- TEST erhielt keinen Lexware-Schlüssel und führte keinen Live-Abgleich aus. Produktion wurde nicht verändert. Die Freigabe für einen automatischen Cronbetrieb oder eine Produktionsinstallation ist damit weiterhin nicht erteilt.
