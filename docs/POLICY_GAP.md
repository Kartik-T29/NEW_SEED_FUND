# Policy Gap Analysis Matrix (POLICY_GAP.md)

**Document Version**: 2.0 (Native PHP + MySQL Implementation)  
**Primary Sources of Truth**:
1. `Grant_policy-260226.docx` (copied to `docs/policy/Grant_policy-260226.docx`)
2. `Seed_Grant_Application_ITMU-ITMGOI-240226.docx` (copied to `docs/policy/Seed_Grant_Application_ITMU-ITMGOI-240226.docx`)

---

## 1. Clause-by-Clause Comparison Matrix

| Policy Clause | Policy Description / Requirement | Portal Implementation Location | Status | Implementation Details / Notes |
| :--- | :--- | :--- | :--- | :--- |
| **P1. Program & Purpose** | ITM Gwalior allocates yearly Innovation Fund to IDEAPAD; half-yearly audit; purpose: reach TRL 3 (PoC), validate assumptions/patents (TRL 2), provide professional exposure. Subject to TRL/MRL scales. | `config/constants.php`<br>`public/index.php`<br>`public/dashboard.php` | **Matches** | Clearly articulated on home page and dashboard banners. Availability explicitly tied to TRL/MRL criteria. |
| **P2. Grant Types & Limits** | 5 grant tiers:<br>• **Ideation reward**: TRL 1, MRL 1; ₹1k–₹5k.<br>• **Validation Grant**: TRL 2, MRL 2; ₹5k–₹20k.<br>• **Proof Grant**: TRL 3, MRL 3; ₹25k–₹50k.<br>• **Scaling Grant**: TRL 4–5; ₹50k–₹500k.<br>• **Emergency Support Grant**: variable, case-by-case.<br>Max 3 grants per team unless waived by SEC. | `config/constants.php`<br>`public/dashboard.php`<br>`public/sec.php`<br>`public/admin.php` | **Matches** | Exact tier names and amounts applied across forms and dropdowns. Replaced older generic names with official policy names. |
| **P3. SEC Composition** | Panel of 3 experts (Innovation/Product, Business/Finance, Market/Intelligence). Dynamic member profiles on Form A/B. | `database/schema.sql` (`User`)<br>`public/reports.php`<br>`public/sec.php` | **Matches** | Names, designations, and departments are dynamically rendered from approved SEC user profiles; no hardcoded names. |
| **P4. SEC SOP (Checklist)** | 12-step SOP: presentation date, review pitch deck 2 days prior, carry Form A, check terms/paperwork, check prior funding, project presentation, TRL/MRL/IRL assessment, milestones, budget moderation under Annexure 1, approval/disapproval, notes, forward to Director. | `public/sec.php`<br>`public/reports.php` | **Matches** | Form A split view incorporates dedicated Pre-checks tab, status levels, shared milestones, 12 rubric questions, and budget moderation. |
| **P5. Startup Application SOP** | 8-step SOP: provide agreement, PAN, Aadhaar, photo; fill form under Annexure 1; report previous utilization; pitch deck 2 days prior; presentation; get copy of assessment; check status with Director. | `public/dashboard.php`<br>`public/status.php` | **Matches** | Section 1 (Agreement), Section 4 (Budget), Section 7 (Documents), and status tracker reflect the complete SOP sequence. |
| **P6.1 Details** | Applicant Name (team leader), Enrollment No, Program & Department, Team Name, Contact (Phone, Email). | `public/register.php`<br>`public/dashboard.php` | **Matches** | All fields captured during registration and prefilled in application Section 2. |
| **P6.2–4 Problem / Solution / Traction** | Explain problem solving in short; proposed solution (as short as possible); effort and traction so far. | `public/dashboard.php` (Section 2) | **Matches** | Textareas with character counters (600/600/800 limits preserved). |
| **P6.5 Experiment Headings** | 10 headings: Objective, Problem Statement, Expected Outcome, Methodology, Experimental Setup, Data to be Collected, Validation Criteria, Risks & Assumptions, Timeline, Deliverables. | `public/dashboard.php` (Section 3)<br>`config/constants.php` | **Matches** | All 10 headings rendered with exact prompt subtitles from policy. |
| **P6.6 Requirement Table** | Grouped under 9 Annexure 1 heads; columns: S.no, Name & Description, Vendor Name/source, Unit Cost, Quantity, Total Cost, Contingency row, Grand Total. | `public/dashboard.php` (Section 4)<br>`database/schema.sql` (`BudgetItem`) | **Matches** | Dynamic budget builder grouped by category with auto-calculating totals. |
| **P6.7 Justification** | Short justification for each item by S.no. | `public/dashboard.php` (Section 5) | **Matches** | Auto-generates justification cards referencing S.no from budget table. |
| **P6.8 Self-Assessment Levels** | TRL, MRL, IRL levels 1 to 9. | `public/dashboard.php` (Section 6)<br>`config/constants.php` | **Matches** | Interactive radio/stepper scales 1–9 using policy level names. |
| **P6.9 Milestones Self-Assessment** | Track A (Prototyping, 9 items) & Track B (Entrepreneurship, 10 items) with Completed/Pending status and remarks. | `public/dashboard.php` (Section 6) | **Matches** | Full item lists rendered with status toggles and remark inputs. |
| **P6.10 Declaration** | "I hereby declare that all information provided is true and the requested grant amount will be used strictly for the stated purpose." Signature & Date. | `public/dashboard.php` (Section 8) | **Matches** | Verbatim text with digital sign-off checkbox, founder name confirmation, and date. |
| **P7. Annexure 1 (9 Headers)** | 1. Equipment (requires quotation)<br>2. Consumables<br>3. Software & Cloud<br>4. Manpower<br>5. Travel<br>6. Testing<br>7. Professional Services<br>8. Outreach<br>9. Contingency (5–10%). | `config/constants.php`<br>`public/dashboard.php`<br>`public/sec.php`<br>`public/admin.php` | **Matches** | All 9 headers with detailed examples displayed as helper text; Equipment shows quotation notice; Contingency displays 5–10% guidance. |
| **P8.1 Form A Status Levels** | Evaluated achieved level across TRL, MRL, and IRL (1–9) by each SEC member. | `public/sec.php` (Tab 2) | **Matches** | Sliders/chips for TRL 1–9, MRL 1–9, IRL 1–9 (correcting source doc typo on IRL 3). |
| **P8.2 Shared Milestones** | One shared form between members; corrections signed on print form; Status (Completed/Pending) + remarks per member. | `public/sec.php` (Tab 3)<br>`database/schema.sql` (`MilestoneAssessment`) | **Matches** | Shared milestone container with optimistic concurrency control (`updatedAt`), remarks, and edit history log displayed on printable Form A. |
| **P8.3 12 Rubric Questions** | A. Technical Soundness (1–7), B. Market Worthiness (8–10), C. Execution Readiness (11–12); Yes/No per member + Notes referring to question numbers. | `public/sec.php` (Tab 4)<br>`config/constants.php` | **Matches** | 12 Yes/No buttons grouped into 3 sections, with question-referenced notes box. |
| **P8.4–6 Form A Recommendations** | Member recommendation note, grant evaluation choice (Validation, Proof, Scaling, None/Rejected), suggested budget under 9 heads. | `public/sec.php` (Tab 5) | **Matches** | Input note, tier selector, and budget moderation grid with real-time totals. |
| **P9. Form B (Grant Report)** | Admin-filled sanction order: name, date, approved tier, approved budget (9 heads), approved timeline (8 dates), final recommendation, T&C, 5 signature blocks. | `public/admin.php`<br>`public/reports.php` | **Matches** | Captures all 8 timeline dates, 9 budget heads, override justification note, and 5 institutional signature blocks (VC, Dean, Director, Asst Director, SEC Member). |
| **P10. Terms & Conditions** | No personal accounts, institute purchases, 6-month registration & equity agreement, sale vouchers submitted to incubator. | `config/constants.php`<br>`public/admin.php`<br>`public/reports.php` | **Matches** | Verbatim policy clauses rendered in sanction order and printable reports. |
| **P11. Disbursement & Audit** | Direct vendor transfer / prepaid cards; no cash; property of ITM Gwalior; expense reports + original invoices; half-yearly reports. | `public/admin.php`<br>`public/status.php` | **Matches** | Post-sanction invoice submission workflow and Admin report aggregation view. |

