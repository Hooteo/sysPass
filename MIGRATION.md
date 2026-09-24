# Migrazione da un vecchio sysPass a questa immagine Docker

Guida passo-passo per spostare un'installazione sysPass esistente (server
vecchio, non containerizzato o comunque diverso da questo fork) su questa
immagine Docker, **senza rompere nulla e senza sorprese sul database**.

Se stai solo aggiornando un'istanza **già** su questa immagine (stesso
host, stessi volumi Docker), questa guida non ti serve: basta
`docker compose pull && docker compose up -d` - vedi il README, sezione
"Fastest path". Questa guida è per il salto da un vecchio server a
questo Docker.

## Il valore che non puoi sbagliare: `passwordSalt`

Prima di tutto: `passwordSalt` è la chiave di firma che sysPass usa per
link pubblici, token CSRF e sicurezza delle sessioni. **Non è una scelta
tua, va copiata identica dal vecchio server.** Se la sbagli:

- L'app parte comunque, il login funziona, sembra tutto a posto.
- Ma ogni link pubblico generato prima della migrazione smette di
  funzionare, silenziosamente, e alcune operazioni legate alle sessioni
  possono comportarsi in modo strano.

Non esiste un modo per "recuperarla" dopo: va presa dal vecchio
`config.xml` PRIMA di spegnere/smantellare il vecchio server.

## Prima di iniziare: fai un test a secco

Non fare il primo tentativo direttamente sull'istanza di produzione.
Il modo più sicuro di procedere:

1. Fai tutta la procedura sotto su un host/progetto Docker **diverso** da
   quello di produzione (anche lo stesso `srvdocker01`, ma con nomi di
   container/volumi diversi, es. `syspass-app_test` / `syspass-db-test`
   invece di quelli di produzione).
2. Verifica tutto con calma (login, ricerca account, un link pubblico
   vecchio, copia password).
3. Solo quando sei sicuro che tutto funziona, ripeti la stessa identica
   procedura sull'istanza reale - a quel punto è solo eseguire di nuovo
   comandi già testati, non più un esperimento.

Il vecchio server **non va toccato/spento** finché non hai verificato che
il nuovo funziona: tienilo acceso in parallelo come rete di sicurezza
finché non sei sicuro, poi spegnilo.

## Passo 1 - Inventario del vecchio server

