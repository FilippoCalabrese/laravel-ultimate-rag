# Analisi dei Requisiti — RAG Engine (Pacchetto Laravel)

> Motore di Retrieval-Augmented Generation, infrastruttura core per i pacchetti verticali Sellinnate.
> Stato: **Revisione consolidata** · Versione: **0.2** · Postura: **Enterprise / prodotto-infrastruttura**

---

## 0. Changelog v0.1 → v0.2

Decisioni recepite dopo il confronto: postura **enterprise**, performance eccezionali, sicurezza altissima, scaling su grandi volumi, **tutti i formati**, **UE-cloud accettabile**, **BYOK richiesto da subito**.

| Area | v0.1 | v0.2 | Motivo |
|---|---|---|---|
| **Postura** | MVP iterativo | Prodotto-infrastruttura enterprise | Requisito esplicito. |
| **Vector store default** | pgvector | **Qdrant** primario (quantizzazione + partizionamento); pgvector resta per tenant piccoli/on-prem | Scaling su grandi volumi. |
| **Multi-tenancy default** | Row-level | **Namespace/collection per tenant**; schema/DB dedicato come opzione | Sicurezza altissima. |
| **Embedding default** | OpenAI cloud | **EU-resident** (Mistral/Jina, o Azure OpenAI regione UE); self-hosted come opzione sovranità massima | Data residency + costo a volume. |
| **PII redaction** | Opzionale | **ON di default** | Sicurezza/GDPR. |
| **Parser MVP** | Set ristretto | **Copertura ampia in Fase 1** | "Tutti i formati possibili". |
| **BYOK / cifratura** | Non previsto | **Nuova area `FR-SEC`, in Fase 1** (envelope encryption, KMS, crypto-shredding) | Requisito esplicito. |
| **Audit log** | Should | **Immutabile, Must** | Evidenza ISO/SOC2. |
| **Retrieval quality** | Hybrid/rerank/MMR/parent-child = Should | **Tutti Must** | "Performance eccezionali" = qualità retrieval. |
| **Air-gapped** | Implicito tra gli on-prem | **Non più baseline**: profilo opzionale (UE-cloud è il default) | Risposta "UE-cloud accettabile". |
| **DR & scaling** | Accennati | **NFR dedicati**: read-scaling, backpressure, DR con consistenza vettori↔metadati | Scaling su grandi volumi. |

**Confine onesto da tenere a mente.** BYOK copre contenuto, chunk e metadati; **i vettori** non sono cifrabili con la chiave del tenant perché la ricerca ANN richiede float in chiaro — sono protetti da encryption-at-rest con chiave KMS, e isolabili nel perimetro del tenant dove serve isolamento assoluto.

---

## 1. Visione e posizionamento

Il pacchetto è **infrastruttura**, non una feature: lo strato di conoscenza su cui i pacchetti verticali, gli agenti interni e i moduli di ricerca costruiscono funzionalità di dominio senza reimplementare ingestion, chunking, embedding e retrieval.

Cinque principi guida:

1. **Domain-agnostic.** Primitive generiche (documenti, chunk, query, risultati). La logica di dominio vive nei consumatori.
2. **Contract-first.** Ogni componente sostituibile è dietro interfaccia stabile. I consumatori dipendono dai contratti, mai dalle implementazioni.
3. **Async-first.** Ingestion su coda; retrieval sincrono e a bassa latenza. Separazione strutturale.
4. **Multi-tenant & secure by design.** Isolamento per tenant e cifratura BYOK sono di prima classe, non aggiunte successive.
5. **EU-resident by default.** Dati ed embedding restano in UE salvo scelta esplicita diversa del consumatore.

**Confine di scope (invariato e cruciale):** il motore possiede l'intera pipeline *ingestion → retrieval*. La **generation** è uno strato **opzionale e disaccoppiato** (dipendenze LLM opzionali): chi fa sola ricerca semantica non si porta dietro LLM; chi fa RAG completo usa prompt di dominio propri.

**Avvertenza di realismo.** Questa postura non descrive un MVP. È un prodotto-infrastruttura con costo di build e complessità operativa significativi. La strategia di mitigazione è architetturale: le *cuciture* enterprise (contratti, isolamento, BYOK, observability) entrano subito perché non si retrofittano; le *ottimizzazioni estreme* di scala (quantizzazione spinta, sharding orizzontale) si attivano quando il volume reale le richiede. Seam da subito, implementazione progressiva.

---

## 2. Ambito (scope)

