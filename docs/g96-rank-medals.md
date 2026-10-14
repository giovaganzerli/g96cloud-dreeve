# Medaglie dei migliori tempi sui segmenti

I piazzamenti 1, 2 e 3 nelle righe dei segmenti del dettaglio attività mostrano medaglia e numero, con colori oro, argento e bronzo e sfondo tenue coordinato. Dal quarto posto rimane il riquadro numerico esistente.

Nella classifica del dettaglio segmento i soli numeri 1, 2 e 3 sono sostituiti da 🥇 🥈 🥉; gli altri piazzamenti rimangono numerici. Entrambe le viste riusano `html/segment/_rank.html.twig`, con descrizioni accessibili delle medaglie. La classifica, i tempi e il database non cambiano.

Solo template Twig, senza nuova build JavaScript/CSS. Palette statica sui riquadri dell’attività, usando dimensioni e spaziatura esistenti. Verificati lint Twig e rendering con i primi tre posti e posizioni successive; anteprima nel browser con dati di esempio. Distribuzione sul NAS dopo backup coerente, con verifica che attività, segmenti e passaggi rimangano invariati.
