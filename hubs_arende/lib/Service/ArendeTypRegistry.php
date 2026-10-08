<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\ArendeTyp;
use OCA\HubsArende\Db\ArendeTypMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Datadriven registry of ärendetyper (config-rows, NOT code forks).
 *
 * "En motor · en saga · N config-rader · ~3 hooks · M flaggor": each ärendetyp is
 * a row in hubs_arende_typ. {@see ArendeService::createCase()} reads the row via
 * {@see get()} and parameterises every saga step from it — there is never an
 * `if (kategori === N)` branch in the engine.
 *
 * The field values seeded here are taken from
 * hubs_start/docs/ARENDETYPER-FLODESANALYS.md (§2.3 the config shape, §3.1 the
 * 8×9 matrix). All eight rows declare a NON-NULL commit_destination — the engine
 * invariant — so a case can never be created without a destination.
 *
 * The eight types: orosanmalan, ansokan_bistand, ekonomi, komplettering,
 * vard_samverkan, rattsligt_tvang, verkstallighet, familjeratt.
 */
class ArendeTypRegistry {
    public function __construct(
        private ArendeTypMapper $mapper,
        private LoggerInterface $logger,
        // A8 — trailing-optional (positionell testharness): behövs bara av
        // synkaOmprovningskrav() som patchar befintliga rader via rå UPDATE
        // (ArendeTypMapper ägs av annan agent, så vi kan inte lägga en metod där).
        private ?IDBConnection $db = null,
    ) {
    }