### 2.1 In scope
- Gestione sorgenti e ingestion (upload, URL, testo grezzo, record Eloquent, storage cloud)
- Parsing ed estrazione **multi-formato ampia** (PDF testuali e scansionati/OCR, Office, OpenDocument, RTF, HTML/XML, CSV/TSV/JSON, e-mail, immagini)
- Preprocessing, normalizzazione, rilevamento lingua, **redazione PII ON di default**
- Chunking con strategie multiple e pluggabili, parent-child, contextual headers
- Embedding multi-provider **EU-resident di default**, con caching e tracking costi
- Vector store multi-backend (**Qdrant primario**, pgvector, in-memory) con filtri su metadati, quantizzazione, partizionamento
- Retrieval: vector + **hybrid (RRF)**, **MMR**, soglie, top-k
- **Reranking** e compressione contestuale
- Query transformation (multi-query, HyDE, step-back)
- Strato di generation opzionale (contesto, citazioni, streaming)
- Orchestrazione async (job, batch, eventi, stato, backpressure)
- **Sicurezza: BYOK / envelope encryption / crypto-shredding / KMS abstraction**
- Multi-tenancy con livelli di isolamento
- **Audit log immutabile**, observability, cost tracking per tenant
- DX: facade, config, comandi Artisan, trait Eloquent, fake per i test

### 2.2 Fuori scope (delegato ai consumatori o all'host)
- Logica di business e regole di dominio
- Interfaccia utente (motore headless)
- Prompt di dominio e personalità degli agenti
- Autenticazione/autorizzazione utenti (il motore espone **hook**, non implementa policy)
- Billing verso il cliente finale (il motore traccia consumi/costi; la monetizzazione è del consumatore)
- Fine-tuning di modelli
- Gestione del ciclo di vita delle chiavi del cliente **al di fuori** dell'integrazione KMS (la custodia resta nel KMS del tenant)

---

## 3. Principi architetturali

| Principio | Implicazione concreta |
|---|---|
| Contract-first | Namespace `Contracts/` con interfacce per ogni componente sostituibile, incluso `KeyManagement`. |
| Driver Manager pattern | Manager alla Laravel per risolvere driver da config a runtime. |
| Async by default | Ingestion su `Bus::batch`; retrieval sincrono. Idempotenza obbligatoria. |
| Secure & tenant-aware | `tenant_id` ovunque + scoping automatico; contenuto cifrato BYOK; isolamento configurabile. |
| EU-resident by default | Provider e storage in regione UE salvo override esplicito. |
| Crypto-shredding ready | Cancellazione = revoca chiave; nessun dato in chiaro persiste ai derivati. |
| Observability built-in | Costi, token, latenza, tracing e audit emessi fin dal primo giorno. |
| Fail gracefully | Retry, backoff, circuit breaker con failover provider; backpressure sull'ingestion. |
| Config-cache safe | Nessuna closure nei config (compatibilità `config:cache`). |
| Zero lock-in | Default sensati ma tutto sostituibile senza toccare i consumatori. |

---

## 4. Requisiti funzionali

> ID: `FR-<AREA>-<NN>`. Priorità: **M** = Must (Fase 1), **S** = Should, **C** = Could.

### 4.1 Gestione sorgenti e ingestion — `FR-IN`

| ID | Requisito | Pri |
|---|---|---|
| FR-IN-01 | Ingestione da **upload file** (singolo e batch), limiti dimensione configurabili. | M |
| FR-IN-02 | Ingestione da **testo grezzo**. | M |
| FR-IN-03 | Ingestione da **URL** (fetch + parsing). | M |
| FR-IN-04 | Ingestione da **record Eloquent**. | M |
| FR-IN-05 | Ingestione da **storage cloud** astratto (S3, R2, locale). | M |
| FR-IN-06 | **Deduplica** su hash contenuto; re-ingestione idempotente. | M |
| FR-IN-07 | **Provenance**: sorgente, timestamp, checksum, mime, dimensione. | M |
| FR-IN-08 | **Versionamento documenti** con sostituzione atomica dei chunk. | M |
| FR-IN-09 | **Metadati arbitrari** tipizzati, propagati ai chunk. | M |
| FR-IN-10 | **Soft-delete + purge** schedulabile (allineato a crypto-shredding, §4.15). | M |

### 4.2 Parsing ed estrazione — `FR-PA`  *(copertura ampia)*

| ID | Requisito | Pri |
|---|---|---|
| FR-PA-01 | **PDF testuali**. | M |
| FR-PA-02 | **PDF scansionati / immagini** via **OCR** (driver pluggabile, OCR EU/self-host). | M |
| FR-PA-03 | **DOCX, PPTX, XLSX** (OOXML). | M |
| FR-PA-04 | **DOC/PPT/XLS legacy** e **OpenDocument (ODT/ODS/ODP)**. | S |
| FR-PA-05 | **RTF, TXT, Markdown**. | M |
| FR-PA-06 | **HTML, XML** con bonifica markup e difese XXE. | M |
| FR-PA-07 | **CSV/TSV/JSON** con preservazione struttura tabellare. | M |
| FR-PA-08 | **E-mail (EML/MSG)** con allegati ricorsivi. | S |
| FR-PA-09 | **Estrazione tabelle** strutturate (no flattening distruttivo). | M |
| FR-PA-10 | **Preservazione struttura** logica (heading, sezioni, gerarchia, pagine). | M |
| FR-PA-11 | **Rilevamento lingua** per documento e per chunk. | M |
| FR-PA-12 | Estrazione **metadati nativi** (autore, date, titolo). | S |
| FR-PA-13 | **Parser driver** registrabile dall'esterno per nuovi formati (l'architettura rende "tutti i formati" un fatto estensibile, non un elenco chiuso). | M |