---

## 2. Behavioral Evaluations ("Needs Approval")

The following behavioral rules have been analyzed and implemented with clear governance safeguards:

### a) Budget Total vs Selected Tier Range
* **Policy Rule**: Each grant tier has a prescribed range (e.g. Proof Grant: ₹25,000 to ₹50,000).
* **Behavior Implemented**: 
  - In Draft editing, a dynamic badge highlights whether the total is within range (`green`), below minimum (`amber`), or exceeds ceiling (`red`).
  - On Final Submission, if the budget exceeds the tier ceiling by >10%, submission is blocked with an advisory prompt to either adjust items or request a tier upgrade.
* **Reasoning**: Prevents accidental out-of-bounds submissions while avoiding blocking edge-case rounding during draft entry.

### b) Contingency 5% to 10% Rule
* **Policy Rule**: Annexure 1 Clause 9 states: *"Usually 5 to 10 percent of the total grant"*.
* **Behavior Implemented**: Real-time contingency percentage calculation. If contingency is between 5.0% and 10.0%, a green check is shown. If outside, a soft advisory banner is shown citing Policy P7.9, without hard-blocking submission.
* **Reasoning**: The policy states *"usually"*, indicating an operational guideline rather than a strict legal ceiling.

### c) Equipment / Capital Purchase Quotation Requirement
* **Policy Rule**: Annexure 1 Clause 1 explicitly states: *"Requires quotation"*.
* **Behavior Implemented**: If any item is added under `Equipment / Capital Purchase`, the user must provide a vendor quotation URL or upload a formal quotation document in Section 7.
* **Reasoning**: Institutional audit compliance requires written supplier quotes before capital equipment disbursal.

