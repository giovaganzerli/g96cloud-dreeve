# Ricalcolo dei dati dell'attività

Permette di ricalcolare distanza, dislivello, tempi e velocità media di un'attività
usando i dati calcolati dalla mappa e i dati del file caricato, al posto di quelli
rilevati dal dispositivo.

Accesso: dalla pagina dell'attività, menu **Azioni** → **Attività → Ricalcola i dati**.

## Come si usa

1. Nella pagina dell'attività: menu **Azioni → Attività → Ricalcola i dati**. Il
   ricalcolo parte subito e ricalcola tutto: distanza, dislivello, tempi, velocità
   media, personal best e segmenti.
2. Si apre la pagina di ricalcolo con due tabelle: la differenza tra i dati del
   dispositivo e quelli ricalcolati per l'attività, e la differenza per i dati
   derivati (personal best e segmenti).
3. Nella tabella dei dati dell'attività ogni riga ha una casella di spunta:
   quelle selezionate vengono applicate, le altre mantengono il valore del
   dispositivo. Per default sono selezionate tutte.
4. Nella tabella dei dati derivati la scelta è a gruppi: **Ricalcola i personal
   best** e **Ricalcola i segmenti**. Selezionare il gruppo ricalcola tutti i
   valori di quel gruppo, togliere la casella li lascia invariati.
5. **Conferma** applica la selezione, **Ricalcola** ricalcola di nuovo, **Torna
   all'attività** chiude la pagina.

Dopo la conferma, la voce del menu diventa **Ripristina i dati**: riporta
l'attività e tutti i dati derivati ai valori del dispositivo.

Nella pagina di un segmento c'è un menu **Azioni** con le tre azioni del
segmento: **Modifica**, **Ricalcola i dati** (genera la traccia locale e cerca
le attività) e **Apri in WindAhead**.

## Cosa viene ricalcolato

| Metrica | Sorgente |
| --- | --- |
| Distanza | Somma delle distanze geodetiche sulle coordinate GPS del tracciato |
| Dislivello | Quote del terreno di due modelli digitali di elevazione, mediati tra loro |
| Tempo in movimento | Timestamps del file, con riconoscimento delle pause in base allo sport |
| Tempo totale | Ultimo timestamp - primo timestamp del file |
| Velocità media | Distanza e tempo in movimento effettivi (ricalcolati quando disponibili) |

### Dislivello

Il dislivello viene calcolato su **due fonti** indipendenti e la media dei due
risultati viene usata:

* **Open-Meteo Elevation** (SRTM/Copernicus DEM), al massimo 100 punti per richiesta.
* **Terrain tiles** (Mapzen DEM, letti dalle immagini "terrarium" con il supporto GD),
  che mescolano SRTM, NED e altri dataset.

Un singolo modello digitale di elevazione è troppo rumoroso per essere affidabile
da solo: il dislivello risultante può essere anche del 25% più basso del valore reale.
La media delle due fonti mitiga il rumore.

La finestra di smoothing è espressa **in metri** (150 m) e non in campioni: con una
finestra in campioni il risultato dipenderebbe da quanto è fitto il tracciato, e
un tracciato campionato ogni 100 m verrebbe filtrato su chilometri, cancellando
le salite brevi. Con la finestra in metri il risultato è indipendente dalla
densità dei punti.

### Riconoscimento delle pause

Una pausa viene riconosciuta quando la velocità scende sotto la soglia dello sport
**e** dura almeno per la durata minima indicata. Una breve decelerazione, come
frenare davanti a un semaforo, non viene contata come pausa.

| Sport | Soglia | Pausa minima |
| --- | --- | --- |
| Strada (Ride, EBikeRide, VirtualRide, Velomobile) | 0,5 m/s | 20 s |
| Gravel (GravelRide) | 0,4 m/s | 20 s |
| MTB (MountainBikeRide, EMountainBikeRide) | 0,3 m/s | 15 s |
| Corsa (Run, VirtualRun) | 0,5 m/s | 15 s |
| Trail running (TrailRun) | 0,3 m/s | 15 s |
| Camminata (Walk) | 0,3 m/s | 20 s |
| Trekking (Hike) | 0,3 m/s | 30 s |
| Sport acquatici | 0,2 - 0,3 m/s | 20 s |
| Sport invernali | 0,3 m/s | 20 s |
| Skating | 0,3 m/s | 15 s |
| Racquet e paddle | 0,2 m/s | 15 s |
| Sport indoor | 0,2 m/s | 20 s |
| Sport di squadra | 0,2 m/s | 20 s |
| Handcycle, Wheelchair | 0,4 m/s | 20 s |
| Avventura outdoor (RockClimbing, Sail) | 0,3 m/s | 20 s |