> Nota: "tutti i formati possibili" si traduce, in pratica, in una **copertura core ampia in Fase 1** + un'architettura a parser driver che rende ogni nuovo formato un'aggiunta isolata, senza toccare il resto della pipeline.

### 4.3 Preprocessing e normalizzazione — `FR-PP`

| ID | Requisito | Pri |
|---|---|---|
| FR-PP-01 | **Pulizia testo**: whitespace, encoding UTF-8, artefatti di estrazione. | M |
| FR-PP-02 | **Normalizzazione** opzionale non distruttiva (unicode NFC, casing). | S |
| FR-PP-03 | **Rilevamento + redazione PII ON di default** (e-mail, telefoni, IBAN, CF/P.IVA, carte), policy per tenant, con possibilità di tokenizzazione reversibile lato consumatore. | M |
| FR-PP-04 | **Pipeline di preprocessing** componibile (stage attivabili da config). | M |
| FR-PP-05 | Gestione **lingua-specifica** (IT/DE/EN prioritarie). | S |

### 4.4 Chunking — `FR-CH`

| ID | Requisito | Pri |
|---|---|---|
| FR-CH-01 | **Fixed-size** con dimensione e overlap configurabili. | M |
| FR-CH-02 | **Recursive character** (split gerarchico per separatori). | M |
| FR-CH-03 | **Sentence/paragraph-based**. | M |
| FR-CH-04 | **Markdown/struttura-aware**. | M |
| FR-CH-05 | **Semantic chunking** (boundary su salto di similarità). | S |
| FR-CH-06 | **Token-aware**: confini sui limiti token del modello, non solo caratteri. | M |
| FR-CH-07 | **Parent-child / small-to-big**: chunk piccoli per retrieval, contesto allargato per generation. | M |
| FR-CH-08 | **Contextual chunk headers**: arricchimento con contesto documento/sezione pre-embedding. | M |
| FR-CH-09 | **Propagazione metadati** documento → chunk + metadati di chunk (pagina, offset, indice). | M |
| FR-CH-10 | **Chunker driver** pluggabile. | M |

### 4.5 Embedding — `FR-EM`  *(EU-resident di default)*

| ID | Requisito | Pri |
|---|---|---|
| FR-EM-01 | Provider **EU-resident cloud** di default (es. Mistral `mistral-embed`, Jina, o Azure OpenAI in regione UE). | M |
| FR-EM-02 | Provider **self-hosted/locale** (Ollama o servizio open: BGE/E5/Nomic) per sovranità massima e costo a volume. | M |
| FR-EM-03 | Provider extra-UE (OpenAI, Cohere, Voyage) **opzionali**, solo su scelta esplicita del consumatore. | S |
| FR-EM-04 | **Batch processing** con dimensione configurabile. | M |
| FR-EM-05 | **Cache embedding** (chiave = hash testo + modello + dimensione). | M |
| FR-EM-06 | **Rate limiting + retry/backoff**. | M |
| FR-EM-07 | **Tracking token e costo** per operazione, aggregabile per tenant. | M |
| FR-EM-08 | **Versionamento modello** (documento sa con quale modello/dimensione è vettorializzato). | M |
| FR-EM-09 | **Re-embedding / migrazione vettori** su cambio modello, batch schedulabile senza downtime di ricerca. | M |
| FR-EM-10 | Validazione **dimensionalità** vettore ↔ indice. | M |

### 4.6 Vector store e indicizzazione — `FR-VS`  *(Qdrant primario)*

| ID | Requisito | Pri |
|---|---|---|
| FR-VS-01 | Driver **Qdrant** primario (self-hostable in UE). | M |
| FR-VS-02 | Driver **pgvector** per tenant piccoli/on-prem (coerente con stack Neon). | M |
| FR-VS-03 | Driver **in-memory** per i test (deterministico). | M |
| FR-VS-04 | Driver aggiuntivi (Weaviate, Milvus) pluggabili. | C |
| FR-VS-05 | **Quantizzazione vettoriale** (scalar/binary) per tenere grandi corpora in RAM. | S |
| FR-VS-06 | **Partizionamento/sharding** per namespace/tenant. | S |
| FR-VS-07 | Gestione **indici** (HNSW/IVF) con parametri esposti e rebuild. | M |
| FR-VS-08 | **Filtri su metadati** combinabili con la ricerca (pre/post-filter). | M |
| FR-VS-09 | **Namespace/collection** per separare corpora e tenant. | M |
| FR-VS-10 | **Isolamento multi-tenant** a livello store. | M |
| FR-VS-11 | Metriche distanza configurabili (cosine, dot, L2). | M |
| FR-VS-12 | Upsert/delete **idempotenti**. | M |

