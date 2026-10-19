# Deploy sul NAS

Il workflow `deploy-nas.yml` parte sui push a `g96-custom` o manualmente dallo stesso
branch. Costruisce un'immagine amd64 e la trasferisce via SSH nella rete Tailscale:
non richiede registry né porte pubbliche.

Destinazione: `g96admin@100.115.48.28`, progetto `/srv/g96-data/compose/dreeve`.
Il Compose già presente sul NAS resta invariato, compresi i volumi in `data/storage`.
Viene aggiornato solo `DREEVE_IMAGE` nel `.env` remoto.

## GitHub e Tailscale

Creare l'environment GitHub **nas**, limitato al branch `g96-custom`, e questi secrets:

| Secret | Contenuto |
| --- | --- |
| `TS_OAUTH_CLIENT_ID` | Client ID OAuth Tailscale |
| `TS_OAUTH_SECRET` | Client secret OAuth Tailscale |
| `NAS_SSH_KEY` | Chiave privata dedicata al deploy |
| `NAS_SSH_KNOWN_HOSTS` | Chiave host SSH verificata per `100.115.48.28` |

Creare il client OAuth nella console Tailscale con permesso di scrittura sulle
auth keys e tag `tag:github-actions`. Configurare i tag owner e consentire a quel
tag **solo TCP 22 verso il NAS** nelle ACL/grants della tailnet. Il client OAuth
non si può ricavare dalla sessione Tailscale già attiva sul NAS.
Si usa OpenSSH con chiave dedicata, non la funzione Tailscale SSH.

### Completare la configurazione da questo Mac

Gli script e la chiave pubblica limitata sono già stati installati sul NAS.
La chiave privata dedicata è in `~/.ssh/g96-dreeve-actions_ed25519` e la chiave
host, letta tramite la connessione SSH già verificata, è in
`~/.ssh/g96-dreeve-actions_known_hosts`. Non aggiungere questi file al repository.

Dopo `gh auth login`, creare l'environment e caricare i secrets:

```sh
gh api --method PUT repos/giovaganzerli/g96cloud-dreeve/environments/nas
gh secret set NAS_SSH_KEY --repo giovaganzerli/g96cloud-dreeve --env nas < ~/.ssh/g96-dreeve-actions_ed25519
gh secret set NAS_SSH_KNOWN_HOSTS --repo giovaganzerli/g96cloud-dreeve --env nas < ~/.ssh/g96-dreeve-actions_known_hosts
gh secret set TS_OAUTH_CLIENT_ID --repo giovaganzerli/g96cloud-dreeve --env nas
gh secret set TS_OAUTH_SECRET --repo giovaganzerli/g96cloud-dreeve --env nas
```

Gli ultimi due comandi chiedono i valori senza inserirli nel codice o nella
cronologia della shell. Nelle impostazioni dell'environment limitare i deployment
al branch `g96-custom`. Infine committare e pushare i file del workflow e degli
script su quel branch. Il primo deploy reale non è stato ancora eseguito.

Gli aggiornamenti futuri agli script nella repository richiedono anche la loro
reinstallazione amministrativa sul NAS: il workflow non può riscrivere gli script
root tramite la chiave limitata.

La chiave di deploy deve avere in `~g96admin/.ssh/authorized_keys` l'opzione:

```text
restrict,command="/usr/local/bin/dreeve-deploy-ssh" ssh-ed25519 ...
```

Gli script `bin/deploy-nas` e `bin/deploy-nas-ssh` vanno installati sul NAS,
di proprietà root e non scrivibili dall'utente SSH, rispettivamente come
`/usr/local/sbin/dreeve-deploy` e `/usr/local/bin/dreeve-deploy-ssh` (modo 755).
L'utente necessita di sudo non interattivo per il primo script; su questo NAS
`g96admin` dispone già di sudo non interattivo. Servono Python 3, gzip e flock.
La chiave dedicata non consente shell o forwarding, ma può distribuire immagini
con accesso ai dati applicativi: proteggere secrets, branch e workflow.

## Backup e recupero

Prima di cambiare versione vengono fermati app e daemon e copiati database
(compresi eventuali WAL/SHM), `.env` e Compose in `deploy-backups/<data>-<sha>/`,
accessibile solo a root. Il trasferimento e la build non interrompono il servizio;
il backup e il riavvio comportano una breve interruzione.
Gli altri servizi del NAS non vengono modificati. I backup non sono eliminati
automaticamente: monitorarne lo spazio e definire una retention.

Le migrazioni vengono eseguite dall'entrypoint dell'app. Il workflow attende la
salute di entrambi i container; in caso di errore dopo il cambio immagine non
effettua rollback automatico, perché il database potrebbe essere stato migrato.
Per recuperare: fermare app e daemon, conservare anche il database fallito,
ripristinare la directory `database` dal tar e il file `env` come `.env`, quindi
riavviare con il Compose remoto e verificare la salute. Le vecchie immagini restano
disponibili localmente. Non usare `docker compose down -v` o cancellare `data`.

Il workflow prepara Composer e gli asset geografici come il workflow Docker
esistente. Gli asset CSS/JS versionati sono inclusi nell'immagine senza ricompilarli.
Questo workflow non sostituisce i test CI delle pull request.
