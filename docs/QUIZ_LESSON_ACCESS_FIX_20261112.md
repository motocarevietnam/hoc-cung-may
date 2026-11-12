# Science Quiz: lesson confirmation and database-only access

## Confirmed cause
At inspection, Science had 72 approved bank rows across seven historical school-plan topics, but no taught confirmations. The old route collapsed this into quiz_no_new_questions and offered only Retry. Quiz topic identities were also cut at 80/200 characters, while the bank supports 255.

## Change
- Before serving any quiz, verify a known, confirmed, non-future school lesson.
- Return actionable historical lesson choices and accurate empty-state reasons. Only an explicit learner click records a taught confirmation through the existing confirmation endpoint.
- Canonical topic text is retained up to 255 characters. Legacy truncated prefixes resolve only when unambiguous; no inferred semantic mappings.
- Serve 1–10 approved unused questions from MariaDB; no on-demand AI generation or QA calls in the Quiz endpoint.
- Remove the paid refill branch from r_quiz. Deployment also set quiz_auto_replenish_enabled=0. This does not disable normal companion chat.
- Keep all question approval decisions and learning history unchanged. A confirmation does not itself approve any question.

## Validation
- PHP syntax checks and Node syntax checks passed for all changed scripts.
- 19 PHP/database integration assertions passed in a rolled-back transaction: accurate Science empty state; future/unconfirmed/unknown topics blocked; explicit confirmation unlocks the matching approved bank; legacy prefix handling; 1–9 question sets; no quiz/history persistence from testing.
- Node UI tests passed: escaped labels; no automatic confirmation; explicit click confirms then starts; topic preservation; double-click guard; existing confirmations; error metadata propagation.
- Live authenticated HTTP smoke check: 409 quiz_lesson_confirmation_required, 72 approved Science rows, seven actionable choices, available counts [10,10,5,10,5,6,6]. No quiz rows created and API usage counter unchanged.
- Full browser/mobile end-to-end was not completed: the isolated browser environment blocked access to the site. Not tested on Kem's device.

## Deployment safety
Staged and syntax-tested before deployment. Each production file was compared byte-for-byte to the captured baseline to detect concurrent changes. Original files/settings retained outside public/ under /root/kem-learning-import/science_quiz_access_20261112/. No AI CLI/model invocation; no automatic question approvals; no merge.
