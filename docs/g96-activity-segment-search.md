# Ricerca locale dei segmenti dal dettaglio attività

Nel dettaglio di un’attività, un amministratore autenticato trova **Cerca segmenti**. Il pulsante apre il modulo di ricerca nello stile del pannello admin: **Cerca segmenti** avvia il riconoscimento, **Torna all’attività** riapre il dettaglio aggiornato.

La funzione è disponibile per attività importate da Strava o da file GPX/FIT/TCX, comprese quelle del connettore Zepp. Le attività create direttamente con il modulo manuale di Dreeve sono escluse sia dall’interfaccia sia dal comando. In assenza di GPS, tempo o distanza viene mostrato un errore.

La ricerca considera i segmenti custom con polilinea e quelli importati con polilinea ufficiale o geometria ricostruita nella cache locale. Non effettua chiamate alle API Strava. Riutilizza `CalculateImportedSegmentEfforts`, `RouteGrid`, `SegmentEffortMatcher`, la cache delle geometrie e il sistema di comandi, CSRF e messaggi del pannello admin.

Il comando `search-activity-segments` scansiona esclusivamente l’attività selezionata, anche se già scansionata. Preserva i passaggi ufficiali e custom esistenti; i nuovi passaggi hanno identificatori deterministici `segmentEffort-local-…`. Registra inoltre la scansione dei nuovi segmenti custom, evitando duplicati alla successiva importazione automatica. È possibile ripetere la ricerca dopo aver aggiunto un segmento.

Il mutex `IMPORT_DATA` impedisce una ricerca mentre è in corso un’importazione o un’altra ricerca. Le scritture del comando sono transazionali e invalidano le cache tramite gli eventi già presenti nel repository dei passaggi.

Questa modifica riguarda la prima funzione richiesta. I comandi della pagina segmento e la creazione da GPX nel pannello admin verranno implementati separatamente.
