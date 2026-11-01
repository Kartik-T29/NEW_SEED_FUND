<?php
/**
 * Official IDEAPAD Seed Fund Policy Constants & Enumerations
 * Source of Truth: Grant_policy-260226.docx & Seed_Grant_Application_ITMU-ITMGOI-240226.docx
 */

declare(strict_types=1);

namespace App\Config;

class Constants {
    // -------------------------------------------------------------
    // P2. Grant Types & Amounts
    // -------------------------------------------------------------
    public const GRANT_TIERS = [
        'IDEATION' => [
            'name' => 'Ideation reward',
            'min' => 1000,
            'max' => 5000,
            'target_trl' => 'TRL 1: Idea/principle',
            'target_mrl' => 'MRL 1: Basic Manufacturing concept',
            'eligibility' => 'Award winners from IDEAPAD competitions or prestigious external competitions.',
            'purpose' => 'To encourage the candidate to explore the idea, conduct surveys, and understand the business canvas.'
        ],
        'VALIDATION' => [
            'name' => 'Validation Grant',
            'min' => 5000,
            'max' => 20000,
            'target_trl' => 'TRL 2: Concept formulated',
            'target_mrl' => 'MRL 2: Manufacturing concept defined',
            'eligibility' => 'Business canvas, empathy sketch of potential customers, evaluation methodology / well-defined experiment with desired outcomes defined.',
            'purpose' => 'To test and evaluate the idea using various evaluation techniques and simulations.'
        ],
        'PROOF' => [
            'name' => 'Proof Grant',
            'min' => 25000,
            'max' => 50000,
            'target_trl' => 'TRL 3: Experimental proof',
            'target_mrl' => 'MRL 3: Materials & process identified',
            'eligibility' => 'Teams with complete prototype (hard/soft) schematics with material/process definition, pitch deck assuming a successful prototype, facility agreement with IDEAPAD.',
            'purpose' => 'For assessing market response and showcasing the idea to potential customers and investors.'
        ],
        'SCALING' => [
            'name' => 'Scaling Grant',
            'min' => 50000,
            'max' => 500000,
            'target_trl' => 'TRL 4: Lab testing & TRL 5: Prototype validated',
            'target_mrl' => 'MRL 4–5: Lab-level to prototype manufacturing',
            'eligibility' => 'Startups with early traction and an equity agreement with IDEAPAD.',
            'purpose' => 'For production, marketing, or operations.'
        ],
        'EMERGENCY' => [
            'name' => 'Emergency Support Grant',
            'min' => 0,
            'max' => 0, // Variable
            'target_trl' => 'Variable',
            'target_mrl' => 'Variable',
            'eligibility' => 'Case-by-case basis.',
            'purpose' => 'For continuity and critical support (to prevent drop-out).'
        ]
    ];

    // -------------------------------------------------------------
    // P8.1 TRL Scales (Levels 1 to 9)
    // -------------------------------------------------------------
    public const TRL_LEVELS = [
        1 => 'Idea/principle',
        2 => 'Concept formulated',
        3 => 'Experimental proof',
        4 => 'Lab testing',
        5 => 'Prototype validated',
        6 => 'Prototype demonstrated',
        7 => 'System-level prototype tested',
        8 => 'Final product ready',
        9 => 'Market launch & scaling'
    ];

    // -------------------------------------------------------------
    // P8.1 MRL Scales (Levels 1 to 9)
    // -------------------------------------------------------------
    public const MRL_LEVELS = [
        1 => 'Basic Manufacturing concept',
        2 => 'Manufacturing concept defined',
        3 => 'Materials & process identified',
        4 => 'Lab-level manufacturing',
        5 => 'Prototype manufacturing',
        6 => 'Pilot manufacturing',
        7 => 'Low-rate production',
        8 => 'Stable manufacturing',
        9 => 'Mass production'
    ];

