# SwiftDrive Rentals — engineering case study

**Problem modeled:** A local rental operator needs to manage available vehicles, booking requests, customer accounts, staff dispatch and fleet administration.

**Implementation:** A PHP/MySQL web application with customer, operations-staff and administrator views.

## Functionality evidenced by source

- Vehicle listing, details and category/availability selection.
- Local customer registration and booking records.
- Reservation pricing and overlap functions in the shared pricing module.
- Operations views for dispatch, pickup and return inspections, and customer inquiries.
- Administrator screens for vehicle updates and customer/staff lists.
- Role-aware PHP session utilities, CSRF helpers and PDO prepared statements.

## Design decisions and trade-offs

**Central pricing helper:** Business calculations are consolidated into includes/pricing.php rather than repeated across every page. Real-time overlap calculations depend on current database rows.

**Session-based access:** PHP authorization helpers provide simple role-focused application structure, with further validation needed before production exposure.

**Relational schema:** Vehicle categories, locations, users, reservations, inspections and payment records are linked in MySQL.

**Safe demo data:** The public ZIP's original identities, inquiries, bookings, payments and reviews have been removed from publication. Only fictional example.test users and a small synthetic vehicle catalog appear in the public SQL.

## Verification

All **28 PHP files** in the sanitized publication passed PHP 8.5.2 syntax checks on the MacBook.

The checks do **not** establish full browser workflow testing, live payment settlement, fleet integrity under simultaneous bookings or secure commercial deployment.

## Areas to improve before commercial use

- Transactional locking and concurrency coverage for overlapping reservations.
- Secure role provisioning, audit logging and backup/restore rehearsals.
- Production payment integrations and reconciliation instead of demo payment flows.
- Image and branding rights confirmation, privacy documentation and realistic operational monitoring.

## Demonstration note

The example vehicle photos are project assets, not proof of a live rental fleet. The rates, registration identifiers and locations in the published database are explicitly fictional.