### 4.7 Retrieval e ricerca — `FR-RT`

| ID | Requisito | Pri |
|---|---|---|
| FR-RT-01 | **Vector similarity search**, top-k configurabile. | M |
| FR-RT-02 | **Soglia di score** minima. | M |
| FR-RT-03 | **Hybrid search** vettoriale + keyword/BM25 con **RRF**. | M |
| FR-RT-04 | **Filtri su metadati** (tenant, namespace, tag, data, sorgente). | M |
| FR-RT-05 | **MMR** per diversificazione. | M |
| FR-RT-06 | **Score e provenienza** con ogni risultato (chunk, documento, posizione). | M |
| FR-RT-07 | **Contesto allargato** in modalità parent-child. | M |
| FR-RT-08 | **API di ricerca fluente** che astrae il driver. | M |

### 4.8 Reranking e post-processing — `FR-RR`

| ID | Requisito | Pri |
|---|---|---|
| FR-RR-01 | **Reranking** con cross-encoder/servizio di rerank (driver pluggabile, opzione EU). | M |
| FR-RR-02 | **Contextual compression** dei chunk recuperati pre-generation. | S |
| FR-RR-03 | **Deduplica risultati** a livello contenuto. | M |
| FR-RR-04 | **Budget di contesto** token-aware. | M |

### 4.9 Query transformation — `FR-QT`

| ID | Requisito | Pri |
|---|---|---|
| FR-QT-01 | **Multi-query** (espansione + retrieval unito). | S |
| FR-QT-02 | **HyDE**. | C |
| FR-QT-03 | **Step-back prompting**. | C |
| FR-QT-04 | **Query transformer driver** pluggabile (richiede LLM, opzionale). | S |

### 4.10 Strato di generation (opzionale) — `FR-GE`

| ID | Requisito | Pri |
|---|---|---|
| FR-GE-01 | **Assemblaggio contesto + prompt** con template del consumatore. | S |
| FR-GE-02 | **Integrazione LLM** multi-provider (EU-resident di default). | S |
| FR-GE-03 | **Citazioni/attribuzione fonti** collegate ai chunk. | S |
| FR-GE-04 | **Streaming** della risposta. | C |
| FR-GE-05 | Lo strato è **isolato**: la sua assenza non impatta ingestion/retrieval. | M |

### 4.11 Pipeline e orchestrazione — `FR-OR`

| ID | Requisito | Pri |
|---|---|---|
| FR-OR-01 | Ingestion come **catena di job** (`Bus::batch`). | M |
| FR-OR-02 | **Tracciamento stato** (pending → parsing → chunking → embedding → indexed → failed). | M |
| FR-OR-03 | **Reprocessing/re-indexing** on-demand di documento o corpus. | M |
| FR-OR-04 | **Batch operations** con avanzamento e callback. | M |
| FR-OR-05 | **Dead-letter handling** ispezionabile/ritentabile. | M |
| FR-OR-06 | **Backpressure** sull'ingestion ad alto volume (rate control verso provider e DB). | M |
| FR-OR-07 | **Webhook/eventi** di avanzamento e completamento. | S |
| FR-OR-08 | Pipeline su `Illuminate\Pipeline` (stage inseribili/rimovibili). | S |

### 4.12 Multi-tenancy — `FR-MT`

| ID | Requisito | Pri |
|---|---|---|
| FR-MT-01 | `tenant_id` su ogni documento, chunk, vettore, operazione. | M |
| FR-MT-02 | **Scoping automatico** delle query al tenant corrente. | M |
| FR-MT-03 | Isolamento **a livelli**: **namespace/collection per tenant di default**, schema/DB dedicato come opzione enterprise. | M |
| FR-MT-04 | **Quote e limiti per tenant** (documenti, dimensione corpus, budget embedding) con enforcement. | M |
| FR-MT-05 | Aggregazione **consumi/costi per tenant** per billing. | M |

### 4.13 Developer Experience / API pubblica — `FR-DX`

| ID | Requisito | Pri |
|---|---|---|
| FR-DX-01 | **Facade** (ingest, search, ask). | M |
| FR-DX-02 | **Config pubblicabile** con default sensati e override granulare. | M |
| FR-DX-03 | **Migrazioni pubblicabili** per le tabelle core. | M |
| FR-DX-04 | **Trait Eloquent** in stile Scout `Searchable`. | S |
| FR-DX-05 | **Comandi Artisan**: ingest, reindex, purge, status, stats, clear-cache, **rotate-keys**. | M |
| FR-DX-06 | **Fluent builder** per query di retrieval (filtri, top-k, rerank, soglie). | M |
| FR-DX-07 | **API stabile e SemVer**: i contratti pubblici non cambiano senza major. | M |

### 4.14 Eventi ed estensibilità — `FR-EV`