    // -------------------------------------------------------------
    // P8.1 IRL Scales (Levels 1 to 9 - Correcting IRL 3 policy typo)
    // -------------------------------------------------------------
    public const IRL_LEVELS = [
        1 => 'Integration concept identified',
        2 => 'Integration assumptions defined',
        3 => 'Interface proof of concept',
        4 => 'Subsystem integration validated',
        5 => 'System integration in relevant environment',
        6 => 'Integrated system demonstrated',
        7 => 'Integration in operational environment',
        8 => 'Integration proven and stable',
        9 => 'Fully integrated and sustained'
    ];

    // -------------------------------------------------------------
    // P7. Annexure 1: Major Grant Headers (9 Categories)
    // -------------------------------------------------------------
    public const BUDGET_HEADERS = [
        'EQUIPMENT' => [
            'name' => 'Equipment / Capital Purchase',
            'description' => 'Used for one-time asset creation for IDEAPAD.',
            'examples' => 'Sensors, electronics, GPUs, servers; Lab instruments; Robotics kits, drones, fabrication tools; 3D printers, testing rigs.',
            'requires_quotation' => true,
            'helper' => 'Requires formal quotation upload or vendor catalog reference.'
        ],
        'CONSUMABLES' => [
            'name' => 'Consumables / Prototyping Materials',
            'description' => 'For items that get used up during development.',
            'examples' => 'Electronic components, PCB fabrication, mechanical parts, chemicals, raw materials, batteries, cables, connectors.',
            'requires_quotation' => false,
            'helper' => 'Raw materials and electronic components utilized during physical or software prototyping.'
        ],
        'SOFTWARE' => [
            'name' => 'Software & Cloud Services',
            'description' => 'Important for AI and CS startups.',
            'examples' => 'SaaS subscriptions, Cloud compute credits (AWS, Azure, GCP), dataset purchase, API usage, software licenses.',
            'requires_quotation' => false,
            'helper' => 'Cloud computing, API usage credits, dataset acquisition, and developer tooling.'
        ],
        'MANPOWER' => [
            'name' => 'Manpower Assistance',
            'description' => 'Limited stipends allowed under university grants.',
            'examples' => 'Student interns, research assistants, prototype developers, designers or annotators (AI datasets).',
            'requires_quotation' => false,
            'helper' => 'Direct project stipends for specialized engineering, annotation, or student assistants.'
        ],
        'TRAVEL' => [
            'name' => 'Travel & Field Work',
            'description' => 'For validation and ecosystem exposure.',
            'examples' => 'Travel for competitions/hackathons, customer discovery visits, industry meetings, pilot deployment visits, conference participation.',
            'requires_quotation' => false,
            'helper' => 'Field testing, ecosystem customer discovery visits, and approved competition travel.'
        ],
        'TESTING' => [
            'name' => 'Testing, Certification & Validation',
            'description' => 'Essential technical validation services.',
            'examples' => 'Product testing labs, safety certification, EMI/EMC testing, AI model benchmarking, regulatory approvals.',
            'requires_quotation' => false,
            'helper' => 'Accredited lab testing, regulatory compliance certificates, and third-party benchmark audits.'
        ],
        'SERVICES' => [
            'name' => 'Professional Services / Consultancy',
            'description' => 'External expertise startups cannot build internally.',
            'examples' => 'Legal consultation, patent filing support, CA/accounting services, UI/UX consulting, product design mentorship.',
            'requires_quotation' => false,
            'helper' => 'Intellectual property / patent filing support, chartered accountant auditing, and specialized mentorship.'
        ],
        'OUTREACH' => [
            'name' => 'Outreach, Events & Dissemination',
            'description' => 'Used for visibility and ecosystem engagement.',
            'examples' => 'Demo day setup, posters & branding, website development, promotional videos, exhibition participation.',
            'requires_quotation' => false,
            'helper' => 'Exhibition stalls, prototype showcase videos, demo day collateral, and pilot marketing.'
        ],
        'CONTINGENCY' => [
            'name' => 'Contingency / Miscellaneous',
            'description' => 'Usually 5–10% of total grant.',
            'examples' => 'Unplanned small purchases, repair & maintenance, logistics.',
            'requires_quotation' => false,
            'helper' => 'Recommended between 5% and 10% of total grant request for unforeseen emergency logistics.'
        ]
    ];