Recupera questi valori dal vecchio `config.xml` (di solito dentro
`app/config/config.xml` dell'installazione vecchia). Se il vecchio
server è un container Docker:

```bash
docker exec <container-app-sorgente> grep -E "passwordSalt|databaseVersion|appVersion" /var/www/html/sysPass/app/config/config.xml
```

Altrimenti, direttamente sul filesystem del vecchio server:

```bash
grep -E "passwordSalt|databaseVersion|appVersion" config.xml
```

Solo tre valori, e basta un dump schema-only (Passo 2) per il resto -
**non** ti servono `dbHost`/`dbName`/`dbUser`/`dbPass` del vecchio
server: sul nuovo host scegli tu liberamente nome database/utente/
password in `.env` (Passo 3), non devono combaciare con niente del
vecchio server - a patto di scommentare una riga in
`docker-compose.yml` (vedi Passo 3). In particolare:

- `passwordSalt` - **l'unico valore che non puoi scegliere tu**, va
  copiato identico (vedi sopra).
- `databaseVersion` - la versione dello schema del vecchio database
  (es. `310.19042701`). Serve al nuovo sysPass per sapere da dove
  applicare gli aggiornamenti di schema mancanti (vedi Passo 5).

⚠️ **Se il valore che trovi assomiglia a `3211.22070201`** (o comunque
inizia con **4 cifre** prima del punto, non 3), **non copiarlo alla
lettera in `SYSPASS_DB_VERSION`.** Non è un numero di versione delle
migration di questo fork - è il timbro lasciato da un'installazione
wizard (`Installer::VERSION`), che rimane fisso a `3.2.11` da quando
esiste questo fork, indipendentemente da quante funzionalità del fork
(OTP, MFA) siano state aggiunte dopo. È **numericamente più alto** delle
versioni di aggiornamento interne di questo fork (`300.x`/`310.x`/
`320.x`), quindi se lo usi così com'è, l'auto-migrate penserà di essere
già aggiornato e **salterà in silenzio** gli aggiornamenti del fork
(l'abbiamo verificato: succede esattamente questo, l'OTP per gli account
sparisce dopo l'import). Usa invece:

```
SYSPASS_DB_VERSION=311.00000000
SYSPASS_APP_VERSION=311.00000000
```

`311` sta correttamente tra `310` (l'ultima migration "stock" tracciata
da questo fork) e `320` (le prime aggiunte del fork) - dice a sysPass
"sei più recente delle vecchie migration stock, ma non hai ancora nessuna
delle aggiunte di questo fork", che è la realtà per qualunque vecchio
server con questo timbro. Se invece il valore che trovi è un normale
`3xx.YYMMDDNN` a 3 cifre (es. `310.19042701`), usalo pure così com'è,
copiato alla lettera.

## Passo 2 - Backup del vecchio database

Se il vecchio server **è anche lui un container Docker** (caso tipico:
stai migrando da un vecchio deploy verso uno nuovo, sullo stesso host o
un altro), fai il dump da dentro il container sorgente, `docker exec`
verso l'esterno:

```bash
docker exec <container-db-sorgente> mysqldump -u<vecchio-root-user> -p'<vecchia-root-pass>' <nome-schema-syspass> > syspass-backup-$(date +%F).sql
```

Se invece il vecchio server è una macchina "normale" (non containerizzata),
lancia `mysqldump` direttamente lì:

```bash
mysqldump -u<vecchio-root-user> -p'<vecchia-root-pass>' <nome-schema-syspass> > syspass-backup-$(date +%F).sql
```

**Usa il nome dello schema, NON `--all-databases`.** `--all-databases`
dumpa e poi *sovrascrive* anche lo schema di sistema `mysql` (utenti,
permessi, e sì, anche la password di `root`) - importato su un'istanza
nuova che ha già il proprio `root`/DB user creati da `.env`
(`SYSPASS_DB_ROOT_PASS`/`SYSPASS_DB_USER`/`SYSPASS_DB_PASS`), rischi di
ritrovarti con la password di `root` del *vecchio* server e di perdere
l'accesso con quella nuova che avevi appena impostato.

Un dump del solo schema copia le tabelle ma non gli utenti/permessi MySQL
del vecchio server - va benissimo così: l'utente/permessi che ti servono
sul nuovo server sono già quelli creati da `.env` al primo avvio del
container `syspass-db` (Passo 4), non serve ricrearli.

L'unico effetto collaterale di un dump schema-only è che due view interne
di sysPass (`account_search_v`/`account_data_v`) portano con sé un
riferimento (`DEFINER`) a un utente MySQL che esisteva solo sul vecchio
server - **questo fork lo corregge da solo automaticamente all'avvio**
(vedi README, "Automatic fix for views with a stale DEFINER"), non serve
fare nulla di manuale.

Fai anche una copia del vecchio `config.xml`, non solo il dump (utile
per riprendere in mano i valori del Passo 1 in un secondo momento):

```bash
docker cp <container-app-sorgente>:/var/www/html/sysPass/app/config/config.xml ./config.xml.old
# oppure, se il vecchio server non è un container:
cp app/config/config.xml ./config.xml.old
```

## Passo 3 - Configurare `.env` sul nuovo host

```bash
cp .env.example .env
```

Compila:

```
SYSPASS_PASSWORD_SALT=<passwordSalt del vecchio config.xml, verbatim - l'unico valore non a tua scelta>
SYSPASS_DB_HOST=syspass-db
SYSPASS_DB_PORT=3306
SYSPASS_DB_ROOT_PASS=<una password NUOVA a tua scelta, per il volume MariaDB nuovo>
SYSPASS_DB_NAME=<nome a tua scelta, es. syspass>
SYSPASS_DB_USER=<utente a tua scelta, es. syspass>
SYSPASS_DB_PASS=<password a tua scelta>
SYSPASS_DB_VERSION=<databaseVersion del vecchio config.xml>
SYSPASS_APP_VERSION=<appVersion del vecchio config.xml>
SYSPASS_AUTO_MIGRATE=yes
```

`SYSPASS_DB_NAME`/`USER`/`PASS` sono valori tuoi, non devono combaciare
con quelli del vecchio server (vedi Passo 1). Perché il container
`syspass-db` crei quell'utente e gli conceda accesso in automatico,
apri `docker-compose.yml` e **scommenta** questa riga nel servizio
`syspass-db` (è commentata di default, serve solo per una migrazione):

```yaml
      - ./docker/db-grant-init.sh:/docker-entrypoint-initdb.d/db-grant-init.sh:ro
```

Senza scommentarla, quell'utente viene comunque creato ma **senza alcun
permesso reale** - dovresti poi concederglieli a mano (vedi Passo 4).

`SYSPASS_DB_VERSION`/`SYSPASS_APP_VERSION` dicono a sysPass **da dove
parte** il database che stai per importare, non dove deve arrivare -
all'avvio, l'auto-migrate (vedi Passo 5) porta automaticamente lo schema
fino all'ultima versione di questo fork, OTP/MFA incluso. Non serve più
calcolare a mano fino a che punto applicare gli aggiornamenti.

## Passo 4 - Importare il database

**L'ordine conta, e non è opzionale: il database va importato PRIMA che
l'app faccia anche solo una richiesta.** Con il percorso "migrazione"
(`SYSPASS_DB_VERSION` valorizzato), l'app **salta il wizard** e assume
che lo schema sia già lì - se parte contro un database ancora vuoto,
ogni query fallisce ("tabella non esiste") invece di installare nulla.
Non esiste un modo per far partire prima l'app e importare il dump
"sopra" dopo, con questo percorso.

### Se usi Portainer (stack)

Un solo "Deploy the stack" farebbe partire **entrambi** i servizi
insieme, e l'app proverebbe a rispondere alla prima richiesta (l'health
check, il tuo primo accesso dal browser...) prima che tu abbia il tempo
di importare il dump. Vai in due passaggi:

1. **Primo deploy: solo il database.** Nell'editor dello stack, commenta
   temporaneamente (o cancella) l'intero blocco del servizio `app` -
   lascia solo `syspass-db` (e le sezioni `volumes:`/rete in fondo al
   file). Deploy dello stack: parte solo il container del database.
2. **Importa il dump** (comandi sotto) usando la console del container
   `syspass-db` in Portainer, o `docker exec` via SSH se ci hai accesso.
3. **Secondo deploy: aggiungi l'app.** Torna nell'editor dello stack,
   rimetti/scommenta il blocco `app` che avevi tolto al passo 1, e fai
   di nuovo "Deploy the stack" (o "Update the stack") - questa volta
   l'app parte contro un database già popolato.

### Comandi (CLI o console del container, stesso risultato)

**Avvia solo il database, non ancora l'app** (equivalente CLI del punto
1 sopra):

