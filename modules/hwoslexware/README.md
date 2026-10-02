# TrafoPilot – Lexware Office Spiegel

Externes Dolibarr-24-Modul `hwoslexware`, Version 0.1.0, Modul-ID 700200. Abhängigkeit: `modHwosCore`. Der Dolibarr-Core bleibt unverändert.

## Sicherheit und Betrieb

Ausschließlich `LEXWARE_TEST_API_KEY_READ_ONLY`; kein Vollzugriffs-Fallback. Der Client bietet ausschließlich erlaubte GET-Pfade auf `https://api.lexware.io`. Redirects und `/document`-Rendering sind gesperrt. UUID und Name der Testorganisation werden vor jedem Batch geprüft. Ein organisationsweiter Datenbank-Lock und mindestens 600 ms Abstand begrenzen parallele Abrufe. Wiederholbare Fehler werden maximal fünfmal mit Backoff versucht; Response-Fehlertexte und Header werden nicht protokolliert.

Die lokale Secret-Datei `/home/vadmin/.config/trafopilot/lexware-dev.env` wird ausschließlich vom DEV-Launcher gelesen, mit Eigentümer-/Rechteprüfung. Nur die benannte Read-only-Zeile wird ausgewertet; die Datei wird nicht als Shellcode geladen. Der Wert wird per stdin übergeben. Web und Cron lesen alternativ die rohe Secret-Datei `/run/secrets/lexware_test_api_key_read_only`. Ein anderer Pfad kann über `LEXWARE_TEST_API_KEY_READ_ONLY_FILE` konfiguriert werden. Gleichzeitig gesetzte Wert-/Pfadvariablen werden abgewiesen. Die Datei muss regulär, lesbar, höchstens 4096 Bytes groß und ohne Rechte für andere Benutzer oder Gruppenschreibrecht sein; Symlinks werden abgewiesen. Das Modul zeigt ausschließlich die Verfügbarkeit an. Der feste Docker-Secret-Pfad funktioniert auch ohne geerbte Cron-Umgebung.

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
# Optional: Chromium-Bedienprüfung mit Screenshots (Playwright erforderlich)
NODE_PATH=/tmp/hwos-lexware-browser/node_modules python3 scripts/lexware-ui-dev.py --visual
```

Der Quellcode muss in DEV unter `/var/www/html/custom/hwoslexware` verfügbar sein. Der CLI-Launcher installiert oder deployt keinen Code. Seine Modulaktivierung wird nach dem Lauf auf den vorherigen Zustand zurückgesetzt. Unterbrochene Läufe werden mit `--run ID` fortgesetzt. Vollimporte erfordern einen erfolgreichen persistenten Dry Run. Der Launcher prüft Geschäftsdatentabellen beim Dry Run sowie Lager-, Zahlungs-, Bank- und Buchhaltungstabellen bei Imports per Datenbankprüfsumme.

Integrationstest-Fixtures verwenden die getrennte Entität 970200 und künstliche Daten. Migrationstests prüfen sämtliche Tabellenspalten inklusive Typ, Nullbarkeit, Default und Extra sowie alle Indizes. Aktivierung wird zweimal ausgeführt; Deaktivierung bewahrt Spiegel und Audit. Tests stellen den vorherigen Aktivierungszustand wieder her. Der Cronjob ist bei Registrierung deaktiviert; ein freigegebener Cron-Benutzer braucht das Abrufrecht. Er verarbeitet begrenzte Batches, wählt wöchentlich einen vollständigen Kontrollscan und sonst einen Änderungsabruf.

TEST und Produktion dürfen ausschließlich ein versioniertes, unveränderliches Release-Artefakt erhalten. Kein solches Deployment gehört zu diesem Auftrag.

Der HTTP-Prüfer verwendet einen temporären Benutzer mit ausschließlich den Modulrechten und löscht ihn anschließend. Das DEV-Elternverzeichnis `/var/www/html/custom` ist seit der dauerhaften Einrichtung `root:www-data 750` und für den Webserver traversierbar. Der Prüfer stellt die ursprünglichen Verzeichnisrechte und den Aktivierungszustand im `finally`-Block wieder her. Er schreibt keine Probe-Datei in das read-only gemountete Modul. Die optionale Browserprüfung bedient den GET-Verbindungstest, die Spiegelnavigation und die Ressourcendetails; Ein fehlender Ressourcendetail-Link lässt die Browserprüfung scheitern. Screenshots landen standardmäßig in `/tmp/hwos-lexware-visual`: Symlink-Verzeichnisse sowie bestehende Verzeichnisse mit fremdem Eigentümer oder anderem Modus als 0700 werden abgewiesen. PNG-Dateien werden exklusiv mit 0600 angelegt; vorhandene Dateien werden nicht überschrieben. Bestehende Benutzerpasswörter bleiben unverändert.

## API-Grenzen und spätere Phasen

Siehe [Endpoint-Matrix](../../docs/lexware-endpoints.md). Mahnungen sind nicht vollständig enumerierbar. Entwürfe besitzen häufig keine PDF-Datei; 404/406/409 bei Dateirepräsentationen werden sichtbar übersprungen. Nicht lesbare UI-Bereiche werden nicht gescrapt. Native Zahlungsprojektion, Schreibzugriff, Webhook-Registrierung, erweiterte Steuermappings und feinere Feldkonfliktauflösung benötigen separate Planung und Freigabe.

## Dauerhafte DEV-Einrichtung

`scripts/configure-lexware-dev.py` ist ausschließlich für den vorhandenen DEV-Host vorgesehen und verlangt root sowie geprüfte Compose-Labels. Es ergänzt `/opt/dolibarr/dev/compose.yaml` um read-only Modulmounts und Docker-Secrets für Web und Cron. Der benannte Read-only-Schlüssel wird aus der geschützten Launcher-Datei gelesen und ohne Ausgabe als `/opt/dolibarr/dev/secrets/lexware_test_api_key_read_only` (`root:www-data`, 0440) gespeichert. Nach erfolgreicher Kandidatenvalidierung wird die ursprüngliche Compose-Datei vor der ersten Installation als `compose.before-lexware.yaml` (`root:root`, 0600) gesichert. Datei und Verzeichniseintrag werden vor den Installationsänderungen per fsync gesichert; ein bestehender Backup-Pfad wird niemals überschrieben und muss eine reguläre root-eigene Datei mit Modus 0600 sein. Symlinks und unsichere Backups werden abgewiesen. Bei Validierungsfehlern bleiben alle installierten Dateien unverändert und es entsteht kein Backup. Andere Schlüssel werden nicht übernommen.

```bash
sudo python3 scripts/configure-lexware-dev.py
sudo docker compose -f /opt/dolibarr/dev/compose.yaml up -d --no-deps --force-recreate dolibarr cron
```

Nach Secret-Rotation ist explizit `--force-recreate` für **dolibarr und cron** erforderlich, weil atomar ersetzte Dateien einen neuen Inode haben. Modulaktivierung erfolgt separat in Dolibarr. In DEV wurden Core und Lexware am 2026-10-02 aktiviert; der Lexware-Cronjob bleibt deaktiviert. Die Secret-Injektion ist für beide Kontexte geprüft, ein automatisch ausgeführter Lexware-Cronlauf ist damit noch nicht nachgewiesen.

Für die optionale Browserprüfung wurde Playwright 1.63.0 unter `/tmp/hwos-lexware-browser` installiert. Reproduktion: `npm install --prefix /tmp/hwos-lexware-browser playwright@1.63.0`, anschließend dessen `playwright install chromium` und bei fehlenden Systembibliotheken `playwright install-deps chromium`. Zugangsdaten werden dem Browser ausschließlich per stdin übergeben; der temporäre Testbenutzer wird anschließend entfernt.

Die Einrichtung benötigt Python-PyYAML. Sie prüft eine temporäre Compose-Datei im DEV-Projektverzeichnis vor jeder Installation. Jede der beiden Services erhält genau einen vorgesehenen Mount und ein Secret; doppelte oder abweichende Einträge werden abgewiesen. Compose und Secret werden einzeln atomar ersetzt; schlägt der Compose-Austausch fehl, wird die Wiederherstellung des vorigen Secrets versucht. Scheitert auch sie, bleibt die einzige Rollback-Kopie root-privat mit 0600 erhalten; `INCOMPLETE_RECOVERY` nennt ausschließlich den Pfad und verlangt manuelle Wiederherstellung. Während der Installation dürfen keine Container parallel neu erstellt werden. Kein Schutz gegen Stromausfall zwischen beiden Dateiaustauschen wird behauptet.

Das Dateilimit beträgt 4096 Bytes nach UTF-8-Serialisierung einschließlich des abschließenden LF (maximal 4095 ASCII-Schlüsselbytes). Der Client prüft dieselbe Grenze für die tatsächlich gelesenen Dateibytes. Konfigurierbare Pfade müssen absolut sein, ohne Symlinks, mit vertrauenswürdigen Eigentümern und nicht schreibbaren Elternverzeichnissen; root-eigene Sticky-Vorfahren oberhalb eines privaten direkten Elternverzeichnisses sind zulässig. Die geöffnete Datei wird über fstat und Identitätsvergleich vor/nach dem Öffnen geprüft. `/run/secrets` bleibt unterstützt.

Der Konfigurationsinstaller liest die Quelldatei über einen begrenzten No-follow-Deskriptor (maximal 8192 Bytes für die ENV-Datei), prüft geöffnete Identität, Eigentümer und Rechte und traversiert nur gepinnte, vertrauenswürdige Elternverzeichnisse. Konkurrierende Compose-Secrets am effektiven Ziel `/run/secrets/lexware_test_api_key_read_only` werden auch bei abweichendem Quellnamen vor der Serialisierung abgewiesen. PHP-Testkopien liegen in einem pro Aufruf neu erzeugten privaten Containerverzeichnis. Fehlgeschlagene HTTP-Fixture-Erstellung räumt Benutzer und Modulaktivierung selbst auf; die Entfernung des Helpers wird auch nach fehlgeschlagener Rechtewiederherstellung versucht.

### Sichere Quelle und schreibfreier Preflight

`python3 scripts/configure-lexware-dev.py --check-only` prüft ausschließlich die
Quelle über dieselben gepinnten No-follow-Deskriptoren. Kein Docker-Aufruf, Backup,
Installation oder Rechteänderung. Erfolg bestätigt nur die Quelle, keine laufenden
Container oder Compose-Konfiguration.

Jeder Vorfahr ab `/` muss root oder `vadmin` gehören, ohne Gruppen-/Fremdschreibrecht
und ohne Symlink. Private Quellverzeichnisse sollen 0700 haben; die reguläre ENV-Datei
muss `vadmin` gehören und soll 0600 haben. Installiertes Ziel: `root:www-data 0440`.
Am 2026-10-02 verweigert der Preflight die reale Quelle: `/home/vadmin/.config`
ist `vadmin:vadmin 0775`. `/home/vadmin` ist 0750, `trafopilot` 0700 und die ENV-Datei
0600. Die strenge Prüfung bleibt bestehen.

Erforderliche Administrator-Remediation, **hier nicht ausgeführt**: nach Prüfung von
Eigentümer, Symlinkfreiheit und Auswirkungen auf andere Nutzer
`chmod 0700 /home/vadmin/.config /home/vadmin/.config/trafopilot` und
`chmod 0600 /home/vadmin/.config/trafopilot/lexware-dev.env`; die privaten Quellen
müssen weiterhin `vadmin` gehören. Danach den Preflight erneut bestehen lassen,
bevor separat autorisierte Installation oder Rotation erfolgt.

HTTP-Fixtures verwenden ein zufälliges 0700-Containerverzeichnis pro Aufruf.
Eine hostlokale benutzereigene 0600-flock-Datei unter
`/tmp/hwos-lexware-ui-<uid>.lock` serialisiert HTTP-Prüfer dieses Benutzers vom Lesen
der Ausgangszustände bis zum Ende sämtlicher Aufräumversuche. Der Lock-Inode bleibt
für wartende Aufrufe bestehen. Andere Werkzeuge oder Benutzer sind nicht serialisiert.
Normale Bereinigung versucht auch bei fehlgeschlagenem oder werfendem `User::delete`
beide Modulwiederherstellungen im finally-Block. Unvollständige Wiederherstellung
meldet Benutzer- und Modulfehler gemeinsam ohne rohe Fehlerdetails.
