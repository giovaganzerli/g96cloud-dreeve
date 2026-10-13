# Azioni locali dal dettaglio segmento

Un amministratore autenticato trova **Cerca attività** nel dettaglio di ogni segmento. Il comando cerca i passaggi nelle attività GPS archiviate, comprese attività Zepp, caricamenti da file e storico Strava. Le attività inserite dal modulo manuale sono escluse. La ricerca riutilizza il matcher locale e le firme geografiche, riguarda soltanto il segmento scelto e preserva i passaggi esistenti. Gli identificatori deterministici consentono di ripeterla senza duplicati.

Quando manca una geometria utilizzabile, compaiono anche **Genera traccia** e **Carica GPX**. Le geometrie già ricostruite nella cache storica contano come tracce disponibili.

La generazione utilizza gli indici originali dei passaggi archiviati e i flussi GPS/distanza. Quando gli indici non sono disponibili, usa gli orari dei passaggi e il flusso temporale, con una tolleranza di cinque secondi e la validazione della distanza già applicata dal ricostruttore. In assenza di dati sufficienti mostra un errore e consente il caricamento manuale; non inventa un percorso e non usa API esterne.

Il caricamento accetta GPX fino a 5 MB, con un singolo percorso continuo `trkseg` o `rte`, da 2 a 25.000 punti, anche senza orari. Verifica coordinate e lunghezza: rispetto alla distanza del segmento, accetta il rapporto 0,65–1,5 usato anche per la ricostruzione. Non accetta l’intera attività al posto del segmento se la lunghezza non corrisponde. I dati ufficiali del segmento, incluse distanza e pendenze, rimangono invariati.

I percorsi aggiunti sono salvati separatamente in `KeyValue`, chiave `g96.segmentGeometryOverrides.v1`, con provenienza `generated` o `gpx`. Le righe ufficiali `Segment` non vengono sovrascritte. Il sistema di mappe esistente usa immediatamente la geometria e la pagina mostra la provenienza. Anche la ricerca dal dettaglio attività e le successive importazioni automatiche utilizzano le nuove tracce. Le scansioni dei segmenti custom e la revisione delle scansioni dei segmenti importati vengono aggiornate coerentemente.

Le azioni usano il mutex dell’importazione e le transazioni del bus di comandi. I comandi JSON e il modulo multipart del GPX richiedono autenticazione admin e CSRF. La cache della pagina segmento distingue visitatori anonimi e amministratori; il salvataggio del percorso invalida le mappe e i dettagli interessati.

Verifica: su database copiato, con credenziali Strava disabilitate, generazione sia da indici sia da orari, rifiuto della generazione senza dati, upload multipart senza orari con namespace GPX, mappe aggiornate, pulsanti condizionali, ricerca ripetibile e metadati/passaggi ufficiali preservati. Importazione automatica ripetuta riconosce la traccia GPX senza duplicati. Parser verificato con tre formati validi e otto input non validi.

La creazione di nuovi segmenti da GPX nel popup admin resta una funzione successiva, separata da questa modifica.