```bash
docker compose up -d syspass-db
```

Aspetta qualche secondo che MariaDB finisca di inizializzarsi.

⚠️ **Crea prima lo schema vuoto - `mysql <nome-database> < dump.sql`
NON lo crea da solo.** `docker-compose.yml` non imposta apposta
`MYSQL_DATABASE` (vedi Passo 3), quindi sul container nuovo non esiste
ancora nessun database chiamato `syspass` - un `mysqldump` schema-only
(Passo 2) non contiene `CREATE DATABASE`, e il client `mysql` fa
comunque un `USE <nome-database>` implicito prima di eseguire il dump,
che fallisce con `ERROR 1049 (42000): Unknown database` se lo schema
non esiste ancora. Va creato (vuoto) come primo passo, separato:

```bash
docker exec <container-db-destinazione> mysql -uroot -p'<SYSPASS_DB_ROOT_PASS>' -e "CREATE DATABASE IF NOT EXISTS <nome-database>;"
```

Poi importa il dump **nel container di destinazione** (nome del
container nuovo, non quello sorgente del Passo 2):

```bash
docker exec -i <container-db-destinazione> mysql -uroot -p'<SYSPASS_DB_ROOT_PASS>' <nome-database> < syspass-backup-YYYY-MM-DD.sql
```

`<nome-database>` (in entrambi i comandi) è lo stesso valore che hai
messo in `SYSPASS_DB_NAME` al Passo 3 (es. `syspass`).

⚠️ **`-i`, non `-it`.** Con `-it` (TTY interattivo) l'input da file/pipe
fallisce con `the input device is not a TTY`. Serve `-i` da solo quando
reindirizzi un file o una pipe.

⚠️ **Attenzione alla password nel comando shell.** Se la tua password
contiene un punto esclamativo (`!`), bash lo interpreta come
"history expansion" anche dentro le virgolette doppie, e la password
finisce corrotta nel comando eseguito. Usa **virgolette singole**
attorno alla password (come sopra), oppure heredoc con virgolette singole
(`<<'SQL' ... SQL`) se stai eseguendo query multi-linea.

Se hai scommentato la riga del Passo 3, `SYSPASS_DB_USER` è già creato e
già autorizzato su `SYSPASS_DB_NAME` a questo punto (gira in automatico
la primissima volta che il volume del DB è vuoto, **prima** ancora che
tu importi il dump) - non devi creare o concedere nulla a mano, né prima
né dopo l'import.

Se invece non l'hai scommentata (o te ne sei accorto solo ora, a volume
già inizializzato - in quel caso scommentarla ora non serve più a
niente, gira solo su un volume vuoto), crealo a mano:

