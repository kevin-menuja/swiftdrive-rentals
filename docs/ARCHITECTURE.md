# SwiftDrive Rentals — technical architecture

SwiftDrive is a PHP/PDO application that models a rental agency with customer, staff and administrator workflows. It uses server-rendered pages and a relational MySQL schema, not separate services.

## Component flow

~~~mermaid
flowchart TD
    Browser["Customer / Staff / Administrator browser"] --> Pages["PHP pages and Bootstrap UI"]
    Pages --> Auth["Session authentication / role and CSRF helpers"]
    Pages --> Pricing["Vehicle availability and pricing calculations"]
    Pages --> Fleet["Fleet, bookings and inspections"]
    Pricing --> PDO["PDO database access"]
    Fleet --> PDO
    Auth --> PDO
    PDO --> DB[("MySQL: vehicles, bookings, payments, users and locations")]
~~~

## Source responsibilities

| Path | Responsibility |
| --- | --- |
| vehicles.php, vehicle.php | Catalog and individual vehicle pages |
| customer/ | Reservation flow, bookings, booking details, profile |
| staff/ | Booking dispatch, inspections and inquiry workflows |
| admin/ | Fleet/customer/staff administration |
| includes/auth.php | Session authorization, role checks and CSRF handling |
| includes/pricing.php | Availability, rental pricing and booking overlap logic |
| includes/db.php | PDO connection and error logging |
| config/config.php | Defaults and optional local private configuration |
| sql/swiftdrive_rentals.sql | MySQL schema and synthetic demo catalog |

## Conceptual reservation sequence

~~~mermaid
sequenceDiagram
    actor Customer
    participant Pages as Customer PHP pages
    participant Pricing as Pricing and availability functions
    participant DB as MySQL

    Customer->>Pages: Select vehicle and rental dates
    Pages->>Pricing: Validate range, overlap and rate
    Pricing->>DB: Read vehicle and existing bookings
    DB-->>Pricing: Availability and pricing inputs
    Pricing-->>Pages: Availability and calculated amount
    Pages->>DB: Store booking request
    Pages-->>Customer: Show booking details
~~~

This is a code-structure summary. Exact transaction isolation and booking conflict handling have not been validated under concurrent load.

## Important operational constraints

- The code includes simulated payment-facing UI; no verified external card processor or settlement service is present.
- Original customer and payment records were excluded from public SQL; new accounts and vehicles are fictional.
- Local database credential overrides belong in a Git-ignored config.local.php file or private environment variables.
- Vehicle images are included from the project ZIP; third-party licensing for commercial use should be verified.
