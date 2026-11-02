# Environment and Cutover Runbook

This runbook keeps the native PHP application and Laravel rewrite isolated until cutover approval.

## Environment boundaries

| Environment | Native database | Laravel database | Write policy |
| --- | --- | --- | --- |
| Local development | Read-only reference or sanitized clone | Dedicated database, for example simkeu_laravel | Laravel may write only to its dedicated database. |
| Staging/UAT | Sanitized production clone | Dedicated staging database | Test data only; no production writes. |
| Production before cutover | Native production database | No Laravel write connection | Native application remains system of record. |
| Production after cutover | Archived/read-only backup | Dedicated Laravel production database | Laravel becomes system of record after signed approval. |

Never copy credentials into this repository. Set APP_ENV, APP_DEBUG=false, APP_KEY, database credentials, mail, queue, cache, and filesystem settings through the deployment environment.

Set NATIVE_REFERENCE_DATABASE in every environment. The application refuses to boot in production when its default database equals this native reference database.

## Backup and restore

1. Put the deployment into maintenance mode.
2. Capture a timestamped MySQL dump and checksum it.
3. Capture private member documents and uploaded assets separately.
4. Restore the dump and files to an isolated validation environment.
5. Verify row counts for users, members, savings, transactions, cash, loans, installments, penalties, SHU, and files.
6. Record the operator, timestamps, dump checksum, and validation result in the release ticket.

## Staging and UAT

1. Import a sanitized, schema-compatible copy of the native data.
2. Run the field/rule reconciliation checklist and role-based UAT scripts.
3. Validate financial totals against the native reference without creating transactions in production.
4. Obtain written business-owner approval for each module and its rollback procedure.

## Cutover

1. Freeze native financial writes at an agreed timestamp.
2. Take a final native database and file backup.
3. Run reconciliation and resolve every discrepancy.
4. Deploy Laravel with cached configuration and optimized assets.
5. Smoke-test login, each role, documents, reports, and a controlled financial workflow.
6. Announce the system-of-record switch only after the owner signs off.

## Rollback

1. Re-enable the native application against its pre-cutover snapshot.
2. Preserve Laravel logs, audit trails, and any records created after cutover for reconciliation; do not delete them.
3. Notify stakeholders and document the incident.
4. Reconcile any dual-entry window before attempting another cutover.