### d) Maximum 3 Grants per Team
* **Policy Rule**: *"Maximum 3 grants per team unless waived by the SEC"*.
* **Behavior Implemented**: Database tracks historical sanctioned grants for the team. If prior grants count >= 3, a prominent notice is shown to the applicant and SEC Form A unlocks an explicit *"Waive Maximum 3-Grant Limit"* checkbox requiring a mandatory justification remark.
* **Reasoning**: Ensures SEC deliberate review and leaves an immutable audit trail for audit compliance.

### e) Panel of Exactly 3 SEC Members per Application
* **Policy Rule**: Policy P3 and P8 describe a panel of 3 evaluators.
* **Behavior Implemented**: The Administrator assigns 3 approved SEC members to an application from the Admin console. The system also supports an open pool where the first 3 approved SEC members who start an evaluation claim the 3 seats. Form A is marked ready for Form B sanction once all 3 have submitted evaluations.
* **Reasoning**: Accommodates both pre-scheduled committee hearing rosters and flexible first-to-review workflows.

### f) Decision Guide Scoring Auto-Suggestion
* **Policy Rule**: *"Mostly YES → Recommend funding (TRL-3 likely); Multiple NO → Revision required before approval."*
* **Behavior Implemented**: On Form A Tab 4, a dynamic recommendation indicator counts the answers:
  - If `NO` count == 0: displays `"Unanimously Positive (TRL-3 strongly indicated)"`.
  - If `NO` count is 1 or 2: displays `"Mostly YES → Recommend funding (TRL-3 likely)"`.
  - If `NO` count >= 3: displays `"Multiple NO (≥3) → Revision required before approval"`.
  - This is an informative decision aid; the SEC member retains final discretion in selecting their grant evaluation choice.
