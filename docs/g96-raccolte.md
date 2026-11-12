# Raccolte

Una Raccolta è un insieme di più tracce che condividono un'esperienza: un viaggio, una gara, un'avventura. Nella barra laterale, sotto **Segmenti**, compare la nuova sezione **Raccolte** con il contatore delle raccolte salvate.

La pagina `/collections` mostra tutte le raccolte contemporaneamente su una mappa e in un elenco. La mappa riporta in un unico tracciato tutti i percorsi di tutte le raccolte, disegnati con le impostazioni colore già presenti nelle impostazioni delle mappe.

L'elenco riprende i filtri della pagina delle attività: ricerca, tipo di sport, data, paese, dispositivo, distanza e dislivello e un gruppo "Altro" con descrizione, galleria e immagine in evidenza. I filtri operano sui dati aggregati della raccolta, non sulle singole attività: il tipo di sport, il paese e il dispositivo sono quelli delle attività che la compongono, mentre la data è quella della prima attività, la distanza e il dislivello sono quelli totali. Lo stato dei filtri viene scritto nell'URL, come nella pagina delle attività.

Le schede sono orizzontali e si susseguono una sotto l'altra: a sinistra l'immagine in evidenza, a destra il titolo e i metadati principali, cioè il periodo coperto, i paesi attraversati, la distanza totale, il dislivello totale, la durata e il numero di attività, seguiti dalla descrizione. La durata è espressa in giorni quando la raccolta copre più di una giornata, in ore e minuti altrimenti. La pagina di gestione nel pannello admin è raggiungibile dal pulsante **Gestisci** in alto a destra.

Il dettaglio `/collections/{collectionId}` mostra l'immagine in evidenza, il nome, la descrizione, la mappa con tutte le tracce dell'attività selezionate, le statistiche raggruppate per categoria con icona, la galleria fotografica e l'elenco delle attività con sport, data, distanza e dislivello.

## Contenuto di una raccolta

Ogni raccolta contiene:

- **Immagine in evidenza**: una sola immagine, caricata dal pannello admin tramite il componente di dropzone già usato per attrezzature e attività.
- **Nome**: obbligatorio.
- **Descrizione**: se lasciata vuota, viene generata automaticamente dall'AI al salvataggio, a partire dalle statistiche calcolate e dall'impronta delle tracce GPS registrate. Il comando **Genera descrizione con AI** rigenera la descrizione in qualsiasi momento, sovrascrivendo quella corrente.
- **Attività**: selezionate tra le attività già importate. Le attività si cercano per nome, sport o data; la ricerca viene eseguita dal server, quindi l'elenco resta leggero anche con molte attività.
- **Mappa**: con l'insieme di tutti i tracciati delle attività selezionate.
- **Galleria fotografica**: un album Immich collegato alla raccolta.

## Statistiche

Le statistiche sono calcolate a partire dalle attività della raccolta e si aggiornano da sole quando un'attività viene aggiunta o rimossa. Sono raggruppate per categoria, ognuna con la propria icona arancione:

- **Tempo**: tempo totale, tempo in movimento e tempo fermo, cioè il tempo trascorso senza muoversi.
- **Distanza**: distanza totale e distanza media per attività.
- **Dislivello**: dislivello positivo totale, altitudine massima, minima e media.
- **Velocità**: velocità media e velocità massima.
- **Tappe**: tappa più lunga, tappa con più dislivello e tappa più veloce. Le tre tappe sono cliccabili e portano all'attività corrispondente.
- **Meteo**: temperatura massima, minima e media.
- **Geografia**: paesi attraversati, principali città e principali vette.
- **Superfici**: distanza su asfalto e distanza su strade sterrate.

I valori sono calcolati nel sistema di unità configurato. Ogni statistica automatica può essere corretta a mano: la modifica viene salvata come valore sostitutivo e non viene più ricalcolata, anche se in seguito cambiano le attività. Il pannello admin permette inoltre di aggiungere statistiche proprie, per esempio costo del viaggio, notti in tenda o in albergo, con etichetta, valore, unità e categoria. L'ordine delle voci è libero: le righe si trascinano e l'ordine viene salvato con il resto della raccolta.

Le principali città sono ricavate dalla città di partenza delle attività, ricavata a sua volta con il reverse geocoding; quando non è disponibile si usa il prefisso del nome dell'attività. Le principali vette sono i punti più alti dei tracciati: il nome della vetta viene riconosciuto nel nome dell'attività, per esempio "Passo Giau e Laghi di Misurina" diventa "Passo Giau" e "Salita all'Etna" diventa "Etna". Le attività senza dislivello non vengono considerate vette.

Le distanze su asfalto e sterrato non sono stimate: derivano dai dati OpenStreetMap. L'analisi viene eseguita una volta per attività, durante la successiva importazione, interrogando l'API Overpass e confrontando i tracciati registrati con le ways della rete stradale. La classificazione usa i tag `surface`, `tracktype` e `highway`. Il risultato viene memorizzato e non richiede nuove chiamate esterne. L'analisi è disattivata se l'endpoint Overpass non è configurato nelle impostazioni; in quel caso le due statistiche restano a zero. Se il servizio non è raggiungibile l'importazione prosegue e l'analisi viene ripetuta al giro successivo.

## Galleria fotografica

La galleria mostra una selezione casuale di una decina di foto dell'album Immich collegato, che cambia a ogni apertura della raccolta, e un link per sfogliare l'intero album su Immich.

L'integrazione si configura nel pannello admin, in **Impostazioni > Integrazioni**, inserendo l'URL del server Immich e la API key. La chiave resta sul server: le foto sono servite da Dreeve attraverso un endpoint interno, che non espone mai la chiave ai visitatori. La lista degli album è letta da Immich al momento della compilazione del modulo.

## Comandi e persistenza

Le raccolte sono gestite con il sistema di comandi del pannello admin: `add-collection`, `update-collection`, `delete-collection` e `generate-collection-description`. Gli aggiornamenti e le eliminazioni sono transazionali e invalidano le cache delle pagine interessate. Le attività non vengono mai modificate o eliminate insieme alla raccolta.

I dati sono conservati in due nuove tabelle: `Collection` con nome, descrizione, immagine, attività, album collegato, valori sostitutivi delle statistiche, statistiche personalizzate e ordine di visualizzazione; `ActivitySurface` con la distanza su asfalto, su sterrato e non classificata di ogni attività.

La sezione admin introduce la gestione delle raccolte accanto a quella dei segmenti. La pagina pubblica e il dettaglio sono protetti dagli stessi controlli di accesso delle altre pagine, e la cache distingue i visitatori anonimi dagli amministratori.

## Dal dettaglio attività

Il dettaglio di un'attività che fa parte di una raccolta mostra la sezione **Raccolte**, con la copertina, il nome e il numero di attività di ogni raccolta in cui l'attività è inserita. Il nome è cliccabile e porta alla raccolta. Quando l'attività non fa parte di nessuna raccolta, la sezione non viene mostrata.