Gli intervalli superiori a 60 secondi sono considerati interruzioni di
registrazione, non pause: il tempo è semplicemente sconosciuto.

La velocità usata per riconoscere le pause viene calcolata sul flusso di distanza
del dispositivo, che funziona anche per le attività indoor. Se il dispositivo non
fornisce la distanza, si usa quella calcolata dalle coordinate.

I parametri sono definiti in
`src/Domain/Activity/Recalculate/PauseDetectionParameters.php`.

### Lo sport usato

La pagina di ricalcolo **non permette di cambiare lo sport**: mostra quello
dell'attività, con la soglia e la durata minima applicate al riconoscimento delle
pause. Lo sport si cambia solo dalla pagina di modifica dell'attività, e il
ricalcolo usa sempre quello salvato sull'attività.

Di conseguenza, cambiando sport in "Modifica attività" e poi ricalcolando, i
personal best vengono ricalcolati con le distanze tipiche del nuovo sport (per
una corsa: 400 m, 1 km, 5 km, ... invece di 5 mi, 10 km, 20 km per la bicicletta).

## Anteprima e conferma

Il ricalcolo non viene applicato subito: il risultato resta in attesa
(`Activity.pendingRecalculation`) finché l'atleta non conferma la selezione.

## Dati derivati

Oltre alle metriche dell'attività, si possono ricalcolare anche i dati che ne
derivano:

| Opzione | Cosa ricalcola |
| --- | --- |
| **Personal best** | I tempi migliori per le distanze tipiche dello sport (1 km, 5 km, ...), ricalcolati dal flusso distanza/tempo del file. Le distanze dipendono dallo sport, quindi i personal best cambiano se lo sport selezionato è diverso da quello dell'attività. |
| **Segmenti** | I tempi dei passaggi sui segmenti noti, ricalcolati confrontando il tracciato dell'attività con la geometria di ogni segmento. I segmenti già associati all'attività **vengono mantenuti**: se un segmento non è più riconoscibile, il suo valore originale resta invariato. |

Nella tabella dei personal best la colonna "Ricalcolati" mostra "-" quando il tempo
ricalcolato è uguale a quello attuale: in quel caso non c'è nulla da scegliere.

## I dati originali non vengono persi

I valori ricalcolati sono salvati in una colonna separata
(`Activity.recalculatedMetrics`) e non sovrascrivono mai quelli del dispositivo:

* `getDistance()`, `getElevation()`, `getMovingTimeInSeconds()`,
  `getElapsedTimeInSeconds()` e `getAverageSpeed()` restituiscono il valore
  ricalcolato quando presente, altrimenti quello del dispositivo.
* I valori del dispositivo restano disponibili tramite `getDeviceDistance()`,
  `getDeviceElevation()`, `getDeviceMovingTimeInSeconds()`,
  `getDeviceElapsedTimeInSeconds()` e `getDeviceAverageSpeed()`.

I personal best e i segmenti vivono invece in tabelle dedicate. Quando vengono
ricalcolati, i valori originali vengono **memorizzati** (salvati nelle metriche
ricalcolate dell'attività) e il ripristino li reinserisce esattamente come erano.
Il backup viene fatto solo alla prima ricalcolazione: ricalcolare di nuovo non
sovrascrive i valori originali con quelli già ricalcolati.

I personal best sono letti con `findAllByActivity`, che restituisce tutte le righe
dell'attività: `findByActivity` restituisce solo quelle dove l'attività detiene il
record per quella distanza, e usarlo cancellerebbe i personal best delle attività
che non sono primarie.

## Note tecniche

* Il ricalcolo del dislivello richiede la connessione a `api.open-meteo.com` e ai
  terrain tiles pubblici. Le quote vengono richieste al massimo 300 punti per
  tracciato e i risultati sono messi in cache (chiave
  `g96.activityElevations.v2`), con una chiave derivata dal tracciato: un
  tracciato diverso viene ricalcolato, lo stesso tracciato no. Questo evita di
  superare il limite di richieste del servizio quando si previsualizza e poi si
  conferma.
* Il ricalcolo del dislivello richiede i dati GPS. Le attività senza coordinate
  non possono essere ricalcolate per distanza o dislivello.