    /**
     * Resolve a single ärendetyp config-row by id.
     *
     * @param string $arendeTypId One of the 8 seeded ids.
     * @return ArendeTyp|null null when the typ is unknown (caller fails closed —
     *         createCase() rejects an unknown typ rather than guessing).
     */
    public function get(string $arendeTypId): ?ArendeTyp {
        if ($arendeTypId === '') {
            return null;
        }
        try {
            return $this->mapper->findByTypId($arendeTypId);
        } catch (DoesNotExistException) {
            return null;
        } catch (DBException $e) {
            $this->logger->error('ArendeTypRegistry::get DB-fel', [
                'app' => 'hubs_arende',
                'arendeTypId' => $arendeTypId,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * @return ArendeTyp[]
     */
    public function all(): array {
        try {
            return $this->mapper->findAll();
        } catch (DBException $e) {
            $this->logger->error('ArendeTypRegistry::all DB-fel', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
            return [];
        }
    }

    /**
     * Idempotently seed the 8 default ärendetyper.
     *
     * Safe to run on every install/upgrade: each row is inserted only when its id
     * does not already exist (so a kommun's local edits are preserved). Intended to
     * be called from a RepairStep / post-migration hook.
     *
     * @return int Number of rows actually inserted.
     */
    public function seedDefaults(): int {
        $inserted = 0;
        foreach ($this->defaultRows() as $row) {
            $id = (string)$row['arendeTypId'];
            try {
                if ($this->mapper->exists($id)) {
                    continue;
                }
                $this->mapper->insert($this->hydrate($row));
                $inserted++;
            } catch (DBException $e) {
                // Tolerate a race / partial seed without aborting the rest.
                $this->logger->warning('ArendeTypRegistry::seedDefaults kunde inte seed:a rad', [
                    'app' => 'hubs_arende',
                    'arendeTypId' => $id,
                    'exception' => $e,
                ]);
            }
        }
        $this->logger->info('ArendeTypRegistry::seedDefaults klar', [
            'app' => 'hubs_arende',
            'inserted' => $inserted,
        ]);
        return $inserted;
    }

    /**
     * Patcha bevakningsmallar-kolumnen på REDAN BEFINTLIGA typrader (t.ex. på en
     * miljö som seedades innan kolumnen fanns). seedDefaults() rör bara frånvarande
     * rader, så utan detta får aldrig gamla orosanmalan-rader sin 14d→4mån-kedja.
     *
     * Sätter endast bevakningsmallar där den är NULL (kommun-anpassningar bevaras),
     * och endast för defaultrader som deklarerar mallar. Idempotent. Returnerar
     * antalet rader som uppdaterades.
     */
    public function synkaBevakningsmallar(): int {
        $uppdaterade = 0;
        foreach ($this->defaultRows() as $row) {
            $mallar = $row['bevakningsMallar'] ?? null;
            if (!is_array($mallar) || $mallar === []) {
                continue;
            }
            $id = (string)$row['arendeTypId'];
            try {
                $typ = $this->mapper->findByTypId($id);
            } catch (DoesNotExistException) {
                continue; // saknas → seedDefaults() skapar den (med mallar via hydrate)
            } catch (DBException $e) {
                $this->logger->warning('ArendeTypRegistry::synkaBevakningsmallar läsfel', [
                    'app' => 'hubs_arende', 'arendeTypId' => $id, 'exception' => $e,
                ]);
                continue;
            }
            if ($typ->getBevakningsmallar() !== null && $typ->getBevakningsmallar() !== '') {
                continue; // redan satt (default eller kommun-anpassad) — rör inte
            }
            // Riktad UPDATE by string-PK (QBMapper::update kräver int-id som saknas här).
            try {
                $this->mapper->setBevakningsmallar(
                    $id,
                    json_encode($mallar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                );
                $uppdaterade++;
            } catch (DBException $e) {
                $this->logger->warning('ArendeTypRegistry::synkaBevakningsmallar skrivfel', [
                    'app' => 'hubs_arende', 'arendeTypId' => $id, 'exception' => $e,
                ]);
            }
        }
        if ($uppdaterade > 0) {
            $this->logger->info('ArendeTypRegistry::synkaBevakningsmallar klar', [
                'app' => 'hubs_arende', 'uppdaterade' => $uppdaterade,
            ]);
        }
        return $uppdaterade;
    }

    /**
     * A8 — patcha omprovningskrav-kolumnen på REDAN BEFINTLIGA typrader (t.ex. en
     * miljö som seedades innan kolumnen fanns). seedDefaults() rör bara frånvarande
     * rader, så utan detta får aldrig gamla orosanmalan-/rattsligt_tvang-rader sitt
     * lagstadgade omprövningskrav → omprövningsbevakningen skulle aldrig autoskapas.
     *
     * Sätter endast kravet för de defaultrader som deklarerar omprovningskrav=true,
     * och endast där kolumnen ännu är false/NULL (kommun-anpassning bevaras — vi
     * höjer bara AV→PÅ, sänker aldrig). Idempotent. Rå UPDATE via IDBConnection
     * eftersom ArendeTypMapper ägs av en annan agent (string-PK ⇒ QBMapper::update
     * funkar ändå inte) och synkar bara defaultradernas krav.
     *
     * @return int Antalet rader som uppdaterades.
     */
    public function synkaOmprovningskrav(): int {
        if ($this->db === null) {
            return 0; // testharness utan DB-koppling — inget att synka
        }
        $uppdaterade = 0;
        foreach ($this->defaultRows() as $row) {
            if (($row['omprovningskrav'] ?? false) !== true) {
                continue; // bara rader som SKA ha kravet
            }
            $id = (string)$row['arendeTypId'];
            try {
                $typ = $this->mapper->findByTypId($id);
            } catch (DoesNotExistException) {
                continue; // saknas → seedDefaults() skapar den (med kravet via hydrate)
            } catch (DBException $e) {
                $this->logger->warning('ArendeTypRegistry::synkaOmprovningskrav läsfel', [
                    'app' => 'hubs_arende', 'arendeTypId' => $id, 'exception' => $e,
                ]);
                continue;
            }
            if ($typ->getOmprovningskrav() === true) {
                continue; // redan satt — rör inte (idempotent)
            }
            // Riktad UPDATE by string-PK (QBMapper::update kräver int-id som saknas här).
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->update('hubs_arende_typ')
                    ->set('omprovningskrav', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
                    ->where(
                        $qb->expr()->eq('arende_typ_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR))
                    );
                $qb->executeStatement();
                $uppdaterade++;
            } catch (DBException $e) {
                $this->logger->warning('ArendeTypRegistry::synkaOmprovningskrav skrivfel', [
                    'app' => 'hubs_arende', 'arendeTypId' => $id, 'exception' => $e,
                ]);
            }
        }
        if ($uppdaterade > 0) {
            $this->logger->info('ArendeTypRegistry::synkaOmprovningskrav klar', [
                'app' => 'hubs_arende', 'uppdaterade' => $uppdaterade,
            ]);
        }
        return $uppdaterade;
    }

    /**
     * Build an ArendeTyp entity from a config array.
     *
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): ArendeTyp {
        $typ = new ArendeTyp();
        $typ->setArendeTypId((string)$row['arendeTypId']);
        $typ->setDisplayName((string)$row['displayName']);
        $typ->setDefaultEnhet($row['defaultEnhet'] !== null ? (string)$row['defaultEnhet'] : null);
        $typ->setForstaAtgard($row['forstaAtgard'] !== null ? (string)$row['forstaAtgard'] : null);
        $typ->setPliktGrind((bool)$row['pliktGrind']);
        $typ->setKopplingDefault($row['kopplingDefault'] !== null ? (string)$row['kopplingDefault'] : null);
        // fristPolicy is stored as JSON text.
        $typ->setFristPolicy(is_array($row['fristPolicy'])
            ? json_encode($row['fristPolicy'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : ($row['fristPolicy'] !== null ? (string)$row['fristPolicy'] : null));
        $typ->setAclProfil($row['aclProfil'] !== null ? (string)$row['aclProfil'] : null);
        $typ->setSekretessGrund($row['sekretessGrund'] !== null ? (string)$row['sekretessGrund'] : null);
        $typ->setDiariePlikt((bool)$row['diariePlikt']);
        $typ->setDhpHandlingstyp($row['dhpHandlingstyp'] !== null ? (string)$row['dhpHandlingstyp'] : null);
        // INVARIANT: commit_destination NOT NULL.
        $typ->setCommitDestination((string)$row['commitDestination']);
        $typ->setFrendsModul($row['frendsModul'] !== null ? (string)$row['frendsModul'] : null);
        $typ->setPreSagaHook($row['preSagaHook'] !== null ? (string)$row['preSagaHook'] : null);
        $typ->setPostCommitHook($row['postCommitHook'] !== null ? (string)$row['postCommitHook'] : null);
        $typ->setPartsModell($row['partsModell'] !== null ? (string)$row['partsModell'] : null);
        // bevakningsMallar stored as JSON text (null = inga standardbevakningar).
        $mallar = $row['bevakningsMallar'] ?? null;
        $typ->setBevakningsmallar(is_array($mallar) && $mallar !== []
            ? json_encode($mallar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null);
        // A8 — lagstadgat omprövnings-/övervägandekrav (LVU 13 §, SoL övervägande).
        $typ->setOmprovningskrav((bool)($row['omprovningskrav'] ?? false));
        return $typ;
    }

    /**
     * The 8 default ärendetyper as config-rows.
     *
     * Field values are sourced from ARENDETYPER-FLODESANALYS.md §3.1 (8×9 matrix)
     * and §2.3 (config shape). Note the two hooks (§2.5):
     *   - kat 6 rättsligt_tvang  → preSagaHook='diariefor_direkt' (diarieför DIREKT)
     *   - kat 8 familjeratt      → partsModell='flerpartsarende' + postCommitHook='familjeratt_yttrande'
     *
     * commit_destination encodes WHERE the case ultimately commits (the invariant):
     *   facksystem | diarium | e_arkiv | extern_myndighet | triage_forward | karantan.
     *
     * @return list<array<string,mixed>>
     */
    private function defaultRows(): array {
        return [
            // 1 — Orosanmälan & akut skydd. Skyddsbedömning som pliktmarkör →
            // förhandsbedömning först (14 dgr ur inkom-datum). Starkaste ACL.
            [
                'arendeTypId' => 'orosanmalan',
                'displayName' => 'Orosanmälan & akut skydd',
                'defaultEnhet' => 'barn-familj@',
                'forstaAtgard' => 'skyddsbedomning',
                'pliktGrind' => true,
                'kopplingDefault' => 'nytt',
                'fristPolicy' => [
                    'typ' => '14d_forhandsbedomning',
                    'ankare' => 'inkom_datum',
                    'speglasUrTreserva' => false,
                ],
                'aclProfil' => 'mycket_hog',
                'sekretessGrund' => 'osl_26',
                'diariePlikt' => false, // förhandsbedömning FÖRE diarieföring
                'dhpHandlingstyp' => 'ifo_barn_anmalan',
                'commitDestination' => 'facksystem',
                'frendsModul' => 'ifo_barn',
                'preSagaHook' => null,
                'postCommitHook' => null,
                'partsModell' => 'enskild_klient',
                // A8 — placerat barn ⇒ lagstadgat övervägande var 6:e mån; motorn
                // säkerställer omprövningsbevakningen automatiskt vid uppföljning.
                'omprovningskrav' => true,
                // KEDJAN: förhandsbedömning (14 d, SoL 11:1a) släcks när utredning
                // inleds → utredningsbevakningen (4 mån, SoL 11:2) föds i samma steg
                // = själva nollställningen. Placerat barn ⇒ övervägande var 6:e mån.
                'bevakningsMallar' => [
                    [
                        'typ' => 'forhandsbedomning_14d',
                        'titel' => 'Förhandsbedömning klar (senast 14 dagar)',
                        'villkorTyp' => 'steg_uppnatt',
                        'villkorArg' => 'utredning',
                        'ankare' => 'inkom_datum',
                        'ankareDagar' => 14,
                        'recurringDagar' => null,
                        'lagstadgad' => true,
                        'vidSteg' => 'fodelse',
                    ],
                    [
                        'typ' => 'utredning_4man',
                        'titel' => 'Utredning klar (senast 4 månader)',
                        'villkorTyp' => 'steg_uppnatt',
                        'villkorArg' => 'beslut',
                        'ankare' => 'steg_datum',
                        'ankareDagar' => 120,
                        'recurringDagar' => null,
                        'lagstadgad' => true,
                        'vidSteg' => 'utredning',
                    ],
                    [
                        'typ' => 'overvagande_6man',
                        'titel' => 'Övervägande av vården (var 6:e månad)',
                        'villkorTyp' => 'manuell_kvittering',
                        'villkorArg' => 'uppfoljning',
                        'ankare' => 'steg_datum',
                        'ankareDagar' => 180,
                        'recurringDagar' => 180,
                        'lagstadgad' => true,
                        'vidSteg' => 'uppfoljning',
                    ],
                ],
            ],

            // 2 — Ansökan/begäran om insats. Behovsbedömning + behörighet; routing
            // beror på insats (sub-typ). NOT-NULL destination = facksystem.
            [
                'arendeTypId' => 'ansokan_bistand',
                'displayName' => 'Ansökan/begäran om insats',
                'defaultEnhet' => 'mottagning@',
                'forstaAtgard' => 'behovsbedomning',
                'pliktGrind' => false,
                'kopplingDefault' => 'nytt',
                'fristPolicy' => [
                    'typ' => 'forvaltningsratt_skyndsam',
                    'ankare' => 'inkom_datum',
                    'speglasUrTreserva' => true,
                ],
                'aclProfil' => 'hog',
                'sekretessGrund' => 'osl_26',
                'diariePlikt' => true,
                'dhpHandlingstyp' => 'insats_ansokan',
                'commitDestination' => 'facksystem',
                'frendsModul' => 'ifo_vuxen', // sub-typ väljer faktisk modul (ao|lss|ek_bistand|barn)
                'preSagaHook' => null,
                'postCommitHook' => null,
                'partsModell' => 'enskild_klient',
            ],

            // 3 — Ekonomi / försörjning / boende. Hög volym, repetitivt, lutar attach.
            [
                'arendeTypId' => 'ekonomi',
                'displayName' => 'Ekonomi / försörjning / boende',
                'defaultEnhet' => 'ekonomi@',
                'forstaAtgard' => 'behovsbedomning',
                'pliktGrind' => false,
                'kopplingDefault' => 'hor_till',
                'fristPolicy' => [
                    'typ' => 'manadscykel',
                    'ankare' => 'inkom_datum',
                    'speglasUrTreserva' => true,
                ],
                'aclProfil' => 'normal',
                'sekretessGrund' => 'osl_26',
                'diariePlikt' => true,
                'dhpHandlingstyp' => 'ek_bistand_akt',
                'commitDestination' => 'facksystem',
                'frendsModul' => 'ek_bistand',
                'preSagaHook' => null,
                'postCommitHook' => null,
                'partsModell' => 'enskild_klient',
            ],

            // 4 — Komplettering i pågående ärende. ALDRIG nytt → ren attach (1b).
            // Ärver allt från värd-ärendet; destination ärvs (default facksystem).
            [
                'arendeTypId' => 'komplettering',
                'displayName' => 'Komplettering i pågående ärende',
                'defaultEnhet' => null, // ärver matchat ärende
                'forstaAtgard' => 'koppla_befintligt',
                'pliktGrind' => false,
                'kopplingDefault' => 'hor_till',
                'fristPolicy' => [
                    'typ' => 'arver',
                    'ankare' => 'arver_vard',
                    'speglasUrTreserva' => true,
                ],
                'aclProfil' => 'arver',
                'sekretessGrund' => 'arver',
                'diariePlikt' => false, // tillförs befintligt dnr
                'dhpHandlingstyp' => 'komplettering',
                'commitDestination' => 'facksystem',
                'frendsModul' => null, // ärver värd-ärendets modul
                'preSagaHook' => null,
                'postCommitHook' => null,
                'partsModell' => 'enskild_klient',
            ],

            // 5 — Vård/omsorg/samverkan (kommun↔region). Tidskritisk KOORDINERING
            // (SIP/utskrivning) — kalender, EJ frist. Delad sekretess.
            [
                'arendeTypId' => 'vard_samverkan',
                'displayName' => 'Vård/omsorg/samverkan',
                'defaultEnhet' => 'aldreomsorg@',
                'forstaAtgard' => 'registrering',
                'pliktGrind' => false,
                'kopplingDefault' => 'hor_till',
                'fristPolicy' => [
                    'typ' => 'koordinering', // ser ut som frist, hanteras som kalender
                    'ankare' => 'inkom_datum',
                    'speglasUrTreserva' => false,
                ],
                'aclProfil' => 'delad_kommun_region',
                'sekretessGrund' => 'osl_25_samtycke',
                'diariePlikt' => true,
                'dhpHandlingstyp' => 'omsorg_sip',
                'commitDestination' => 'facksystem',
                'frendsModul' => 'ao',
                'preSagaHook' => null,
                'postCommitHook' => null,
                'partsModell' => 'enskild_klient',
            ],

            // 6 — Rättsliga / tvång / brott (LVU/LVM). VÄNDER ORDNINGEN: formell
            // diarieföring DIREKT (preSagaHook). Hårda domstolsfrister.
            [
                'arendeTypId' => 'rattsligt_tvang',
                'displayName' => 'Rättsligt / tvång (LVU/LVM)',
                'defaultEnhet' => 'barn-familj@',
                'forstaAtgard' => 'registrering',
                'pliktGrind' => false,
                'kopplingDefault' => 'nytt',
                'fristPolicy' => [
                    'typ' => 'domstol',
                    'ankare' => 'speglad_treserva',
                    'speglasUrTreserva' => true,
                ],
                'aclProfil' => 'hog_plus_aktorer',
                'sekretessGrund' => 'osl_10_partsinsyn',
                'diariePlikt' => true, // DIREKT, omvänd ordning mot kat 1
                'dhpHandlingstyp' => 'lvu_lvm_akt',
                'commitDestination' => 'diarium', // allmän handling direkt → diariet
                'frendsModul' => 'ifo_barn',
                'preSagaHook' => 'diariefor_direkt', // §2.5 hook
                'postCommitHook' => null,
                'partsModell' => 'enskild_klient',
                // A8 — LVU/LVM ⇒ lagstadgad omprövning/övervägande var 6:e mån
                // (LVU 13 §); motorn säkerställer bevakningen automatiskt vid uppföljning.
                'omprovningskrav' => true,
                // Domstolsfristerna speglas ur facksystemet (speglasUrTreserva) —
                // Hubs dubbelbevakar dem INTE. Men övervägandet/omprövningen av
                // vården var 6:e månad (LVU §13) är en Hubs-egen rytm som facksystemet
                // inte påminner om; recurring under uppföljningssteget.
                'bevakningsMallar' => [
                    [
                        'typ' => 'omprovning_6man',
                        'titel' => 'Övervägande/omprövning av vården (var 6:e månad)',
                        'villkorTyp' => 'manuell_kvittering',
                        'villkorArg' => 'uppfoljning',
                        'ankare' => 'steg_datum',
                        'ankareDagar' => 180,
                        'recurringDagar' => 180,
                        'lagstadgad' => true,
                        'vidSteg' => 'uppfoljning',
                    ],
                ],
            ],

            // 7 — Verkställighet / placering / uppföljning. ALLTID attach; dubbel-
            // märkning (akut_fara → även kat 1) sker via flaggor, inte typen.
            [
                'arendeTypId' => 'verkstallighet',
                'displayName' => 'Verkställighet / uppföljning',
                'defaultEnhet' => null, // ärver ansvarig utredare/uppföljning
                'forstaAtgard' => 'koppla_befintligt',
                'pliktGrind' => false,
                'kopplingDefault' => 'hor_till',
                'fristPolicy' => [
                    'typ' => 'tidsbegransat',
                    'ankare' => 'beslut_datum',
                    'speglasUrTreserva' => true,
                ],
                'aclProfil' => 'arver_plus_extern_part',
                'sekretessGrund' => 'arver',
                'diariePlikt' => false, // tillförs befintligt dnr
                'dhpHandlingstyp' => 'uppfoljning',
                'commitDestination' => 'facksystem',
                'frendsModul' => null, // samma modul som beslutet
                'preSagaHook' => null,
                'postCommitHook' => null,
                'partsModell' => 'enskild_klient',
            ],

            // 8 — Familjerätt. Egen partsmodell (flerpart, ofta motparter), egen
            // modul, post-hook för yttrande. Gränsfallet (§2.5).
            [
                'arendeTypId' => 'familjeratt',
                'displayName' => 'Familjerätt & relationsrätt',
                'defaultEnhet' => 'familjeratt@',
                'forstaAtgard' => 'registrering',
                'pliktGrind' => false,
                'kopplingDefault' => 'nytt',
                'fristPolicy' => [
                    'typ' => 'domstol',
                    'ankare' => 'speglad_treserva',
                    'speglasUrTreserva' => true,
                ],
                'aclProfil' => 'familjeratt_inre_sekretess', // strikt partsåtskillnad
                'sekretessGrund' => 'osl_26_partsatskillnad',
                'diariePlikt' => true, // egen ärendeserie
                'dhpHandlingstyp' => 'familjeratt_akt',
                'commitDestination' => 'facksystem',
                'frendsModul' => 'familjeratt',
                'preSagaHook' => null,
                'postCommitHook' => 'familjeratt_yttrande', // §2.5 hook
                'partsModell' => 'flerpartsarende',
            ],
        ];
    }
}