    public const ANNEXURE_1_HEADERS = self::BUDGET_HEADERS;
    public const TIMELINE_DATES = self::FORM_B_TIMELINE_FIELDS;

    public const SEC_PANEL_DESIGNATIONS = [
        0 => [
            'domain' => 'Innovation & Product Development',
            'default_name' => 'Er. Arun Singh Kushwah',
            'default_designation' => 'Assistant Director (Startups), SOET'
        ],
        1 => [
            'domain' => 'Business & Finance',
            'default_name' => 'Dr. Ambar Agarwal',
            'default_designation' => 'Faculty, School of Management'
        ],
        2 => [
            'domain' => 'Market Access & Intelligence',
            'default_name' => 'Prof. Ashish Chandel',
            'default_designation' => 'Director, IDEAPAD Incubator'
        ]
    ];

    // -------------------------------------------------------------
    // P8.2 Milestone Sets
    // -------------------------------------------------------------
    public const MILESTONES_TRACK_A = [
        'Customer interviews',
        'Empathy map and root cause analysis',
        'Problem framing and validation',
        'Ideation sprint',
        'MVP sketching and hypothesis',
        'Early user feedback and target market clarity',
        'No cost/low cost product/idea/service development and field testing',
        'Present findings to mentors/incubator team',
        'MVP revision'
    ];

    public const MILESTONES_TRACK_B = [
        'Customer interviews',
        'Competitive landscape',
        'Analyze case studies in same field',
        'Target market clarity',
        'Mentor assignment',
        'Run a pilot with faculty/students with mock negotiation & pricing',
        'Present findings to mentors/incubator team',
        'Build & test Business Model Canvas (BMC)',
        'Calculate service economics and breakeven plan',
        'Pitch deck / Pitch to panel'
    ];

    // -------------------------------------------------------------
    // P8.3 TRL 2 / TRL 3 Rubric Assessment Questions (12 Questions)
    // -------------------------------------------------------------
    public const RUBRIC_QUESTIONS = [
        'A' => [
            'category' => 'A. Technical Soundness',
            'questions' => [
                1 => 'Is the problem clearly defined?',
                2 => 'Is the hypothesis/objective of the experiment clear?',
                3 => 'Does the experiment logically test the core technology?',
                4 => 'Is the methodology realistic and technically feasible?',
                5 => 'Are required tools/resources identified?',
                6 => 'Will experiment provide a validation of your idea? (TRL 2)',
                7 => 'Will results provide proof-of-concept (TRL-3 evidence)?'
            ]
        ],
        'B' => [
            'category' => 'B. Market Worthiness',
            'questions' => [
                8 => 'Is there a clearly identified user/customer need?',
                9 => 'Does the experiment reduce a key market or product risk?',
                10 => 'Is there a plausible application/use case after TRL-3?'
            ]
        ],
        'C' => [
            'category' => 'C. Execution Readiness',
            'questions' => [
                11 => 'Is timeline realistic?',
                12 => 'Does the team show adequate technical understanding?'
            ]
        ]
    ];

    public static function getFlatRubricQuestions(): array {
        $flat = [];
        foreach (self::RUBRIC_QUESTIONS as $group) {
            foreach ($group['questions'] as $num => $text) {
                $flat[$num] = $text;
            }
        }
        return $flat;
    }

