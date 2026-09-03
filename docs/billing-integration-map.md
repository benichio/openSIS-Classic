# Billing integration map

## openSIS touchpoints

- Modules are enabled from `ConfigInc.php` through `$openSISModules`.
- Enabled modules load `modules/<module>/Menu.php` from `Menu.php`.
- Existing config already enables `Billing`, so `modules/Billing/Menu.php` bridges to `modules/billing/Menu.php`.
- Menu permissions are resolved with `profile_exceptions.MODNAME`, `CAN_USE` and `CAN_EDIT`.
- Runtime pages are loaded through `Modules.php?modname=billing/<Page>.php`.
- Database access uses `DBQuery()` and `DBGet()`.

## Student data

- Students: `students.STUDENT_ID`, `FIRST_NAME`, `LAST_NAME`, `MIDDLE_NAME`.
- Enrollment and active period: `student_enrollment` with `SYEAR`, `SCHOOL_ID`, `START_DATE`, `END_DATE`.
- Related people: `people` and `students_join_people`.

## Academic services

- Groups/classes are represented by `course_periods` and student placement by `schedule`.
- O2O billing is modeled in `billing_o2o_sessions`; a future connector should import from calendar/attendance into this table.

## Billing module boundaries

- Screen files stay thin and include `modules/billing/includes/BillingBootstrap.php`.
- Billing state is stored in the new `billing_*` tables defined in `modules/billing/sql/001_billing_schema.sql`.
- Fiscal records are abstracted by `BillingFiscalAdapter`; the current implementation is `LocalFiscalRecordAdapter`.
- PDFs and VERI*FACTU transmission are intentionally prepared by schema fields and adapter boundaries, not faked.

## First iteration scope

- Module navigation.
- Installation SQL.
- Fiscal center configuration.
- Billing accounts and student assignment.
- Services, contracts, tax rules, promotions and O2O session review.
- Billing run simulation, draft generation and issuing.
- Manual payments and allocations-ready storage.
- Basic accountant CSV export.