| ID | Requisito | Pri |
|---|---|---|
| FR-EV-01 | Eventi ciclo di vita: `DocumentIngested`, `DocumentChunked`, `ChunksEmbedded`, `DocumentIndexed`, `SearchPerformed`, `IngestionFailed`, **`KeyRotated`**, **`DataShredded`**. | M |
| FR-EV-02 | Componenti chiave **`Macroable`** / estendibili via `extend()`. | S |
| FR-EV-03 | **Hook di autorizzazione** prima di ingestion e retrieval (demandati al consumatore). | M |
| FR-EV-04 | **Driver custom** (parser, chunker, embedder, store, reranker, KMS) registrabili dall'esterno senza fork. | M |

### 4.15 Sicurezza, cifratura e gestione chiavi (BYOK) — `FR-SEC`  *(nuova area, Fase 1)*

| ID | Requisito | Pri |
|---|---|---|
| FR-SEC-01 | **Envelope encryption**: DEK per-tenant (o per-documento) cifra contenuto sorgente, testo chunk e metadati sensibili; la DEK è wrappata dalla **KEK del tenant** nel suo KMS. | M |
| FR-SEC-02 | **KMS abstraction** dietro contratto, con driver: **AWS KMS, GCP KMS, Azure Key Vault, HashiCorp Vault** + driver locale per dev/test. | M |
| FR-SEC-03 | **BYOK**: il tenant fornisce/controlla la KEK; il motore non la detiene mai in chiaro a riposo. | M |
| FR-SEC-04 | **Crypto-shredding**: la cancellazione di un tenant/documento avviene revocando/distruggendo la chiave, rendendo i derivati irrecuperabili (incluso nei backup) — risposta forte al diritto all'oblio GDPR. | M |
| FR-SEC-05 | **Key rotation** della KEK senza re-ingestione (re-wrap delle DEK). | S |
| FR-SEC-06 | **Confine vettori dichiarato**: i vettori non sono cifrati BYOK (la ANN richiede float in chiaro); sono protetti da **encryption-at-rest** con chiave KMS-backed, e il vector store è **collocabile nel perimetro del tenant** dove serve isolamento assoluto. | M |
| FR-SEC-07 | **Caching cifrato**: anche le cache (embedding, contenuto) rispettano la cifratura; nessun dato sensibile in chiaro in cache condivise. | M |
| FR-SEC-08 | **Sanitizzazione input di parsing**: difese da file malevoli, XXE, zip-bomb, path traversal negli allegati. | M |

---

## 5. Requisiti non funzionali

> ID: `NFR-<AREA>-<NN>`.

### 5.1 Performance — `NFR-PE`
- **NFR-PE-01** Latenza retrieval (vector + filtri, esclusa generation) target **p95 < 200 ms** su corpus fino a ~10M chunk con Qdrant/HNSW + quantizzazione.
- **NFR-PE-02** Latenza retrieval con reranking target **p95 < 500 ms** su top-k moderato.
- **NFR-PE-03** Throughput ingestion scalabile orizzontalmente aggiungendo worker, senza modifiche al codice.
- **NFR-PE-04** Caching obbligatorio per embedding ripetuti; cache query opzionale.
- **NFR-PE-05** Batch efficiente verso i provider di embedding.
- **NFR-PE-06** Connection pooling/riuso verso DB e vector store (compatibile con Hyperdrive davanti a Postgres).

### 5.2 Scalabilità — `NFR-SC`
- **NFR-SC-01** Ingestion orizzontalmente scalabile via worker stateless.
- **NFR-SC-02** **Read-scaling** del retrieval (repliche di lettura del vector store, routing delle query).
- **NFR-SC-03** Corpora di grandi dimensioni con ANN, quantizzazione e parametri tunabili.
- **NFR-SC-04** **Sharding/partizionamento** per namespace/tenant senza degrado al crescere dei tenant.
- **NFR-SC-05** Nessuno stato in memoria condiviso tra richieste.

### 5.3 Affidabilità e resilienza — `NFR-AF`
- **NFR-AF-01** Retry con backoff esponenziale + jitter su tutte le chiamate esterne.
- **NFR-AF-02** **Circuit breaker** per provider degradati con **failover** a provider secondario o accodamento.
- **NFR-AF-03** **Idempotenza** di ingestion ed embedding.
- **NFR-AF-04** Fallimenti parziali isolati: un documento corrotto non blocca il batch.
- **NFR-AF-05** Scritture sull'indice **atomiche** rispetto alla sostituzione di versioni documento.
- **NFR-AF-06** **Backpressure**: l'ingestion ad alto volume non satura provider, DB o vector store.

### 5.4 Disaster Recovery e consistenza — `NFR-DR`  *(nuovo)*
- **NFR-DR-01** Strategia di **backup coordinato** tra store relazionale (metadati) e vector store (vettori).
- **NFR-DR-02** **Consistenza vettori ↔ metadati** verificabile e riparabile (job di riconciliazione che rileva chunk senza vettore o vettori orfani).
- **NFR-DR-03** **RPO/RTO** target da concordare per tier enterprise; capacità di **rebuild dell'indice dai dati sorgente** come ultima istanza.
- **NFR-DR-04** Restore che rispetta la cifratura BYOK (i backup restano inutili senza la chiave del tenant — coerente con crypto-shredding).