    // -------------------------------------------------------------
    // P9. Form B Approved Timeline (8 Specific Dates)
    // -------------------------------------------------------------
    public const FORM_B_TIMELINE_FIELDS = [
        'grantApprovalDate' => [
            'label' => '1. Grant Approval Date',
            'description' => 'Date of sanction by committee'
        ],
        'fundReleaseDate' => [
            'label' => '2. Fund Release Date',
            'description' => 'Date funds are transferred to startup or vendors'
        ],
        'experimentDuration' => [
            'label' => '3. Experiment Duration',
            'description' => 'Total time allowed to complete TRL-3 experiment (e.g., 4–6 months)'
        ],
        'midReviewDate' => [
            'label' => '4. Mid-Review Date',
            'description' => 'Progress check to verify technical work and spending'
        ],
        'grantUtilizationDeadline' => [
            'label' => '5. Grant Utilization Deadline',
            'description' => 'Last date by which funds must be spent'
        ],
        'experimentCompletionDate' => [
            'label' => '6. Experiment Completion Date',
            'description' => 'Deadline for proof-of-concept demonstration'
        ],
        'finalSubmissionDate' => [
            'label' => '7. Final Submission Date',
            'description' => 'Report + utilization certificate + demo evidence'
        ],
        'closureDate' => [
            'label' => '8. Closure Date',
            'description' => 'Final approval / grant closure by committee (to be eligible for next grant)'
        ]
    ];

    // -------------------------------------------------------------
    // P6. 10 Experiment Headings
    // -------------------------------------------------------------
    public const EXPERIMENT_HEADINGS = [
        1 => ['title' => 'Objective', 'prompt' => 'What hypothesis or concept is being tested?'],
        2 => ['title' => 'Problem Statement', 'prompt' => 'What gap does the experiment address?'],
        3 => ['title' => 'Expected Outcome', 'prompt' => 'What success will look like (TRL-3 proof)?'],
        4 => ['title' => 'Methodology', 'prompt' => 'How the experiment will be conducted?'],
        5 => ['title' => 'Experimental Setup', 'prompt' => 'Tools, materials, environment required (short list)?'],
        6 => ['title' => 'Data to be Collected', 'prompt' => 'What measurements/evidence will be recorded?'],
        7 => ['title' => 'Validation Criteria', 'prompt' => 'How results will be evaluated as successful?'],
        8 => ['title' => 'Risks & Assumptions', 'prompt' => 'Technical uncertainties or dependencies?'],
        9 => ['title' => 'Timeline', 'prompt' => 'Key stages and duration?'],
        10 => ['title' => 'Deliverables', 'prompt' => 'Prototype, dataset, report, demo, etc.']
    ];

    // -------------------------------------------------------------
    // P6.10 Declaration
    // -------------------------------------------------------------
    public const DECLARATION_TEXT = "I hereby declare that all information provided is true and the requested grant amount will be used strictly for the stated purpose.";

    // -------------------------------------------------------------
    // P10. Terms and Conditions
    // -------------------------------------------------------------
    public const TERMS_AND_CONDITIONS = [
        "The sanctioned amounts will not be transferred to a personal account.",
        "All purchases will be made by the institute directly or via managed institutional prepaid instruments; no cash will be provided.",
        "All purchases, equipment, and prototypes developed herewith remain the property of ITM University.",
        "The startup will aim to register their venture within six months and accept an equity-sharing agreement with the incubator.",
        "The startup will submit all sale vouchers, original invoices, and an expense report with supporting bills to the incubator.",
        "Non-compliance will lead to immediate termination of the grant and future ineligibility."
    ];
}

if (!defined('ROLE_STARTUP')) define('ROLE_STARTUP', 'STARTUP');
if (!defined('ROLE_SEC')) define('ROLE_SEC', 'SEC_MEMBER');
if (!defined('ROLE_ADMIN')) define('ROLE_ADMIN', 'ADMIN');

if (!class_exists('Constants', false)) {
    class_alias(Constants::class, 'Constants');
}
