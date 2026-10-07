**Table Y**

**Data Consistency and Traceability Audit (Sample Size: 20 Test Records)**

Scope: 20 synthetic student evaluations submitted through the actual PHP HTTP API in an isolated MySQL database. The input column represents submitted payloads; browser interaction was not tested. All 20 records passed database and faculty report input comparisons. Overall SET was 82.00%.

| Test Case ID | Evaluation Component | Frontend Input (Submitted Payload) | Backend Storage (Database Record) | Output Generation (System Report Input) | Consistency Status |
| --- | --- | --- | --- | --- | --- |
| TC-DC-01 | Student Likert ratings Q1 to Q5 | Selected: [4, 3, 5, 4, 4] on the 1 to 5 scale | evaluation_responses.rating_value = [4.00, 3.00, 5.00, 4.00, 4.00] | Report ratings = [4.00, 3.00, 5.00, 4.00, 4.00]; all 100 rating items match | PASS<br>20/20 (100%) |
| TC-DC-02 | Single evaluation mean and percentage | Ratings: [4, 3, 5, 4, 4] | Mean calculated from saved rating_value entries; no stored overall_rating field is assumed | Mean = 4.00; equivalent = 80.00%; 20 computed results match | PASS<br>20/20 (100%) |
| TC-DC-03 | Qualitative feedback Q6 | Input: "The instructor explains lesson 1 clearly and provides useful examples." | evaluation_responses.text_value = "The instructor explains lesson 1 clearly and provides useful examples." | The faculty report input preserves the exact qualitative response | PASS<br>20/20 (100%) |
| TC-DC-04 | General comments | Input: "Please provide more practice exercises for topic 1." | evaluations.general_comments = "Please provide more practice exercises for topic 1." | The faculty report input preserves the exact general comment | PASS<br>20/20 (100%) |
| TC-DC-05 | Evaluation credibility score and status | Submitted ratings, feedback and timing metadata; 120 seconds for 6 questions | evaluations.credibility_score = 92; credibility_status = AUTO_ACCEPTED | Returned credibility snapshot = 92/100, AUTO_ACCEPTED; cross-source comparator unavailable | PASS<br>20/20 (100%) |
| TC-DC-06 | Transaction traceability | Serialized submission payload with client receipt metadata | Client and received SHA-256 match; committed evaluation and response records linked to the trace reference | The same evaluation is included in the faculty report input with matching scores, feedback and comments | PASS<br>20/20 (100%) |
| Overall | 20 test records | 20 validated submissions | 20 exact database matches | 20 exact report input matches; overall SET = 82.00% | PASS<br>100% |

The component rows show representative values from record R01 and match counts across all 20 records. Credibility PASS means the stored score and status matched the returned snapshot; it does not validate the score as a measure of truthfulness. Peer and supervisor workflows, generated report files, dashboard rendering and AI summaries were outside this sample.

**Record Verification Results**

| Record | Evaluation ID | Submitted Ratings Q1 to Q5 | Database Match | Report Input Match | Mean | Percent | Credibility |
| --- | --- | --- | --- | --- | --- | --- | --- |
| R01 | 1 | 4, 3, 5, 4, 4 | PASS | PASS | 4.00 | 80.00% | 92/100 |
| R02 | 2 | 3, 4, 4, 5, 5 | PASS | PASS | 4.20 | 84.00% | 93/100 |
| R03 | 3 | 5, 4, 3, 4, 5 | PASS | PASS | 4.20 | 84.00% | 93/100 |
| R04 | 4 | 4, 5, 4, 3, 4 | PASS | PASS | 4.00 | 80.00% | 92/100 |
| R05 | 5 | 4, 3, 5, 4, 4 | PASS | PASS | 4.00 | 80.00% | 92/100 |
| R06 | 6 | 3, 4, 4, 5, 5 | PASS | PASS | 4.20 | 84.00% | 93/100 |
| R07 | 7 | 5, 4, 3, 4, 5 | PASS | PASS | 4.20 | 84.00% | 93/100 |
| R08 | 8 | 4, 5, 4, 3, 4 | PASS | PASS | 4.00 | 80.00% | 92/100 |
| R09 | 9 | 4, 3, 5, 4, 4 | PASS | PASS | 4.00 | 80.00% | 92/100 |
| R10 | 10 | 3, 4, 4, 5, 5 | PASS | PASS | 4.20 | 84.00% | 93/100 |
| R11 | 11 | 5, 4, 3, 4, 5 | PASS | PASS | 4.20 | 84.00% | 93/100 |
| R12 | 12 | 4, 5, 4, 3, 4 | PASS | PASS | 4.00 | 80.00% | 92/100 |
| R13 | 13 | 4, 3, 5, 4, 4 | PASS | PASS | 4.00 | 80.00% | 92/100 |
| R14 | 14 | 3, 4, 4, 5, 5 | PASS | PASS | 4.20 | 84.00% | 93/100 |
| R15 | 15 | 5, 4, 3, 4, 5 | PASS | PASS | 4.20 | 84.00% | 93/100 |
| R16 | 16 | 4, 5, 4, 3, 4 | PASS | PASS | 4.00 | 80.00% | 92/100 |
| R17 | 17 | 4, 3, 5, 4, 4 | PASS | PASS | 4.00 | 80.00% | 92/100 |
| R18 | 18 | 3, 4, 4, 5, 5 | PASS | PASS | 4.20 | 84.00% | 93/100 |
| R19 | 19 | 5, 4, 3, 4, 5 | PASS | PASS | 4.20 | 84.00% | 93/100 |
| R20 | 20 | 4, 5, 4, 3, 4 | PASS | PASS | 4.00 | 80.00% | 92/100 |

All 20 records had matching client and received payload hashes and AUTO_ACCEPTED credibility status.

Verification timestamp: 2026-10-06 22:06:57 (Asia/Manila). The local test completed 202 assertions.

Evidence: table_y_20_records_evidence.json. Source implementation: api/accuracy_logger.php, api/data_accuracy_helper.php, api/accuracy_trace_formatter.php, api/faculty_report_helper.php and api/evaluation_credibility.php.