```bash
docker exec -it syspass-db mysql -uroot -p'<SYSPASS_DB_ROOT_PASS>' -e "
CREATE USER IF NOT EXISTS '<dbUser>'@'%' IDENTIFIED BY '<dbPass>';
GRANT ALL PRIVILEGES ON \`<dbName>\`.* TO '<dbUser>'@'%';
FLUSH PRIVILEGES;"
```

Se il `%` (qualsiasi host) non viene abbinato in modo affidabile sulla
tua rete Docker (caso raro), dai al container `app` un IP statico su una
rete dedicata (esempio commentato in fondo a `docker-compose.yml`).

## Passo 5 - Avviare l'app e lasciare che si aggiorni da sola

```bash
docker compose up -d app
```

(su Portainer, questo è il "secondo deploy" descritto al Passo 4 - basta
far ripartire/aggiornare lo stack con il blocco `app` incluso)

Da questo momento in poi **non serve più cliccare nulla a mano, e non
serve nemmeno riavviare il container**: già al primissimo avvio,
l'entrypoint applica in automatico qualunque aggiornamento di schema
mancante rispetto a `SYSPASS_DB_VERSION` (OTP per-account, MFA di login,
e qualunque altro aggiornamento futuro), esattamente come cliccare a
mano sulla schermata di conferma upgrade del browser, ma da solo.
Controlla che sia andato tutto bene:

```bash
docker compose logs app | grep Auto-migrate
```

Ti aspetti righe come:

```
Auto-migrate: checking whether a DB schema upgrade is pending...
Auto-migrate: upgrade needed, applying...
Auto-migrate: {"status":0,"description":"Application successfully updated",...}
```

Se invece vedi `Auto-migrate: no upgrade needed.` alla primissima
esecuzione, controlla che `SYSPASS_DB_VERSION` sia stato letto
correttamente (vedi Risoluzione problemi sotto) - potrebbe non aver
trovato nulla da aggiornare per il motivo sbagliato.

## Passo 6 - Verifica

Checklist prima di considerare la migrazione riuscita:

- [ ] Apri il sito: **non** deve comparire l'installer (vedi sotto se
      succede), deve andare dritto alla pagina di login.
- [ ] Login con un account esistente del vecchio sysPass, con la sua
      vecchia password.
- [ ] Apri un link pubblico generato **prima** della migrazione e
      controlla che risolva ancora - questa è la vera prova che
      `passwordSalt` è stato copiato correttamente.
- [ ] Copia la password di un account qualsiasi - conferma che la
      cifratura delle password funziona.
- [ ] Se il vecchio sysPass aveva già account con OTP configurato,
      controlla che il codice OTP venga ancora generato correttamente.
- [ ] `docker compose logs app` non deve avere `Access denied for user`
      (problema di permessi DB, torna al Passo 4) né errori ripetuti a
      raffica (qualche `Context not initialized` isolato all'avvio è
      normale e si auto-risolve, legato alla migrazione del formato di
      `config.xml` stesso).

Solo dopo aver spuntato tutta la lista, ripeti la procedura
sull'istanza di produzione reale (se questo era un test), oppure
considera la migrazione conclusa.

**Posso togliere `SYSPASS_DB_VERSION`/`SYSPASS_APP_VERSION` (e le altre
variabili di questo tipo) dallo stack ora che la migrazione è andata?**
Sì - vengono lette **solo** la primissima volta che `config.xml` non
esiste ancora. Una volta creato/aggiornato (cosa già avvenuta se sei
arrivato fin qui), le variabili d'ambiente vengono ignorate a ogni
riavvio successivo, `SYSPASS_AUTO_MIGRATE` a parte (quella è l'unica
riletta a ogni boot). L'unica cautela: se in futuro cancelli di nuovo il
volume `syspass-config` per qualche motivo, senza `SYSPASS_DB_VERSION`
l'app tornerebbe al wizard invece che al percorso "migrazione" - se
pensi possa ricapitare, non c'è danno a lasciarle nello stack anche da
inutilizzate.

## Piano B: qualcosa è andato storto

Il vantaggio di non aver mai toccato il vecchio server: puoi sempre
tornare indietro senza perdite.

- **Se il problema è nel nuovo database** (import fallito, permessi
  sbagliati, schema strano): `docker compose down -v` per buttare via i
  volumi del tentativo fallito (⚠️ questo cancella SOLO i volumi della
  nuova istanza Docker, il vecchio server resta intatto) e ricomincia dal
  Passo 4 con un dump fresco.