* **Reasoning**: Conforms exactly to the policy decision guide while preserving expert academic judgment.

### g) Documents Checklist as Admin-Verified Pre-checks
* **Policy Rule**: SOP requires checking agreement, PAN, Aadhaar, photo, prior utilization report, and pitch deck.
* **Behavior Implemented**: In SEC Form A Tab 1 (Pre-checks), the system renders verified badges indicating whether the required files have been verified by the Administrator, alongside manual verification checkboxes for the evaluator.
* **Reasoning**: Reduces redundant paperwork inspection by evaluators while maintaining individual evaluator accountability.

### h) Post-Approval Implementation & Tracking
* **Policy Rule**: Policy P9 specifies 8 milestone dates, mid-review check, and final expense submission.
* **Behavior Implemented**: Startup `/status.php` displays a visual 8-milestone roadmap based on the approved Form B timeline dates. Startups can upload mid-review presentations and final expense reports with bill invoices.
* **Reasoning**: Fulfills the monitoring and accountability requirements of Policy P11.

### i) Half-Yearly Transparency & Audit Reports
* **Policy Rule**: Policy P11 requires IDEAPAD to publish half-yearly reports summarizing grants awarded, total disbursed amount, utilization, and impact.
* **Behavior Implemented**: Admin console includes a dedicated Half-Yearly Report summary aggregating counts by tier, total sanctioned INR, disbursement status, and CSV export.
* **Reasoning**: Fully automates institutional and external funding body audit preparation.

### j) Milestone Sets A and B Applicability
* **Policy Rule**: Policy P4.8 notes milestones differ for Problem-Solution fit vs Entrepreneurial fit.
* **Behavior Implemented**: The startup selects their primary development track (Track A: Prototyping vs Track B: Entrepreneurship) during application self-assessment. In SEC Form A, the evaluator sees the startup's selected track but can view and add remarks on both tracks.
* **Reasoning**: Startups focus on their current development phase while evaluators retain comprehensive committee oversight.

---

## 3. Data Privacy & Compliance (PAN & Aadhaar)

* **Policy Requirement**: Collection of PAN card, Aadhaar card, and passport photos of team members.
* **Implementation Protocol**:
  1. **Zero Plaintext Storage**: PAN and Aadhaar identity numbers are never stored as queryable plaintext strings in database fields.
  2. **Encrypted Storage Directory**: Uploaded files are placed in `/home/kt/Desktop/seed_fund_itm/uploads/` outside the web root (`public/`) and assigned cryptographically randomized filenames (`doc_<hash>.jpg`).
  3. **Authenticated Streaming**: Files can only be downloaded or viewed via `download.php?id=<id>` which verifies active Administrator or owning Founder session credentials.
  4. **Masked Previews**: Printable Form A and Form B display only masked verification tags (e.g. `Aadhaar: Verified [•••• 1234]`) to prevent identity exposure during committee meetings.

---

## 4. Policy Input Still Needed

Where the official policy documents are silent, the portal adopts sensible standard defaults and flags them for future committee review:

1. **Application Windows & Deadlines**: The policy does not specify whether applications run in batches/cohorts or rolling admissions. Default: **Rolling admissions** with instant draft creation.
2. **Turnaround SLA**: No maximum turnaround time from submission to SEC presentation is defined. Default: **Advisory 14-day review window**.
3. **Scoring Weights**: Beyond the Yes/No decision guide, no numerical weights or passing percentages are prescribed. Default: **Qualitative committee consensus with advisory NO counter**.
4. **SEC Tie-Breaking**: When 3 members submit conflicting grant evaluations (e.g., Validation vs Proof vs Reject). Default: **Administrator Form B reviews majority choice and can record an explicit justification note if overriding**.