### 5.5 Sicurezza — `NFR-SE`
- **NFR-SE-01** Segreti (API key, credenziali KMS) solo via env/secret manager.
- **NFR-SE-02** Isolamento multi-tenant come **invariante testata** (test che dimostrano l'assenza di leakage cross-tenant).
- **NFR-SE-03** **Cifratura in transito** verso tutti i servizi; **a riposo** via BYOK per i contenuti e KMS-backed per i vettori.
- **NFR-SE-04** Hook di access control prima di ogni lettura/scrittura.
- **NFR-SE-05** Sanitizzazione input di parsing (file malevoli, XXE, zip-bomb).
- **NFR-SE-06** Nessun log di contenuto sensibile di default; redazione log configurabile.
- **NFR-SE-07** Principio del **minimo privilegio** sulle credenziali verso provider e KMS.

### 5.6 Compliance (GDPR / ISO 27001 / SOC 2) — `NFR-CO`
- **NFR-CO-01** **Diritto alla cancellazione** via crypto-shredding (revoca chiave) + purge fisico verificabile dei derivati.
- **NFR-CO-02** **Data residency UE di default** (embedding, KMS, vector store in regione UE); trasferimenti extra-UE solo su scelta esplicita e documentata.
- **NFR-CO-03** **Audit log immutabile** (append-only, a prova di manomissione) delle operazioni su dati personali e chiavi — evidenza per ISO 27001 / SOC 2.
- **NFR-CO-04** **Minimizzazione**: redazione PII ON di default prima dell'invio a terzi.
- **NFR-CO-05** **Data flow documentato** verso ogni provider (per DPA e registro trattamenti).
- **NFR-CO-06** **Retention policy** configurabile con purge schedulato.

### 5.7 Osservabilità — `NFR-OB`
- **NFR-OB-01** Logging strutturato correlato per documento/operazione/tenant.
- **NFR-OB-02** Metriche: latenza retrieval, durata stage ingestion, hit-rate cache, token e costo per operazione e per tenant.
- **NFR-OB-03** Tracing opzionale degli stage della pipeline.
- **NFR-OB-04** Stato pipeline interrogabile (conteggi per stato).

### 5.8 Gestione costi — `NFR-CT`
- **NFR-CT-01** Tracking token e costo per ogni chiamata a embedding/LLM/rerank.
- **NFR-CT-02** Aggregazione costi per tenant e periodo.
- **NFR-CT-03** Caching aggressivo per ridurre chiamate ridondanti.
- **NFR-CT-04** Quote/budget per tenant con enforcement (soft warning + hard limit).
- **NFR-CT-05** Stima costo *a priori* di una (re)indicizzazione prima di eseguirla.

### 5.9 Estensibilità — `NFR-ES`
- **NFR-ES-01** Ogni componente dietro contratto; nuovi driver senza fork.
- **NFR-ES-02** Pipeline a stage componibili.
- **NFR-ES-03** Eventi su tutto il ciclo di vita.
- **NFR-ES-04** Nessuna dipendenza hard verso singolo provider, store, formato o KMS.

### 5.10 Manutenibilità — `NFR-MA`
- **NFR-MA-01** PSR-12, tipizzazione stretta (PHP 8.2+, `declare(strict_types=1)`).
- **NFR-MA-02** Analisi statica (PHPStan/Larastan livello alto) in CI.
- **NFR-MA-03** Copertura test sul core ≥ 80%, con focus su contratti, pipeline e invarianti di sicurezza.
- **NFR-MA-04** Documentazione: README, guida per autori di pacchetti consumatori, esempi di driver custom e di integrazione KMS.
- **NFR-MA-05** **SemVer** rigoroso sui contratti pubblici.

### 5.11 Compatibilità — `NFR-CP`
- **NFR-CP-01** **Laravel 11 e 12**, **PHP 8.2+**.
- **NFR-CP-02** Compatibile con **`config:cache`**.
- **NFR-CP-03** Vector store primario **Qdrant**; pgvector come alternativa on-prem/piccola scala.
- **NFR-CP-04** Astrazione storage/queue/cache via contratti Laravel (compatibile con R2/Workers/Hyperdrive).
- **NFR-CP-05** Deploy **on-prem** supportato; **profilo air-gapped opzionale** (tutti i componenti self-hostable: embedding locale, Qdrant locale, Vault come KMS).

### 5.12 Internazionalizzazione — `NFR-IN`
- **NFR-IN-01** Documenti multilingua con rilevamento lingua.
- **NFR-IN-02** Modelli di embedding multilingua (priorità IT/DE/EN).
- **NFR-IN-03** Architettura che non preclude lingue senza confini di parola netti.

### 5.13 Testabilità — `NFR-TE`
- **NFR-TE-01** **Fake** ufficiali per embedder, vector store, LLM e **KMS** (deterministici, zero rete).
- **NFR-TE-02** Vector store in-memory nei test.
- **NFR-TE-03** Pipeline eseguibile in modalità sincrona.
- **NFR-TE-04** Test che verificano **isolamento multi-tenant** e **crypto-shredding** come invarianti.

---

## 6. Decisioni architetturali chiave (chiuse)

| # | Decisione | Scelta | Note |
|---|---|---|---|
| 6.1 | Confine generation | **Dentro ma isolato/opzionale** | Sola ricerca = no dipendenze LLM; RAG completo = prompt di dominio del consumatore. |
| 6.2 | Vector store default | **Qdrant** (quantizzazione + partizionamento) | pgvector per piccoli/on-prem; in-memory per test. Tutti dietro lo stesso contratto. |
| 6.3 | Multi-tenancy | **Namespace per tenant** di default | Schema/DB dedicato come opzione enterprise. |
| 6.4 | Sync vs async | **Ingestion async + backpressure; retrieval sincrono** | Modalità sincrona disponibile per test/input ad-hoc. |
| 6.5 | Embedding default | **EU-resident cloud** (Mistral/Jina/Azure OpenAI UE) | Self-hosted per sovranità massima; extra-UE solo su scelta esplicita. |
| 6.6 | Cifratura | **BYOK + envelope encryption + crypto-shredding** | DEK per-tenant wrappata da KEK nel KMS del tenant. |
| 6.7 | Confine cifratura vettori | **Vettori non BYOK** | ANN richiede float in chiaro; encryption-at-rest KMS-backed + collocazione nel perimetro tenant dove serve isolamento assoluto. |
| 6.8 | KMS | **Abstraction multi-driver** | AWS/GCP/Azure/Vault + locale dev. |
| 6.9 | Deployment | **EU-cloud default; on-prem supportato; air-gapped opzionale** | Air-gapped non è più baseline. |
| 6.10 | Config | **Statica config-cache-safe + override runtime** | Nessuna closure nei config. |
| 6.11 | Tokenizer | **Astratto dietro contratto** | Chunking, budget contesto e stima costi dipendono dal modello. |

---

## 7. Struttura proposta del pacchetto

```
src/
├── Contracts/            # Embedder, VectorStore, Chunker, Parser, Reranker,
│                         #   QueryTransformer, Tokenizer, Llm, KeyManagement (KMS)
├── Managers/             # EmbedderManager, VectorStoreManager, KmsManager, ...
├── Ingestion/            # Sorgenti, deduplica, provenance, versioning
├── Parsing/              # Parser per formato + OCR
├── Preprocessing/        # Stage di pulizia/normalizzazione/PII
├── Chunking/             # Strategie di chunking (incl. parent-child, contextual)
├── Embedding/            # Provider EU/self-host, batching, cache cifrata, cost tracking
├── VectorStore/          # Qdrant, Pgvector, InMemory; quantizzazione, partizioni
├── Retrieval/            # Search builder, hybrid+RRF, MMR, soglie
├── Reranking/            # Rerank, compression
├── Query/                # Query transformer (multi-query, HyDE, step-back)
├── Generation/           # (opzionale) contesto, citazioni, streaming
├── Security/             # Envelope encryption, BYOK, crypto-shredding
│   └── Kms/              #   Driver: Aws, Gcp, Azure, Vault, Local
├── Tenancy/              # Scoping, livelli di isolamento, quote
├── Pipeline/             # Orchestrazione job/batch, stato, backpressure, eventi
├── Audit/                # Audit log immutabile (append-only)
├── Recovery/             # Riconciliazione vettori↔metadati, rebuild indice
├── Events/               # Eventi del ciclo di vita
├── Models/               # Document, Chunk, Embedding meta, UsageRecord, AuditEntry
├── Concerns/             # Trait Eloquent "Searchable"-like
├── Console/              # Comandi Artisan (incl. rotate-keys, reconcile)
├── Testing/              # Fake (incl. KMS) e helper di test
├── Facades/              # Facade pubblica
└── RagEngineServiceProvider.php
config/    rag-engine.php
database/  migrations/
```

---

## 8. Modello dati (entità principali)

| Entità | Ruolo | Campi chiave |
|---|---|---|
| **Document** | Sorgente ingerita | `id`, `tenant_id`, `source_type`, `content_hash`, `mime`, `metadata`, `version`, `status`, `encrypted_content_ref`, `dek_id` |
| **Chunk** | Frammento indicizzabile | `id`, `document_id`, `tenant_id`, `encrypted_content`, `position/offset`, `metadata`, `parent_chunk_id?`, `token_count` |
| **Embedding (meta)** | Vettore + tracciamento | `chunk_id`, `model`, `dimensions`, `vector` (Qdrant/pgvector), `provider`, `cost` |
| **DataKey** | Riferimento DEK wrappata | `id`, `tenant_id`, `wrapped_dek`, `kek_ref` (KMS), `rotated_at` |
| **IngestionRun** | Stato batch | `id`, `tenant_id`, `status`, contatori, errori |
| **UsageRecord** | Consumi/costi | `tenant_id`, `operation`, `tokens`, `cost`, `period` |
| **AuditEntry** | Log immutabile | `tenant_id`, `actor`, `action`, `target`, `hash_prev` (catena), `created_at` |

> La DEK in chiaro non è mai persistita: vive in memoria solo per la durata dell'operazione, dopo unwrap via KMS. La chiave fisica resta nel KMS del tenant.

---

## 9. Rischi e questioni aperte

| # | Rischio / questione | Impatto | Mitigazione |
|---|---|---|---|
| R1 | **Scope creep** da postura enterprise | Alto | Seam subito, implementazione progressiva (vedi §1). |
| R2 | **BYOK vs ricerca vettoriale**: i vettori non sono BYOK-cifrabili | Alto (atteso) | Confine dichiarato (FR-SEC-06); encryption-at-rest KMS + collocazione nel perimetro tenant. |
| R3 | **Latenza KMS** (unwrap DEK ad ogni operazione) | Medio | Cache DEK in memoria a TTL breve per richiesta/batch; mai persistita. |
| R4 | **Embedding inversion** (un vettore è parzialmente invertibile) | Medio | Trattare i vettori come sensibili; isolamento e access control; opzione store nel perimetro tenant. |
| R5 | **Costi embedding** su grandi volumi | Medio | Self-hosted EU, caching, batching, quote per tenant, stima a priori. |
| R6 | **Qualità OCR/parsing** variabile a monte | Medio | Parser driver isolati; OCR premium per cliente dove serve. |
| R7 | **Leakage cross-tenant** | Alto | Scoping automatico + namespace per tenant + test invariante. |
| R8 | **Consistenza vettori↔metadati** dopo crash/restore | Medio | Job di riconciliazione + rebuild dai sorgenti (NFR-DR). |
| R9 | **Complessità operativa** (Qdrant + KMS + worker + repliche) | Medio | Profilo "leggero" (pgvector + KMS locale) per ambienti piccoli e dev. |
| Q1 | **RPO/RTO** target per il tier enterprise? | — | Determina strategia di backup/replica (NFR-DR-03). |
| Q2 | KMS **prioritario** per i primi clienti (AWS vs Azure vs Vault)? | — | Determina quale driver KMS rifinire per primo. |
| Q3 | La **redazione PII** dev'essere reversibile (tokenizzazione) o distruttiva di default? | — | Determina il comportamento di FR-PP-03. |

---

## 10. Roadmap suggerita (fasi)

La postura enterprise **carica molto la Fase 1**, perché sicurezza e isolamento non si retrofittano.

**Fase 0 — Fondamenta**
Contracts (incl. `KeyManagement`), manager, service provider, config, modelli, eventi base, fake (incl. KMS). Ossatura su cui tutto si innesta.

**Fase 1 — MVP retrieval enterprise end-to-end**
Ingestion (upload + URL + testo + Eloquent + storage), **copertura parser ampia**, chunking fixed/recursive/sentence/markdown token-aware **+ parent-child + contextual headers**, embedding **EU-resident + self-hosted** con cache e cost tracking, **Qdrant + pgvector + in-memory**, retrieval con **hybrid+RRF, MMR, soglie, filtri**, **reranking**, **BYOK/envelope encryption/crypto-shredding + KMS abstraction**, **multi-tenancy con namespace per tenant**, **audit log immutabile**, pipeline async **con backpressure**, quote per tenant, comandi Artisan (incl. rotate-keys), PII redaction ON, facade. **A fine Fase 1: ricerca semantica enterprise reale, sicura e multi-tenant.**

**Fase 2 — Scala e robustezza**
Quantizzazione vettoriale, sharding/partizionamento, read-scaling, riconciliazione vettori↔metadati e rebuild indice, key rotation, compressione contestuale, OpenDocument/legacy/email parser.

**Fase 3 — Generation opzionale + qualità avanzata**
Strato generation (contesto, citazioni, streaming), multi-query, semantic chunking, observability/tracing completi, profilo air-gapped end-to-end.

**Fase 4 — Avanzato**
HyDE/step-back, re-embedding online senza downtime, esplorazione confidential computing per i vettori, ottimizzazioni di costo spinte.

---

### Prossimo passo
Le decisioni sono chiuse: posso passare al **documento di architettura tecnica** con i **contratti pubblici della Fase 0** (firme di `Embedder`, `VectorStore`, `Chunker`, `Parser`, `Reranker`, `KeyManagement`, `Tokenizer`), il modello dati di dettaglio e il diagramma della pipeline. Le tre domande residue (Q1–Q3) non bloccano l'architettura: incidono su backup, priorità driver KMS e comportamento PII, e le possiamo chiudere in corsa.