- **Se il problema è nella configurazione** (`passwordSalt` sbagliato,
  variabili d'ambiente sbagliate): cancella il volume `syspass-config`
  (`docker volume rm <progetto>_syspass-config`), correggi `.env`, e
  riparti - `config.xml` verrà rigenerato da zero al prossimo avvio.
- **Il vecchio server resta la fonte di verità finché non hai finito la
  checklist del Passo 6.** Nessuna fretta a spegnerlo.

## Risoluzione problemi comuni

**Vedo l'installer invece della pagina di login.**
`SYSPASS_DB_VERSION`/`SYSPASS_APP_VERSION` non sono stati letti, oppure
`app/config/config.xml` esiste già con valori vecchi/vuoti da un
tentativo precedente. Controlla `.env`, e se necessario cancella il
volume `syspass-config` e riparti da un `.env` pulito.

**`ERROR 1045: Access denied for user 'root'@'localhost' (using password: YES)`**
durante l'import del dump. Di solito è uno di questi due problemi:
- Stai usando `-it` invece di `-i` con l'input reindirizzato da file (il
  comando fallisce ancora prima di arrivare a MySQL).
- La password contiene caratteri speciali (es. `!`) e non è tra
  virgolette singole.

**`ERROR 1049 (42000): Unknown database '<nome>'`** durante l'import del
dump. Non hai creato lo schema vuoto prima (vedi Passo 4) - questo fork
non imposta `MYSQL_DATABASE` di proposito, quindi nessun database esiste
finché non lo crei tu con `CREATE DATABASE IF NOT EXISTS <nome>;` prima
di importare il dump sopra.

**`SQLSTATE[HY000] [1044] Access denied for user '...'@'...' to database
'...'`** (nei log di `fix-view-security`, o dell'app in generale) - **non
è lo stesso errore del 1045** ("using password"): qui l'utente esiste e
la password è giusta, semplicemente non ha alcun permesso su quel
database. Capita quando riusi un volume `syspass-db` **già
inizializzato** da un tentativo precedente (stesso utente, magari
riportato da una prima installazione andata storta) - `db-grant-init.sh`
(Passo 3/4) gira **solo la primissima volta** che il volume è vuoto,
quindi su un volume riciclato non scatta più e quell'utente resta senza
permessi. Concedili a mano, senza `CREATE USER` (l'utente c'è già):

```bash
docker exec -it <container-db> mysql -uroot -p'<password-root>' -e "
GRANT ALL PRIVILEGES ON \`<nome-database>\`.* TO '<utente>'@'%';
FLUSH PRIVILEGES;"
```

Poi riavvia il container dell'app.

**Ho importato il vecchio dump ma sysPass dice che il database non ha
alcune colonne/tabelle che mi aspettavo (es. OTP, MFA).**
Normale se il vecchio server non aveva ancora queste funzionalità -
`SYSPASS_DB_VERSION` gliel'ha detto e l'auto-migrate (Passo 5) le crea da
solo al primo avvio. Controlla i log come indicato sopra; se dicono `no
upgrade needed` ma le tabelle mancano davvero, il problema è a monte
(versione letta male), non nell'auto-migrate stesso.

**Login funziona, ma la ricerca account o la visualizzazione password
danno `Access denied for user '...'@'...' (using password: YES)`.**
Prima di questo fork era un problema reale (view interne con `DEFINER`
puntato a un utente del vecchio server, non copiato da un dump
schema-only) - ora è corretto in automatico a ogni avvio del container,
vedi README "Automatic fix for views with a stale DEFINER". Se lo vedi
comunque, controlla `docker compose logs app | grep fix-view-security`:
se dice `failed on <nome-view>`, il DB user configurato non ha i permessi
`CREATE VIEW`/`DROP` sul proprio schema - probabilmente non hai
scommentato la riga del Passo 3/4, o l'hai scommentata dopo che il
volume del DB era già inizializzato (in quel caso va concesso a mano,
vedi Passo 4).

**Nei log vedo `fix-view-security: could not connect (...), skipping.`**
Normale e innocuo se capita solo al primissimo avvio (il DB può metterci
qualche secondo in più a essere pronto quando app e DB partono insieme
da zero) - lo script riprova per fino a 30 secondi prima di arrendersi.
Se lo vedi a ogni riavvio, controlla che `SYSPASS_DB_HOST`/`PORT` in
`.env` puntino davvero al servizio giusto.

**Un link pubblico vecchio non funziona più dopo la migrazione.**
`passwordSalt` non è stato copiato identico. Non c'è modo di
"aggiustarlo" a posteriori sui link già generati - vanno rigenerati.
Verifica che il valore in `.env`/`config.xml` nuovo sia carattere per
carattere identico a quello del vecchio `config.xml`.
